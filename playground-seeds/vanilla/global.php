<?php

// Plain functions without a namespace and without composer: the "bare files" mode.

function vanilla_total(array $items): float
{
    $total = 0.0;
    foreach ($items as $item) {
        if (isset($item['price'])) {
            if (isset($item['qty'])) {
                $total = $total + $item['price'] * $item['qty'];
            } else {
                $total = $total + $item['price'];
            }
        }
    }

    return $total;
}

function vanilla_initials(string $name): string
{
    $parts = explode(' ', trim($name));
    $initials = '';
    foreach ($parts as $part) {
        if (strlen($part) > 0) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }

    return $initials;
}

$format = static function (float $amount): string {
    return number_format($amount, 2, '.', ' ');
};
