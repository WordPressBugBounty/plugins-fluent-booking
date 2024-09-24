<?php

/**
 * @var $router FluentBooking\Framework\Http\Router
 */

$router->namespace('FluentBooking\App\Http\Controllers')->group(function($router) {
    require_once __DIR__ . '/api.php';
});
