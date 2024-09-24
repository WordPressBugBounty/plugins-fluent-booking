<?php

/**
 ***** DO NOT CALL ANY FUNCTIONS DIRECTLY FROM THIS FILE ******
 *
 * This file will be loaded even before the framework is loaded
 * so the $app is not available here, only declare functions here.
 */

defined( 'ABSPATH' ) || exit;

if ($app->config->get('app.env') == 'dev') {

    $globalsDevFile = __DIR__ . '/globals_dev.php';
    
    is_readable($globalsDevFile) && include $globalsDevFile;
}

if (!function_exists('dd')) {
    function dd()
    {
        foreach (func_get_args() as $arg) {
            echo "<pre>";
            print_r($arg);
            echo "</pre>";
        }
        die();
    }
}

function fluentbookingFormattedAmount($amountInCents, $currencySettings)
{
    $default = [
        'currency_sign' => '',
        'currency_position' => 'left',
        'decimal_separator' => '.',
        'thousand_separator' => ',',
        'currency_separator' => 'dot_comma',
        'decimal_points' => 2,
    ];

    $currencySettings = array_merge($default, $currencySettings);

    $position =  $currencySettings['currency_position'];
    $symbol = $currencySettings['currency_sign'];
    $decimalPoints = $currencySettings['decimal_points'];
    $decmalSeparator = $currencySettings['decimal_separator'];
    $thousandSeparator = $currencySettings['thousand_separator'];

    if ($currencySettings['currency_separator'] != 'dot_comma') {
        $decmalSeparator = ',';
        $thousandSeparator = '.';
    }
    if ($amountInCents % 100 == 0 && $currencySettings['decimal_points'] == 0) {
        $decimalPoints = 0;
    }

    $amount = number_format($amountInCents / 100, $decimalPoints, $decmalSeparator, $thousandSeparator);

    if ('left' === $position) {
        return $symbol . $amount;
    } elseif ('left_space' === $position) {
        return $symbol . ' ' . $amount;
    } elseif ('right' === $position) {
        return $amount . $symbol;
    } elseif ('right_space' === $position) {
        return $amount . ' ' . $symbol;
    }
    return $amount;
}
