<?php

defined( 'ABSPATH' ) || exit;

use FluentBooking\Framework\Foundation\Application;
use FluentBooking\App\Hooks\Handlers\ActivationHandler;
use FluentBooking\App\Hooks\Handlers\DeactivationHandler;
use FluentBooking\Database\DBMigrator;

return function ($file) {

    $app = new Application($file);

    register_activation_hook($file, function () use ($app) {
        ($app->make(ActivationHandler::class))->handle();
    });

    register_deactivation_hook($file, function () use ($app) {
        ($app->make(DeactivationHandler::class))->handle();
    });

    require_once(FLUENT_BOOKING_DIR . 'boot/action_scheduler_loader.php');

    add_action('plugins_loaded', function () use ($app) {
        do_action('fluent_booking/loaded', $app);

        $currentDBVersion = get_option('fluent_booking_db_version');
        if (!$currentDBVersion || version_compare($currentDBVersion, FLUENT_BOOKING_DB_VERSION, '<')) {
            update_option('fluent_booking_db_version', FLUENT_BOOKING_DB_VERSION, 'no');
            DBMigrator::run();
        }

        if (defined('FLUENT_BOOKING_PRO_VERSION') && version_compare(FLUENT_BOOKING_MIN_PRO_VERSION, FLUENT_BOOKING_PRO_VERSION, '>')) {
            if (!current_user_can('manage_options')) {
                return;
            }
            add_filter('fluent_booking/dashboard_notices', function ($notices) {
                $updateUrl = admin_url('plugins.php?s=fluent-booking&plugin_status=all&fluent-booking-pro-check-update=' . time());
                $notices[] = '<div class="error">' . esc_html__('FluentBookingPro plugin needs to be updated to the latest version.', 'fluent-booking') . ' <a href="' . esc_url($updateUrl) . '">' . esc_html__('Click here to update', 'fluent-booking') . '</a></div>';
                return $notices;
            });
        }
    });
};
