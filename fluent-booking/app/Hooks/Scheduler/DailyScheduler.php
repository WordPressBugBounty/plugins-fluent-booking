<?php

namespace FluentBooking\App\Hooks\Scheduler;

use FluentBooking\App\Services\SummaryReportService;

class DailyScheduler
{
    public function register()
    {
        add_action('fluent_booking/daily_tasks', [$this, 'handleDailyTasks']);
    }

    public function handleDailyTasks()
    {
        SummaryReportService::maybeSendSummary();
    }
}