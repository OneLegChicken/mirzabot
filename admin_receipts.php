<?php

require_once __DIR__ . '/customer_orders.php';

const PENDING_RECEIPT_FILTER = "p.Payment_Method IN ('cart to cart', 'DigiPay credit') AND p.payment_Status = 'waiting'";
const ADMIN_RECEIPT_PAGE_SIZE = 5;

function adminReceiptRows(PDO $pdo, int $page, ?int $receiptId = null): array
{
    $filter = PENDING_RECEIPT_FILTER;
    if ($receiptId !== null) {
        $filter .= ' AND p.id = :receipt_id';
    }
    $received = "COALESCE(NULLIF(p.at_updated, ''), NULLIF(p.time, ''))";
    $stmt = $pdo->prepare("SELECT p.id, p.id_order, p.id_user, p.Payment_Method, p.price, p.fee_amount,
            p.gross_amount, p.order_type, p.receipt_file_id,
            p.id_invoice AS legacy_context,
            (SELECT i.id_invoice FROM invoice i WHERE i.id_payment = p.id_order AND i.id_user = p.id_user LIMIT 1) AS linked_invoice,
            (SELECT s.type FROM service_other s WHERE s.id_payment = p.id_order AND s.id_user = p.id_user ORDER BY s.id DESC LIMIT 1) AS service_type,
            $received AS received_at
        FROM payment_report p WHERE $filter
        ORDER BY REPLACE($received, '/', '-') ASC, p.id ASC LIMIT :size OFFSET :offset");
    if ($receiptId !== null) {
        $stmt->bindValue(':receipt_id', $receiptId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':size', $receiptId === null ? ADMIN_RECEIPT_PAGE_SIZE : 1, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $receiptId === null ? ($page - 1) * ADMIN_RECEIPT_PAGE_SIZE : 0, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function adminReceiptWait(?string $receivedAt, array $labels, int $now): string
{
    $received = $receivedAt === null ? false : strtotime($receivedAt);
    if ($received === false) {
        return $labels['unknownWait'];
    }
    $minutes = intdiv(max(0, $now - $received), 60);
    if ($minutes === 0) {
        return $labels['justReceived'];
    }
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
    $parts = [];
    if ($days > 0) {
        $parts[] = sprintf($labels['days'], $days);
    }
    if ($hours > 0) {
        $parts[] = sprintf($labels['hours'], $hours);
    }
    if ($days === 0 && $minutes % 60 > 0) {
        $parts[] = sprintf($labels['minutes'], $minutes % 60);
    }
    return implode(' ', $parts);
}

function adminReceiptText(array $receipt, array $labels, array $orderLabels, int $now): string
{
    // payment_report.id_invoice is legacy context, while trackedPaymentType expects the linked invoice ID.
    $receipt['id_invoice'] = $receipt['linked_invoice'];
    $type = trackedPaymentType($receipt);
    $isDigipay = $receipt['Payment_Method'] === 'DigiPay credit';
    $method = $orderLabels[$isDigipay ? 'digipay' : 'card'];
    $amount = recordedPaymentAmount($receipt['price'], $receipt['fee_amount'], $receipt['gross_amount']);
    $code = htmlspecialchars((string) $receipt['id_order'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '🔹 <code>' . $code . '</code> · <b>' . $method . '</b>'
        . "\n" . ($orderLabels[$type] ?? $orderLabels['payment']) . ' · ' . sprintf($orderLabels['amount'], number_format($amount))
        . "\n" . sprintf($labels['waiting'], adminReceiptWait($receipt['received_at'], $labels, $now));
}

function adminReceiptQueueView(PDO $pdo, int $page, array $messages): array
{
    $total = (int) $pdo->query('SELECT COUNT(*) FROM payment_report p WHERE ' . PENDING_RECEIPT_FILTER)->fetchColumn();
    $lastPage = max(1, (int) ceil($total / ADMIN_RECEIPT_PAGE_SIZE));
    $page = min(max(1, $page), $lastPage);
    $labels = $messages['Admin']['receiptQueue'];
    $orderLabels = $messages['users']['orderTracking'];
    $text = sprintf($labels['title'], number_format($total));
    $rows = [];
    if ($total === 0) {
        $text .= "\n\n" . $labels['empty'];
    } else {
        $now = time();
        foreach (adminReceiptRows($pdo, $page) as $receipt) {
            $text .= "\n\n" . adminReceiptText($receipt, $labels, $orderLabels, $now);
            $rows[] = [[
                'text' => sprintf($labels['open'], $receipt['id_order']),
                'callback_data' => 'receiptopen:' . $receipt['id'],
            ]];
        }
        $pager = [];
        if ($page > 1) {
            $pager[] = ['text' => $messages['users']['page']['previous'], 'callback_data' => 'receiptqueue:' . ($page - 1)];
        }
        if ($page < $lastPage) {
            $pager[] = ['text' => $messages['users']['page']['next'], 'callback_data' => 'receiptqueue:' . ($page + 1)];
        }
        if ($pager !== []) {
            $rows[] = $pager;
            $text .= "\n\n" . sprintf($labels['page'], $page, $lastPage);
        }
        $rows[] = [['text' => $labels['bulkButton'], 'callback_data' => 'receiptbulk', 'style' => 'danger']];
    }
    $rows[] = [['text' => $messages['Admin']['backAdminBtn'], 'callback_data' => 'admin']];
    return [$text, json_encode(['inline_keyboard' => $rows])];
}

function openAdminReceipt(PDO $pdo, int $receiptId, string $adminId, array $messages): void
{
    $labels = $messages['Admin']['receiptQueue'];
    $receipt = adminReceiptRows($pdo, 1, $receiptId)[0] ?? null;
    if ($receipt === null) {
        sendmessage($adminId, $labels['unavailable'], null, 'HTML');
        return;
    }
    $text = adminReceiptText($receipt, $labels, $messages['users']['orderTracking'], time())
        . "\n" . sprintf($labels['customer'], htmlspecialchars((string) $receipt['id_user'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $photo = trim((string) $receipt['receipt_file_id']);
    $image = $photo !== '' ? telegram('sendphoto', ['chat_id' => $adminId, 'photo' => $photo]) : null;
    if (empty($image['ok'])) {
        $text .= "\n\n" . $labels['photoUnavailable'];
    }
    $order = (string) $receipt['id_order'];
    $keyboard = null;
    if (preg_match('/\A\w{1,52}\z/', $order)) {
        $keyboard = json_encode(['inline_keyboard' => [[
            ['text' => $receipt['Payment_Method'] === 'DigiPay credit' ? $labels['reviewDigipay'] : $messages['users']['Balance']['confirmPaying'],
                'callback_data' => 'Confirm_pay_' . $order, 'style' => 'success'],
            ['text' => $messages['users']['Balance']['rejectPay'], 'callback_data' => 'reject_pay_' . $order, 'style' => 'danger'],
        ]]]);
    }
    sendmessage($adminId, $text, $keyboard, 'HTML');
}

function pendingReceiptSnapshot(PDO $pdo, bool $lock = false): array
{
    $rows = $pdo->query('SELECT p.id, p.at_updated, p.receipt_file_id FROM payment_report p WHERE '
        . PENDING_RECEIPT_FILTER . ' ORDER BY p.id' . ($lock ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_NUM);
    return [array_column($rows, 0), substr(hash('sha256', serialize($rows)), 0, 24)];
}

function adminReceiptBulkPreview(PDO $pdo, array $labels, string $notice = ''): array
{
    [$ids, $fingerprint] = pendingReceiptSnapshot($pdo);
    $rows = [];
    if ($ids === []) {
        $text = $labels['empty'];
    } else {
        $text = sprintf($labels['bulkPreview'], number_format(count($ids)));
        $rows[] = [['text' => $labels['bulkConfirm'], 'callback_data' => "receiptbulk:$fingerprint", 'style' => 'danger']];
    }
    $rows[] = [['text' => $labels['backToQueue'], 'callback_data' => 'receiptqueue:1']];
    return [$notice === '' ? $text : "$notice\n\n$text", json_encode(['inline_keyboard' => $rows])];
}

function rejectAdminReceiptSnapshot(PDO $pdo, string $fingerprint): ?int
{
    $pdo->beginTransaction();
    try {
        [$ids, $currentFingerprint] = pendingReceiptSnapshot($pdo, true);
        if ($ids === [] || !hash_equals($currentFingerprint, $fingerprint)) {
            $pdo->rollBack();
            return null;
        }
        $total = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("UPDATE payment_report SET payment_Status = 'reject'
                WHERE id IN ($placeholders) AND payment_Status = 'waiting'");
            $stmt->execute($chunk);
            $total += $stmt->rowCount();
        }
        $pdo->commit();
        clearSelectCache('payment_report');
        return $total;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
