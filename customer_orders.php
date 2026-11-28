<?php

function customerOrderRows(PDO $pdo, string $owner, string $testName, int $page, ?int $paymentId = null): array
{
    // Gateway and service timestamps are stored in Tehran local time; invoice timestamps are Unix seconds.
    $paymentSource = "SELECT 'payment' AS source, CAST(id AS CHAR) AS reference,
            TIMESTAMPDIFF(SECOND, '1970-01-01 03:30:00', time) AS created
        FROM payment_report
        WHERE id_user = :payment_owner AND COALESCE(bottype, '') = ''
          AND COALESCE(Payment_Method, '') NOT IN ('', 'add balance by admin', 'low balance by admin')";
    $params = ['payment_owner' => $owner, 'display_owner' => $owner];
    if ($paymentId !== null) {
        $references = $paymentSource . ' AND id = :payment_id';
        $params['payment_id'] = $paymentId;
    } else {
        $references = "$paymentSource
            UNION ALL
            SELECT 'invoice', purchase.id_invoice, CAST(purchase.time_sell AS SIGNED)
            FROM (
                SELECT id_invoice FROM invoice WHERE id_user = :invoice_owner
                UNION
                SELECT transferred.id_invoice FROM service_other transfer
                JOIN invoice transferred ON transferred.username = transfer.username
                WHERE transfer.id_user = :former_owner AND transfer.type = 'transfertouser'
            ) AS candidates
            JOIN invoice purchase ON purchase.id_invoice = candidates.id_invoice
            WHERE COALESCE((
                  SELECT transfer.id_user FROM service_other transfer
                  WHERE transfer.username = purchase.username AND transfer.type = 'transfertouser'
                  ORDER BY transfer.id LIMIT 1
              ), purchase.id_user) = :invoice_payer
              AND COALESCE(purchase.id_payment, '') IN ('', 'none')
              AND purchase.name_product NOT IN (:test_name, 'usertest') AND purchase.price_product > 0
              AND LOWER(purchase.Status) NOT IN ('unpaid', 'unsuccessful')
            UNION ALL
            SELECT 'service', CAST(id AS CHAR), TIMESTAMPDIFF(SECOND, '1970-01-01 03:30:00', time)
            FROM service_other
            WHERE id_user = :service_owner AND COALESCE(id_payment, '') IN ('', 'none')
              AND type IN ('extend_user', 'extra_user', 'extra_time_user')
              AND (status = 'paid' OR (type <> 'extend_user' AND status = '' AND output <> ''))";
        $params += [
            'invoice_owner' => $owner, 'former_owner' => $owner, 'invoice_payer' => $owner,
            'service_owner' => $owner, 'test_name' => $testName,
        ];
    }

    $statement = $pdo->prepare("SELECT refs.source, refs.created, p.id_order, p.Payment_Method, p.payment_Status,
            p.price AS payment_price, p.gross_amount, p.fee_amount, p.order_type, p.fulfillment_status,
            p.id_invoice AS legacy_context, i.id_invoice, i.price_product, i.Status AS invoice_status,
            s.id AS service_id, s.type AS service_type, s.status AS service_status, s.price AS service_price,
            s.payable_amount, COALESCE(CASE WHEN i.id_user = :display_owner THEN i.username END, s.username) AS service_name
        FROM (SELECT * FROM ($references) AS orders
              ORDER BY created DESC, source, reference DESC LIMIT :size OFFSET :offset) AS refs
        LEFT JOIN payment_report p ON refs.source = 'payment' AND p.id = refs.reference
        LEFT JOIN invoice i ON i.id_invoice = CASE WHEN refs.source = 'invoice' THEN refs.reference ELSE (
            SELECT related.id_invoice FROM invoice related
            WHERE related.id_payment = p.id_order AND related.id_user = p.id_user
            ORDER BY related.time_sell DESC, related.id_invoice DESC LIMIT 1
        ) END
        LEFT JOIN service_other s ON s.id = CASE WHEN refs.source = 'service' THEN refs.reference ELSE (
            SELECT related.id FROM service_other related
            WHERE related.id_payment = p.id_order AND related.id_user = p.id_user
            ORDER BY related.id DESC LIMIT 1
        ) END
        ORDER BY refs.created DESC, refs.source, refs.reference DESC");
    foreach ($params as $name => $value) {
        $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->bindValue(':size', $paymentId === null ? 6 : 1, PDO::PARAM_INT);
    $statement->bindValue(':offset', $paymentId === null ? ($page - 1) * 5 : 0, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function trackedPaymentType(array $order): string
{
    if (!empty($order['order_type'])) {
        return $order['order_type'];
    }
    if ($order['id_invoice'] !== null) {
        return 'buy';
    }
    if ($order['service_type'] !== null) {
        return $order['service_type'];
    }
    $context = (string) $order['legacy_context'];
    $legacyStep = json_decode($context, true)['step'] ?? explode('|', $context, 2)[0];
    return match ($legacyStep) {
        'get_step_payment_buy', 'getconfigafterpay' => 'buy',
        'getextenduser' => 'extend_user',
        'getextravolumeuser' => 'extra_user',
        'getextratimeuser' => 'extra_time_user',
        'get_step_payment_service' => 'service',
        'get_step_payment' => 'topup',
        default => 'payment',
    };
}

function recordedPaymentAmount($base, $fee, $gross): float
{
    return (float) $gross > 0 ? (float) $gross : (float) $base + (float) $fee;
}

function customerOrderText(array $order, array $labels): string
{
    $esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $recordedAmount = false;
    if ($order['source'] === 'payment') {
        $type = trackedPaymentType($order);
        $code = $order['id_order'];
        $amount = recordedPaymentAmount($order['payment_price'], $order['fee_amount'], $order['gross_amount']);
        $method = match ($order['Payment_Method']) {
            'DigiPay credit' => $labels['digipay'],
            'cart to cart' => $labels['card'],
            default => $order['Payment_Method'],
        };
        $state = match (strtolower((string) $order['payment_Status'])) {
            'unpaid' => 'awaiting',
            'waiting' => 'receiptReview',
            'paid' => 'approved',
            'expire' => 'expired',
            'reject' => 'rejected',
            default => 'unknown',
        };
        $delivery = null;
        if ($state === 'approved') {
            $delivery = match ($order['fulfillment_status']) {
                'fulfilled' => $type . 'Ready',
                'wallet_refunded' => 'walletRefunded',
                'wallet_credited' => 'walletCredited',
                'pending' => 'fulfillmentPending',
                // For older rows infer delivery only when the linked record supports it.
                default => match (true) {
                    $order['service_status'] === 'paid' => $type . 'Ready',
                    $order['service_status'] === 'failed' => 'fulfillmentFailed',
                    in_array($order['invoice_status'], [
                        'active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold', 'archivedbyuser', 'disablebyadmin',
                    ], true) => 'buyReady',
                    default => null,
                },
            };
        }
    } else {
        $purchase = $order['source'] === 'invoice';
        $type = $purchase ? 'buy' : $order['service_type'];
        $code = $purchase ? $order['id_invoice'] : 'S-' . $order['service_id'];
        $amount = $purchase ? $order['price_product'] : ($order['payable_amount'] ?? $order['service_price']);
        $recordedAmount = !$purchase && $type === 'extend_user' && $order['payable_amount'] === null;
        $method = $labels['wallet'];
        $state = 'paidWallet';
        $delivery = $type . 'Ready';
    }

    $lines = [
        '🔹 <b>' . ($labels[$type] ?? $labels['payment']) . '</b> · ' . $esc($method),
        sprintf($labels['tracking'], $esc($code)),
        sprintf($labels[$recordedAmount ? 'recordedAmount' : 'amount'], number_format((float) $amount)),
        $labels[$state],
    ];
    if ($delivery !== null && isset($labels[$delivery])) {
        $lines[] = $labels[$delivery];
    }
    if ($order['service_name'] !== null && $order['service_name'] !== '') {
        $lines[] = sprintf($labels['serviceName'], $esc($order['service_name']));
    }
    return implode("\n", $lines);
}

function customerOrderReceiptKeyboard(array $labels, int $paymentId): string
{
    return json_encode(['inline_keyboard' => [[
        ['text' => $labels['button'], 'callback_data' => 'trackpay:' . $paymentId, 'style' => 'primary'],
    ]]]);
}

function saveCustomerPaymentReceipt(PDO $pdo, array $payment, string $owner, ?string $receiptReference = null, ?string $receiptFileId = null): bool
{
    $statement = $pdo->prepare("UPDATE payment_report SET payment_Status = 'waiting', at_updated = ?,
            dec_not_confirmed = COALESCE(?, dec_not_confirmed), receipt_file_id = COALESCE(?, receipt_file_id)
        WHERE id = ? AND id_user = ? AND Payment_Method = ? AND payment_Status IN ('Unpaid', 'waiting')");
    $statement->execute([date('Y/m/d H:i:s'), $receiptReference, $receiptFileId, $payment['id'], $owner, $payment['Payment_Method']]);
    clearSelectCache('payment_report');
    return $statement->rowCount() === 1;
}

function showCustomerOrders(PDO $pdo, string $owner, int $messageId, array $messages, int $page, ?int $paymentId): void
{
    $labels = $messages['users']['orderTracking'];
    $orders = customerOrderRows($pdo, $owner, $messages['common']['labels']['testServiceName'], $page, $paymentId);
    $hasNext = count($orders) > 5;
    $text = $labels['title'];
    $rows = [];
    if ($orders === []) {
        $text .= "\n\n" . ($paymentId === null ? $labels['empty'] : $labels['unavailable']);
    } else {
        foreach (array_slice($orders, 0, 5) as $order) {
            $text .= "\n\n" . customerOrderText($order, $labels);
        }
    }
    if ($paymentId !== null) {
        if ($orders !== []) {
            $rows[] = [['text' => $labels['refresh'], 'callback_data' => 'trackpay:' . $paymentId, 'style' => 'primary']];
        }
        $rows[] = [['text' => $labels['button'], 'callback_data' => 'trackorders:1']];
    } else {
        $pageButtons = [];
        if ($page > 1) {
            $pageButtons[] = ['text' => $messages['users']['page']['previous'], 'callback_data' => 'trackorders:' . ($page - 1)];
        }
        if ($hasNext) {
            $pageButtons[] = ['text' => $messages['users']['page']['next'], 'callback_data' => 'trackorders:' . ($page + 1)];
        }
        if ($pageButtons !== []) {
            $rows[] = $pageButtons;
        }
    }
    $rows[] = [['text' => $messages['textbot']['accountWallet'], 'callback_data' => 'account']];
    Editmessagetext($owner, $messageId, $text, json_encode(['inline_keyboard' => $rows]), 'HTML');
}
