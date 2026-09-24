<?php

/**
 * Math function polyfills.
 *
 * Each function is only defined when it is missing, so PHP's native
 * implementation, or the copy WordPress core ships in wp-includes/compat.php
 * (which loads before this file), always wins. Keep every function here
 * behaviourally identical to core's version: same results, same exception
 * types, same messages.
 *
 * Loaded via the Polyfills/loader.php autoload shim. Keep this file
 * parseable on the oldest PHP the framework supports: no union types, no
 * named arguments and so on. Express the types in docblocks instead.
 */

if (! function_exists('clamp')) {
    /**
     * Clamp a value to the inclusive range [$min, $max].
     *
     * Returns $value when it lies within the bounds, otherwise the nearest
     * bound. Mirrors the clamp() added in PHP 8.6 and the polyfill in
     * WordPress 7.1's wp-includes/compat.php: like both, the arguments are
     * not type-checked.
     *
     * @param  int|float  $value
     * @param  int|float  $min    Must be less than or equal to $max.
     * @param  int|float  $max    Must be greater than or equal to $min.
     * @return int|float  Either $value, $min or $max.
     *
     * @throws \ValueError               If $min > $max or a bound is NAN (PHP 8+).
     * @throws \InvalidArgumentException The same conditions on PHP 7.x, where
     *                                   ValueError does not exist.
     */
    function clamp($value, $min, $max)
    {
        $fail = static function ($message) {
            if (class_exists('ValueError', false)) {
                throw new ValueError($message);
            }

            throw new InvalidArgumentException($message);
        };

        if (is_float($min) && is_nan($min)) {
            $fail('clamp(): Argument #2 ($min) must not be NAN');
        }

        if (is_float($max) && is_nan($max)) {
            $fail('clamp(): Argument #3 ($max) must not be NAN');
        }

        if ($max < $min) {
            $fail(
                'clamp(): Argument #2 ($min) must be smaller than or equal to argument #3 ($max)'
            );
        }

        // Upper bound first, matching PHP's implementation. Comparison is not
        // transitive across mixed operand types, so both bounds can compare
        // as exceeded at once and the first check has to win.
        if ($value > $max) {
            return $max;
        }

        if ($value < $min) {
            return $min;
        }

        return $value;
    }
}
