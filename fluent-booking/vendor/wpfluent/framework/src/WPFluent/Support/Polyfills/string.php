<?php

/**
 * String function polyfills.
 *
 * Each function is only defined when it is missing, so PHP's native
 * implementation, or the copy WordPress core ships in wp-includes/compat.php
 * (which loads before this file and has carried these since WordPress 5.9),
 * always wins. The bodies below are core's, so behaviour is identical
 * whichever copy is in use.
 *
 * The framework itself calls these dozens of times, so this file is what
 * keeps it working on PHP 7.4 with a WordPress older than 5.9, or when the
 * framework runs standalone.
 *
 * Loaded via the Polyfills/loader.php autoload shim. Keep this file
 * parseable on the oldest PHP the framework supports.
 */

if (! function_exists('str_contains')) {
    /**
     * Check whether a string contains a given substring.
     *
     * Mirrors PHP 8.0's str_contains(): an empty needle always matches.
     *
     * @param  string  $haystack
     * @param  string  $needle
     * @return bool
     */
    function str_contains($haystack, $needle)
    {
        if ('' === $needle) {
            return true;
        }

        return false !== strpos($haystack, $needle);
    }
}

if (! function_exists('str_starts_with')) {
    /**
     * Check whether a string starts with a given substring.
     *
     * Mirrors PHP 8.0's str_starts_with(): an empty needle always matches.
     *
     * @param  string  $haystack
     * @param  string  $needle
     * @return bool
     */
    function str_starts_with($haystack, $needle)
    {
        if ('' === $needle) {
            return true;
        }

        return 0 === strpos($haystack, $needle);
    }
}

if (! function_exists('str_ends_with')) {
    /**
     * Check whether a string ends with a given substring.
     *
     * Mirrors PHP 8.0's str_ends_with(): an empty needle always matches.
     *
     * @param  string  $haystack
     * @param  string  $needle
     * @return bool
     */
    function str_ends_with($haystack, $needle)
    {
        if ('' === $haystack) {
            return '' === $needle;
        }

        $len = strlen($needle);

        return substr($haystack, -$len, $len) === $needle;
    }
}
