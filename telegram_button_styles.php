<?php
function semanticTelegramButtonStyle(array $button)
{
    if (isset($button['style'])) {
        return null;
    }
    $action = (string) ($button['callback_data'] ?? '') . ' ' . (string) ($button['text'] ?? '');
    $action = function_exists('mb_strtolower') ? mb_strtolower($action, 'UTF-8') : strtolower($action);
    $deleteTerms = ['delete', 'remove', 'revoke', 'trash', 'حذف', 'удал', 'Удал', '删除'];
    foreach ($deleteTerms as $term) {
        if (strpos($action, $term) !== false) {
            return 'danger';
        }
    }
    $confirmTerms = ['confirm', 'approve', 'accept', 'yes', 'startaction', 'confirmpaid', 'تایید', 'تأیید', 'بله', 'подтверд', 'да', '确认', '确定'];
    foreach ($confirmTerms as $term) {
        if (strpos($action, $term) !== false) {
            return 'success';
        }
    }
    $navigationTerms = ['back', 'menu', 'home', 'previous', 'next', 'return', 'navigation', 'admin', 'start', 'بازگشت', 'برگشت', 'منو', 'خانه', 'назад', 'Назад', 'меню', 'Меню', '返回', '菜单'];
    foreach ($navigationTerms as $term) {
        if (strpos($action, $term) !== false) {
            return 'primary';
        }
    }
    return null;
}

function applySemanticTelegramButtonStyles($replyMarkup)
{
    $wasJson = is_string($replyMarkup);
    $markup = $wasJson ? json_decode($replyMarkup, true) : $replyMarkup;
    if (!is_array($markup)) {
        return $replyMarkup;
    }
    $changed = false;
    foreach (['keyboard', 'inline_keyboard'] as $markupKey) {
        if (!isset($markup[$markupKey]) || !is_array($markup[$markupKey])) {
            continue;
        }
        foreach ($markup[$markupKey] as $rowKey => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $buttonKey => $button) {
                if (is_string($button) && $markupKey === 'keyboard') {
                    $button = ['text' => $button];
                }
                if (!is_array($button) || !isset($button['text'])) {
                    continue;
                }
                $style = semanticTelegramButtonStyle($button);
                if ($style !== null) {
                    $button['style'] = $style;
                    $markup[$markupKey][$rowKey][$buttonKey] = $button;
                    $changed = true;
                }
            }
        }
    }
    if (!$changed) {
        return $replyMarkup;
    }
    if (!$wasJson) {
        return $markup;
    }
    $encoded = json_encode($markup, JSON_UNESCAPED_UNICODE);
    return $encoded === false ? $replyMarkup : $encoded;
}
