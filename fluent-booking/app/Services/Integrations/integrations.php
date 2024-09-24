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
    (new \FluentBooking\App\Services\Integrations\FluentCRM\Bootstrap());
});

add_action('fluent_boards_loaded', function () {
    (new \FluentBooking\App\Services\Integrations\FluentBoards\Bootstrap());
});