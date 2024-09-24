<?php

namespace FluentBooking\App\Hooks\Handlers;

use FluentBooking\App\Models\BookingActivity;
use FluentBooking\Framework\Support\Arr;

class LogHandler
{
    public function register()
    {
        add_action('fluent_booking/log_booking_note', [$this, 'logBookingActivity'], 10);
        add_action('fluent_booking/log_booking_activity', [$this, 'logBookingActivity'], 10, 1);
    }

    public function logBookingActivity($data)
    {
        $logData = array_filter(Arr::only($data, [
            'status',
            'type',
            'title',
            'description',
            'booking_id'
        ]));

        if (!$logData || empty($logData['booking_id'])) {
            return;
        }

        BookingActivity::create($logData);
    }
}
