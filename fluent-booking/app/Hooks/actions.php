<?php

defined('ABSPATH') || exit;

/**
 * Handlers live in app/Hooks/Handlers. addCustomAction('foo', ...) is
 * add_action('slug-foo', ...), prefixed with the plugin slug.
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
 * MCP server for AI agents. Off by default: until enabled in Settings, boot()
 * only registers the Toolkit discovery filters. See docs/mcp-server-spec.md.
 */
\FluentBooking\App\Modules\MCP\MCPInit::boot();


/*
 * Register all the single action handlers
 */
$app->addAction('init', 'BlockEditorHandler@init');

$app->addAction('wp_ajax_fluent_booking_export_calendar', 'DataExporter@exportCalendar');
$app->addAction('wp_ajax_fluent_booking_export_hosts', 'DataExporter@exportBookingHosts');
$app->addAction('wp_ajax_fluent_booking_import_calendar', 'DataImporter@importCalendar');

$app->addAction('fluent_booking/after_calendar_event_landing_page', function () {
    echo wp_kses_post(\FluentBooking\App\Services\LandingPage\LandingPageHelper::getPoweredByHtml());
}, 10);

add_action('init', function () {
    if (!isset($_REQUEST['gcal'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }
});
