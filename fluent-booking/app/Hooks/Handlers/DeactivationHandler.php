<?php

namespace FluentBooking\App\Hooks\Handlers;

use FluentBooking\Framework\Foundation\Application;

class DeactivationHandler
{
    protected $app = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle()
    {
        if(function_exists('\as_unschedule_all_actions')) {
            \as_unschedule_all_actions('fluent_booking_five_minutes_tasks');
            \as_unschedule_all_actions('fluent_booking_hourly_tasks');
            \as_unschedule_all_actions('fluent_booking/daily_tasks');
        }

        wp_clear_scheduled_hook('fluent_booking_five_minutes_tasks');
        wp_clear_scheduled_hook('fluent_booking_hourly_tasks');
        wp_clear_scheduled_hook('fluent_booking/daily_tasks');
    }

}
