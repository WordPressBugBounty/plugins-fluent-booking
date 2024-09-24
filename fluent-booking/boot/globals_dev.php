<?php

defined( 'ABSPATH' ) || exit;

/**
 * Enable Query Log
 */
if (!function_exists('fluentbooking_eql')) {
    function fluentbooking_eql()
    {
        defined('SAVEQUERIES') || define('SAVEQUERIES', true);
    }
}

/**
 * Get Query Log
 */
if (!function_exists('fluentbooking_gql')) {
    function fluentbooking_gql()
    {
        $result = [];
        foreach ((array)$GLOBALS['wpdb']->queries as $key => $query) {
            $result[++$key] = array_combine([
                'query', 'execution_time'
            ], array_slice($query, 0, 2));
        }
        return $result;
    }
}

