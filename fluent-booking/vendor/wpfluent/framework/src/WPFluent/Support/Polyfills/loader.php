<?php

/**
 * Autoload shim for the framework's polyfills.
 *
 * This is the single composer "files" autoload entry for every polyfill the
 * framework ships, and the only file in this folder that must always exist.
 * Each polyfill is a lowercase <area>.php file next to this one that defines
 * the functions and constants missing at runtime; a PascalCase folder (like
 * MBString/) holds any classes or data tables backing such a file.
 *
 * Load order is PHP itself, then WordPress core's own shims in
 * wp-includes/compat.php (core loads before any plugin autoloader), then
 * this loader. So a function that core ships is never redefined here, and
 * the framework's copy only fills the gap on older WordPress or when the
 * framework runs standalone. Because either copy may be the one in use,
 * every polyfill here must behave exactly like core's version: same
 * results, same exception types, same messages.
 *
 * To add a polyfill: create the file and append its path to the list below.
 * To drop one: delete its file (and folder). The guard below skips missing
 * entries, so nothing breaks at autoload time and composer.json does not
 * need to change either way.
 *
 * Everything listed here is loaded on every request, before the plugin
 * boots, so each file must parse on the oldest PHP version the framework
 * supports (see "php" in composer.json). Guards like function_exists() do
 * not help with that: the whole file is compiled before they run.
 */

$polyfills = [
    __DIR__ . '/math.php',
    __DIR__ . '/mbstring.php',
    __DIR__ . '/pcre.php',
    __DIR__ . '/string.php',
];

foreach ($polyfills as $polyfill) {
    if (is_file($polyfill)) {
        require_once $polyfill;
    }
}

unset($polyfills, $polyfill);
