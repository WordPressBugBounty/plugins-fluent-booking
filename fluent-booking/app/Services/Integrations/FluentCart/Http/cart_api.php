<?php

defined('ABSPATH') || exit;

/**
 * @var $router FluentBooking\Framework\Http\Router
 */

$router->prefix('calendars')->withPolicy('CalendarPolicy')->group(function ($router) {
    $router->post('/{id}/events/{event_id}/cart-settings', [\FluentBooking\App\Services\Integrations\FluentCart\Http\Controller\FluentCartController::class, 'saveEventCartSettings'])->int('id')->int('event_id');
});

$router->prefix('integrations')->withPolicy('SettingsPolicy')->group(function ($router) {
    $router->get('/options/cart-products', [\FluentBooking\App\Services\Integrations\FluentCart\Http\Controller\FluentCartController::class, 'getCartProducts']);
    $router->post('/create/cart-product', [\FluentBooking\App\Services\Integrations\FluentCart\Http\Controller\FluentCartController::class, 'createProduct']);
});