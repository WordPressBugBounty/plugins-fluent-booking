<?php

/**
 ***** DO NOT CALL ANY FUNCTIONS DIRECTLY FROM THIS FILE ******
 *
 * This file will be loaded even before the framework is loaded
 * so the $app is not available here, only declare functions here.
 */

defined( 'ABSPATH' ) || exit;

if ($app->config->get('app.env') == 'dev') {

    $fluentBookingGlobalsDevFile = __DIR__ . '/globals_dev.php';
    
    is_readable($fluentBookingGlobalsDevFile) && include $fluentBookingGlobalsDevFile;
}

if (!function_exists('dd')) {
    function dd() // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    {
        foreach (func_get_args() as $fluentBookingArg) {
            echo "<pre>";
            print_r($fluentBookingArg); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
            echo "</pre>";
        }
        die();
    }
}

function fluentbookingFormattedAmount($amountInCents, $currencySettings)
{
    $default = [
        'currency_sign'     => '',
        'currency_position' => 'left',
        'number_format'     => 'comma_separated', // dot_separated
        'decimal_points'    => 2,
    ];

    $currencySettings = array_merge($default, $currencySettings);

    $symbol            = $currencySettings['currency_sign'];
    $position          = $currencySettings['currency_position'];
    $decimalPoints     = $currencySettings['decimal_points'];
    $thousandSeparator = $currencySettings['number_format'] === 'comma_separated' ? ',' : '.';
    $decimalSeparator  = $currencySettings['number_format'] === 'comma_separated' ? '.' : ',';

    $amount = number_format($amountInCents / 100, $decimalPoints, $decimalSeparator, $thousandSeparator);

    $formats = [
        'left'        => fn() => $symbol . $amount,
        'left_space'  => fn() => $symbol . ' ' . $amount,
        'right'       => fn() => $amount . $symbol,
        'right_space' => fn() => $amount . ' ' . $symbol,
    ];

    return isset($formats[$position]) ? $formats[$position]() : $amount;
}
