<?php

/*
 * Global Modules Intialization
 */
(new \FluentBooking\App\Services\GlobalModules\GlobalModules())->register();

// Global Notification Handler
(new \FluentBooking\App\Hooks\Handlers\GlobalNotificationHandler())->register();

add_action('fluentform/loaded', function () {
    (new \FluentBooking\App\Services\Integrations\FluentForms\FluentFormInit())->init();
});

add_action('fluentcrm_loaded', function () {
    (new \FluentBooking\App\Services\Integrations\FluentCRM\FluentCrmInit());

    add_action('init', function () {
        (new \FluentBooking\App\Services\Integrations\FluentCRM\Bootstrap());
    });

});

add_action('fluent_boards_loaded', function () {
    add_action('init', function () {
        (new \FluentBooking\App\Services\Integrations\FluentBoards\Bootstrap());
    });
});

if (defined('ELEMENTOR_VERSION')) {
    /*
     * We will keep it for then time being for backward compatibility of Old Fluent Booking Pro
     */
    if (!class_exists('\FluentBookingPro\App\Services\Integrations\Elementor\ElementorIntegration')) {
        (new \FluentBooking\App\Services\Integrations\Elementor\ElementorIntegration())->register();
    }
}
