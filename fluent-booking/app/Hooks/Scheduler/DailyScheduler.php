<?php

namespace FluentBooking\App\Hooks\Scheduler;

use FluentBooking\App\Modules\MCP\Support\WriteGuard;
use FluentBooking\App\Services\SummaryReportService;

class DailyScheduler
{
    public function register()
    {
        add_action('fluent_booking/daily_tasks', [$this, 'handleDailyTasks']);
    }

    public function handleDailyTasks()
    {
        // The purge runs first, and in its own try: MCP confirm-token and
        // idempotency records live in options rather than transients (they
        // need an atomic claim and must survive a cache flush), so nothing
        // else expires them — and behind the summary, a slow or throwing
        // summary would starve them out of the tick indefinitely.
        try {
            WriteGuard::purgeExpired();
        } catch (\Throwable $e) {
            if (defined('FLUENT_BOOKING_DEBUG') && FLUENT_BOOKING_DEBUG) {
                error_log('FluentBooking MCP purge failed: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }

        SummaryReportService::maybeSendSummary();
    }
}