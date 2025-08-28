<?php

namespace FluentBooking\App\Hooks\Handlers;

use FluentBooking\Framework\Foundation\Application;
use FluentBooking\Database\DBMigrator;
use FluentBooking\Database\DBSeeder;

class ActivationHandler
{
    protected $app = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle($network_wide = false)
    {
        DBMigrator::run($network_wide);
        DBSeeder::run();

        $this->registerWpCron();
    }

    public function registerWpCron()
    {
        $fiveMinutesHook = 'fluent_booking_five_minutes_tasks';
        $hourlyHook = 'fluent_booking_hourly_tasks';
        $dailyHook = 'fluent_booking/daily_tasks';

        if(function_exists('\as_schedule_recurring_action')) {
            if (!\as_next_scheduled_action($fiveMinutesHook)) {
                \as_schedule_recurring_action(time(), (60 * 5), $fiveMinutesHook, [], 'fluent-booking', true);
            }
            if (!\as_next_scheduled_action($hourlyHook)) {
                \as_schedule_recurring_action(time(), (60 * 60), $hourlyHook, [], 'fluent-booking', true);
            }
            if (!\as_next_scheduled_action($dailyHook)) {
                \as_schedule_recurring_action(time(), (60 * 60 * 24), $dailyHook, [], 'fluent-booking', true);
            }
        }
    }
}
