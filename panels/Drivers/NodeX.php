<?php

class NodeXPanelDriver extends BasePanelDriver
{
    public function create(array $vars)
    {
        $panel = $vars['Get_Data_Panel'];
        $product = $vars['Get_Data_Product'];
        $username = $vars['usernameC'];
        $invoice = $vars['invoice'];
        $request = array(
            'username' => $username,
            'telegram_id' => (string) ($vars['Data_Config']['from_id'] ?? ''),
            'note' => (string) ($vars['note'] ?? ''),
            'limit_usage' => (int) $vars['data_limit'],
            'limit_expire' => (int) $vars['expire'],
            'services' => nodex_service_ids($product, $panel)
        );
        $response = request_nodex($panel['name_panel'], 'POST', '/subscriptions', $request);
        $rows = json_decode($response['body'] ?? '', true);
        $user = is_array($rows) && isset($rows[0]) ? $rows[0] : (is_array($rows) ? $rows : array());
        if (!empty($response['error']) || (int) ($response['status'] ?? 0) >= 400 || empty($user['username'])) {
            return array('status' => 'Unsuccessful', 'msg' => nodex_error($response));
        }
        $subscriptionUrl = $user['subscription_link'] ?? '';
        return array(
            'status' => 'successful',
            'username' => $user['username'],
            'subscription_url' => $invoice ? nodeXSubscriptionUrl($invoice) : $subscriptionUrl,
            'configs' => nodex_links($username, $panel['name_panel'])
        );
    }

    public function read(array $vars)
    {
        $panel = $vars['Get_Data_Panel'];
        $username = $vars['username'];
        $response = getuser_nodex($username, $panel['name_panel']);
        if (!empty($response['error']) || (int) ($response['status'] ?? 0) >= 400 || !is_array($response['user'])) {
            return array('status' => 'Unsuccessful', 'msg' => nodex_error($response));
        }
        $user = $response['user'];
        $expiry = !empty($user['limit_expire']) ? (int) $user['limit_expire'] : (!empty($user['expired_at']) ? strtotime($user['expired_at']) : 0);
        $invoice = $vars['invoice'];
        return array(
            'status' => status_nodex($user),
            'username' => $user['username'],
            'data_limit' => (int) ($user['limit_usage'] ?? 0),
            'expire' => $expiry ?: 0,
            'online_at' => $user['last_online_at'] ?? null,
            'used_traffic' => (int) ($user['total_usage'] ?? 0),
            'links' => nodex_links($username, $panel['name_panel']),
            'subscription_url' => $invoice ? nodeXSubscriptionUrl($invoice) : ($user['subscription_link'] ?? ''),
            'sub_updated_at' => $user['updated_at'] ?? null,
            'sub_last_user_agent' => $user['last_client_agent'] ?? null,
            'uuid' => null,
            'data_limit_reset' => 'no_reset'
        );
    }

    public function revokeSub(array $vars)
    {
        global $pdo;

        $username = $vars['username'];
        $panelName = $vars['name_panel'];
        $panel = $vars['Get_Data_Panel'];
        $invoice = $vars['invoice'] ?? null;
        if ($invoice === null) {
            // Older installed reseller bots do not pass the invoice to Revoke_sub().
            $lookup = $pdo->prepare("SELECT * FROM invoice WHERE username = ? AND Service_location = ? LIMIT 1");
            $lookup->execute([$username, $panelName]);
            $invoice = $lookup->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        try {
            if ($invoice !== null) {
                $token = newNodeXSubscriptionToken($panel);
                $currentToken = $invoice['subscription_token'] ?? null;
                $pdo->beginTransaction();
                $rotate = $pdo->prepare("UPDATE invoice SET subscription_token = ?
                    WHERE id_invoice = ? AND Service_location = ? AND username = ?
                    AND (subscription_token = ? OR (subscription_token IS NULL AND ? IS NULL))");
                $rotate->execute([$token, $invoice['id_invoice'], $panelName, $username, $currentToken, $currentToken]);
                if ($rotate->rowCount() !== 1) {
                    $pdo->rollBack();
                    return ['status' => 'Unsuccessful', 'msg' => 'Subscription link has already changed.'];
                }
            }

            $response = request_nodex($panelName, 'POST', '/subscriptions/revoke', ['usernames' => [$username]]);
            $status = (int) ($response['status'] ?? 0);
            if (!empty($response['error']) || $status < 200 || $status >= 300) {
                if ($invoice !== null) {
                    $pdo->rollBack();
                }
                return ['status' => 'Unsuccessful', 'msg' => nodex_error($response)];
            }
            if ($invoice !== null) {
                $pdo->commit();
                $invoice['subscription_token'] = $token;
                clearSelectCache('invoice');
            }
        } catch (Throwable $e) {
            if ($invoice !== null && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('NodeX subscription link rotation failed.');
            return ['status' => 'Unsuccessful', 'msg' => 'Could not rotate the NodeX subscription link.'];
        }

        if ($invoice !== null && ($panel['subvip'] ?? '') === 'onsubvip') {
            $subscriptionUrl = nodeXSubscriptionUrl($invoice);
        } else {
            $user = getuser_nodex($username, $panelName);
            $subscriptionUrl = $user['user']['subscription_link'] ?? '';
        }
        return array(
            'status' => 'successful',
            'configs' => nodex_links($username, $panelName),
            'subscription_url' => $subscriptionUrl
        );
    }

    public function remove(array $vars)
    {
        $panel = $vars['Get_Data_Panel'];
        $username = $vars['username'];
        $response = request_nodex($panel['name_panel'], 'DELETE', '/subscriptions', array('usernames' => array($username)));
        if (!empty($response['error']) || (int) ($response['status'] ?? 0) >= 400) {
            return array('status' => 'Unsuccessful', 'msg' => nodex_error($response));
        }
        return array('status' => 'successful', 'username' => $username);
    }

    public function modify(array $vars)
    {
        $name = $vars['name_panel'];
        $username = $vars['username'];
        $input = $vars['config'];
        $data = array('usernames' => array($username));
        $map = array('data_limit' => 'limit_usage', 'expire' => 'limit_expire', 'status' => 'enabled');
        foreach ($map as $source => $target) {
            if (array_key_exists($source, $input)) {
                $data[$target] = $source == 'status' ? $input[$source] == 'active' : (int) $input[$source];
            }
        }
        foreach (array('enabled', 'limit_usage', 'limit_expire', 'services', 'note', 'email', 'phone', 'telegram_id') as $key) {
            if (array_key_exists($key, $input)) {
                $data[$key] = $input[$key];
            }
        }
        if (count($data) === 1) {
            return array('status' => true, 'data' => null);
        }
        $response = request_nodex($name, 'PUT', '/subscriptions', $data);
        if (!empty($response['error']) || (int) ($response['status'] ?? 0) >= 400) {
            return array('status' => false, 'msg' => nodex_error($response));
        }
        return array('status' => true, 'data' => json_decode($response['body'] ?? '', true));
    }

    public function changeStatus(array $vars)
    {
        $username = $vars['username'];
        $enabled = $vars['DataUserOut']['status'] != 'active';
        $response = request_nodex($vars['name_panel'], 'POST', '/subscriptions/' . ($enabled ? 'enable' : 'disable'), array('usernames' => array($username)));
        if (!empty($response['error']) || (int) ($response['status'] ?? 0) >= 400) {
            return array('status' => 'Unsuccessful', 'msg' => nodex_error($response));
        }
        return array('status' => 'successful', 'msg' => null);
    }

    public function resetUsage(array $vars)
    {
        $username = $vars['username'];
        $response = request_nodex($vars['panel']['name_panel'], 'POST', '/subscriptions/reset', array('usernames' => array($username)));
        if (!empty($response['error']) || (int) ($response['status'] ?? 0) >= 400) {
            return array('status' => false, 'msg' => nodex_error($response));
        }
        return array('status' => true, 'msg' => 'successful');
    }

    public function extendService(array $vars)
    {
        return $this->updateLimits($vars['panel']['name_panel'], $vars['username'], $vars['data_limit_new'], $vars['time_new']);
    }

    public function extraVolume(array $vars)
    {
        $user = $this->DataUser($vars['panel']['name_panel'], $vars['username_account']);
        if (($user['status'] ?? '') === 'Unsuccessful') {
            return array('status' => false, 'msg' => $user['msg'] ?? 'Unable to read subscription');
        }
        return $this->updateLimits($vars['panel']['name_panel'], $vars['username_account'], $vars['new_limit'], $user['expire']);
    }

    public function extraTime(array $vars)
    {
        $user = $this->DataUser($vars['panel']['name_panel'], $vars['username_account']);
        if (($user['status'] ?? '') === 'Unsuccessful') {
            return array('status' => false, 'msg' => $user['msg'] ?? 'Unable to read subscription');
        }
        return $this->updateLimits($vars['panel']['name_panel'], $vars['username_account'], $user['data_limit'], $vars['new_limit']);
    }

    private function updateLimits($panelName, $username, $dataLimit, $expiry)
    {
        $response = request_nodex($panelName, 'PUT', '/subscriptions', array(
            'usernames' => array($username),
            'limit_usage' => (int) $dataLimit,
            'limit_expire' => (int) $expiry,
            'enabled' => true
        ));
        if (!empty($response['error']) || (int) ($response['status'] ?? 0) >= 400) {
            return array('status' => false, 'msg' => nodex_error($response));
        }
        return array('status' => true, 'msg' => 'successful');
    }
}
