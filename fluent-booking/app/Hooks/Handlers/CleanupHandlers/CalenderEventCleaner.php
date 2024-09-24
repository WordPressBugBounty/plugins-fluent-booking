<?php

namespace FluentBooking\App\Hooks\Handlers\CleanupHandlers;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Meta;

class CalenderEventCleaner
{
    public function register()
    {
        add_action('fluent_booking/before_delete_calendar_event', [$this, 'handleBeforeDelete'], 10, 2);
    }

    public function handleBeforeDelete($calendarEvent, $calendar)
    {
        if (empty($calendarEvent)) {
            return;
        }

        Meta::query()
            ->where('object_type', 'calendar_event')
            ->where('object_id', $calendarEvent->id)->delete();

        $bookings = Booking::query()
            ->where('event_id', $calendarEvent->id)
            ->get();

        foreach ($bookings as $booking){
            do_action('fluent_booking/before_delete_booking', $booking);
            $booking->delete();
            do_action('fluent_booking/after_delete_booking', $booking);
        }


    }
}