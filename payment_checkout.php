<?php

function digipayCheckoutEnabled(): bool
{
    $qrFileId = trim((string) getPaySettingValue('digipaycredit_qr_file_id', '0'));
    return getPaySettingValue('digipaycredit_status', 'off') === 'on' && $qrFileId !== '' && $qrFileId !== '0';
}

function digipayCheckoutQuote(int $amount): array
{
    $percent = max(0, min(100, (float) getPaySettingValue('digipaycredit_fee_percent', '0')));
    $fee = (int) ceil($amount * $percent / 100);
    $gross = $amount + $fee;
    $minimum = (int) getPaySettingValue('minbalancedigipaycredit', '20000');
    $maximum = (int) getPaySettingValue('maxbalancedigipaycredit', '1000000');
    return [
        'fee' => $fee,
        'gross' => $gross,
        'minimum' => $minimum,
        'maximum' => $maximum,
        'eligible' => $amount > 0 && $gross >= $minimum && $gross <= $maximum,
    ];
}

function digipayCheckoutAmount(array $labels, array $quote): string
{
    return sprintf($labels['digipayFee'], number_format($quote['fee']))
        . "\n" . sprintf($labels['digipayTotal'], number_format($quote['gross']));
}

function paymentCheckoutView(array $labels, string $intro, string $keyboard, float $remaining, ?float $total = null): array
{
    $text = $intro . "\n\n";
    if ($total !== null) {
        $wallet = max(0, min($total, $total - $remaining));
        $text .= sprintf($labels['orderTotal'], number_format($total)) . "\n"
            . sprintf($labels['walletContribution'], number_format($wallet)) . "\n";
        if ($remaining > $total) {
            $text .= sprintf($labels['walletDebt'], number_format($remaining - $total)) . "\n";
        }
    }
    $text .= sprintf($labels[$total === null ? 'topupAmount' : 'remaining'], number_format($remaining));

    $markup = json_decode($keyboard, true);
    foreach ($markup['inline_keyboard'] as $index => $row) {
        if (($row[0]['callback_data'] ?? null) !== 'digipaycredit') {
            continue;
        }
        $quote = digipayCheckoutQuote((int) $remaining);
        $text .= "\n\n" . digipayCheckoutAmount($labels, $quote);
        if (!$quote['eligible']) {
            $text .= "\n" . sprintf($labels['digipayRange'], number_format($quote['minimum']), number_format($quote['maximum']));
            unset($markup['inline_keyboard'][$index]);
            $markup['inline_keyboard'] = array_values($markup['inline_keyboard']);
        }
        break;
    }
    return [$text, json_encode($markup)];
}
