<?php

/**
 * PCRE function polyfills.
 *
 * Each function is only defined when it is missing, so PHP's native
 * implementation always wins. WordPress core does not polyfill these, so
 * the contract to match is PHP's own.
 *
 * Loaded via the Polyfills/loader.php autoload shim. Keep this file
 * parseable on the oldest PHP the framework supports.
 */

if (! function_exists('preg_last_error_msg')) {
    /**
     * Return the error message for the last PCRE regex execution.
     *
     * Mirrors PHP 8.0's preg_last_error_msg(): the strings are the ones PHP
     * itself returns for each preg_last_error() code.
     *
     * @return string
     */
    function preg_last_error_msg()
    {
        switch (preg_last_error()) {
            case PREG_NO_ERROR:
                return 'No error';
            case PREG_INTERNAL_ERROR:
                return 'Internal error';
            case PREG_BACKTRACK_LIMIT_ERROR:
                return 'Backtrack limit exhausted';
            case PREG_RECURSION_LIMIT_ERROR:
                return 'Recursion limit exhausted';
            case PREG_BAD_UTF8_ERROR:
                return 'Malformed UTF-8 characters, possibly incorrectly encoded';
            case PREG_BAD_UTF8_OFFSET_ERROR:
                return 'The offset did not correspond to the beginning of a valid UTF-8 code point';
            case PREG_JIT_STACKLIMIT_ERROR:
                return 'JIT stack limit exhausted';
            default:
                return 'Unknown error';
        }
    }
}
