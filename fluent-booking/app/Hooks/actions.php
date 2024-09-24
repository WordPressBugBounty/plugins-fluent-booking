<?php

defined('ABSPATH') || exit;

/**
 * All registered action's handlers should be in app\Hooks\Handlers,
 * addAction is similar to add_action and addCustomAction is just a
 * wrapper over add_action which will add a prefix to the hook name
 * using the plugin slug to make it unique in all wordpress plugins,
 * ex: $app->addCustomAction('foo', ['FooHandler', 'handleFoo']) is
 * equivalent to add_action('slug-foo', ['FooHandler', 'handleFoo']).
 */

/**
 * @var $app FluentBooking\Framework\Foundation\Application
 */

/*
 * Register all the grouped action handlers
 */

(new \FluentBooking\App\Hooks\Handlers\FrontEndHandler())->register();
(new \FluentBooking\App\Hooks\Handlers\CleanupHandlers\CleanupHandler())->register();
(new \FluentBooking\App\Hooks\Handlers\NotificationHandler())->register();
(new \FluentBooking\App\Hooks\Handlers\LogHandler())->register();
(new \FluentBooking\App\Hooks\Handlers\AdminMenuHandler())->register();
(new \FluentBooking\App\Hooks\Scheduler\FiveMinuteScheduler())->register();
(new \FluentBooking\App\Hooks\Scheduler\DailyScheduler())->register();
(new \FluentBooking\App\Services\LandingPage\LandingPageHandler())->boot();


/*
 * Register all the single action handlers
 */
$app->addAction('init', 'BlockEditorHandler@init');

$app->addAction('wp_ajax_fluent_booking_export_calendar', 'DataExporter@exportCalendar');
$app->addAction('wp_ajax_fluent_booking_export_hosts', 'DataExporter@exportBookingHosts');
$app->addAction('wp_ajax_fluent_booking_import_calendar', 'DataImporter@importCalendar');

$app->addAction('fluent_booking/after_calendar_event_landing_page', function () {
    echo \FluentBooking\App\Services\LandingPage\LandingPageHelper::getPoweredByHtml();
}, 10);

add_action('init', function () {
    if (!isset($_REQUEST['gcal'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }
});
