<?php
chdir(__DIR__);
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
$ManagePanel = new ManagePanel();
$textbotlang = languagechange();
$testServiceName = $textbotlang['common']['labels']['testServiceName'];
$deleteExpiredTests = $pdo->prepare("DELETE i FROM invoice i INNER JOIN marzban_panel p ON p.name_panel = i.Service_location WHERE i.name_product = :test_name AND i.test_ended_at IS NOT NULL AND CAST(i.test_ended_at AS UNSIGNED) <= :delete_before AND LOWER(p.type) IN ('nodex', 'guard', 'solidlayer')");
$deleteExpiredTests->execute([
    ':test_name' => $testServiceName,
    ':delete_before' => time() - 72 * 60 * 60,
]);

$stmt = $pdo->prepare("SELECT * FROM invoice WHERE status != 'disabled' AND name_product = :test_name ORDER BY RAND() LIMIT 15");
$stmt->execute([':test_name' => $testServiceName]);
while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $username = trim($result['username']);
    $panel = select("marzban_panel", "*", "name_panel", $result['Service_location'], "select");
    if ($panel == false) {
        continue;
    }
    $user = select("user", "*", "id", $result['id_user'], "select");
    $panelUser = $ManagePanel->DataUser($result['Service_location'], $result['username']);
    if (in_array($panelUser['status'] ?? 'Unsuccessful', ['active', 'on_hold', 'Unsuccessful', 'disabled'], true)) {
        continue;
    }

    $isNodeX = in_array(strtolower((string) ($panel['type'] ?? '')), ['nodex', 'guard', 'solidlayer'], true);
    if ($isNodeX) {
        $markEnded = $pdo->prepare("UPDATE invoice SET Status = 'disabled', test_ended_at = :ended_at WHERE id_invoice = :id_invoice AND (test_ended_at IS NULL OR test_ended_at = '')");
        $markEnded->execute([':ended_at' => time(), ':id_invoice' => $result['id_invoice']]);
        if ($markEnded->rowCount() === 0) {
            continue;
        }
    } else {
        $ManagePanel->RemoveUser($result['Service_location'], $username);
        update("invoice", "status", "disabled", "id_invoice", $result['id_invoice']);
    }

    if ((int) ($user['status_cron'] ?? 0) !== 0) {
        $response = json_encode([
            'inline_keyboard' => [[
                ['text' => $textbotlang['keyboard']['buyService'], 'callback_data' => 'buy'],
            ]],
        ]);
        $expiredMessage = str_replace('{username}', $username, $textbotlang['textbot']['testExpired']);
        sendmessage($result['id_user'], $expiredMessage, $response, 'HTML');
    }
}
