<?php

function request_guard($location, $method, $path, $body = null)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    if (!$panel || empty($panel['url_panel']) || empty($panel['password_panel'])) {
        return array('status' => null, 'body' => null, 'error' => 'Guard URL or API key is missing');
    }

    $ch = curl_init(rtrim($panel['url_panel'], '/') . '/api' . $path);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => ($GLOBALS['request_exec_timeout'] ?? null) ?: 30000,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'Content-Type: application/json',
            'X-API-Key: ' . $panel['password_panel']
        )
    ));
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return array('status' => null, 'body' => null, 'error' => $error);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('status' => $status, 'body' => $response);
}

function getuser_guard($username, $location)
{
    $response = request_guard($location, 'GET', '/subscriptions?limit=100&usernames=' . rawurlencode($username));
    $response['user'] = null;
    $data = json_decode($response['body'] ?? '', true);
    foreach (($data['items'] ?? array()) as $item) {
        if (isset($item['username']) && strcasecmp($item['username'], $username) === 0) {
            $response['user'] = $item;
            break;
        }
    }
    if ($response['user'] === null && empty($response['error']) && (int) ($response['status'] ?? 0) < 400) {
        $response['status'] = 404;
        $response['body'] = json_encode(array('message' => 'Subscription not found'));
    }
    return $response;
}

function guard_error($response)
{
    if (!empty($response['error'])) {
        return $response['error'];
    }
    $data = json_decode($response['body'] ?? '', true);
    if (!empty($data['message'])) {
        return $data['message'];
    }
    if (!empty($data['errors'][0]['message'])) {
        return $data['errors'][0]['message'];
    }
    return 'error code : ' . ($response['status'] ?? 'unknown');
}

function guard_links($username, $location)
{
    $response = request_guard($location, 'GET', '/subscriptions/' . rawurlencode($username) . '/links');
    if (!empty($response['error']) || (int) ($response['status'] ?? 0) >= 400) {
        return array();
    }
    $data = json_decode($response['body'] ?? '', true);
    return array_values(array_filter(array_map(function ($item) {
        return trim((string) ($item['link'] ?? ''));
    }, $data['links'] ?? array())));
}

function guard_service_ids($product, $panel)
{
    $raw = !empty($product['inbounds']) ? $product['inbounds'] : ($panel['proxies'] ?? '');
    $value = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($value)) {
        $value = array($value);
    }
    if (isset($value['service_id'])) {
        $value = array($value['service_id']);
    } elseif (isset($value['service_ids'])) {
        $value = $value['service_ids'];
    }
    return array_values(array_unique(array_filter(array_map('intval', $value), function ($id) {
        return $id > 0;
    })));
}

function status_guard(array $user)
{
    if (empty($user['enabled'])) {
        return 'disabled';
    }
    $expiry = !empty($user['limit_expire']) ? (int) $user['limit_expire'] : (!empty($user['expired_at']) ? strtotime($user['expired_at']) : 0);
    if ($expiry > 0 && $expiry < time()) {
        return 'expired';
    }
    $limit = (int) ($user['limit_usage'] ?? 0);
    if ($limit > 0 && (int) ($user['total_usage'] ?? 0) >= $limit) {
        return 'limited';
    }
    if (array_key_exists('activated', $user) && !$user['activated']) {
        return 'on_hold';
    }
    return 'active';
}

