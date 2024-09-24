<?php

namespace FluentBooking\App\Hooks\Handlers;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\CalendarSlot;

class IntegrationHandlers
{
    public function boot()
    {
        add_action('fluent_booking/after_booking_scheduled', array($this, 'pushScheduledToQueue'), 10, 2);
        add_action('fluent_booking/run_booking_integrations_for_scheduled', [$this, 'runForScheduledIntegrations'], 10, 2);
    }

    public function pushScheduledToQueue($booking, $slot)
    {
        as_enqueue_async_action('fluent_booking/run_booking_integrations_for_scheduled', [
            $booking->id,
            $slot->id
        ], 'fluent-booking');
    }

    public function runForScheduledIntegrations($bookingId, $slotId)
    {
        $booking = Booking::find($bookingId);
        $slot = CalendarSlot::find($slotId);

        if (!$booking || !$slot) {
            return;
        }

        // We will run the integrations for the calendar

    }
}
