<?php
ini_set('error_log', 'error_log');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../panels/Api/Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
$ManagePanel = new ManagePanel();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: private, no-store');
$url = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$parts = explode("/sub/", (string) $url);
$token = trim($parts[1] ?? '');
$isNodeXToken = preg_match('/\Anx_[a-f0-9]{32}\z/', $token) === 1;
if (!$isNodeXToken && !preg_match('/\A[a-f0-9]{4,64}\z/i', $token)) {
    echo "ERROR!";
    exit;
}
try {
    $nameloc = $isNodeXToken
        ? select("invoice", "*", "subscription_token", substr($token, 3), "select")
        : select("invoice", "*", "id_invoice", $token, "select");
    if (!is_array($nameloc)
        || (!$isNodeXToken && isset($nameloc['subscription_token']))
        || !in_array($nameloc['Status'], ['active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold', 'archivedbyuser'], true)) {
        http_response_code(404);
        echo "ERROR!";
        exit;
    }
    $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $nameloc['username'], $nameloc);
    $config = "";
    if (is_array($DataUserOut) && isset($DataUserOut['links']) && is_array($DataUserOut['links'])) {
        foreach ($DataUserOut['links'] as $Links) {
            $config .= $Links . "\r\r";
        }
    }
    echo $config;
} catch (Throwable $e) {
    error_log('sub endpoint error: ' . $e->getMessage());
    echo "Error!";
}
