<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\Models\Calendar;

class CalendarEventService
{
    public static function processEvent($calendarEvent)
    {
        $calendarEvent->payment_html = $calendarEvent->getPaymentHtml();

        $calendarEvent->public_url = $calendarEvent->getPublicUrl();

        $calendarEvent->durations = $calendarEvent->getAvailableDurations();

        $calendarEvent->description = $calendarEvent->getDescription();

        $calendarEvent->short_description = Helper::excerpt($calendarEvent->description);

        $calendarEvent->locations = $calendarEvent->defaultLocationHtml();

        do_action_ref_array('fluent_booking/processed_event', [&$calendarEvent]);

        return $calendarEvent;
    }

    public static function processEvents(Calendar $calendar, $calendarEvents)
    {
        $eventOrder = $calendar->getMeta('event_order');

        if (!empty($eventOrder)) {
            $calendarEvents = $calendarEvents->sortBy(function($event) use ($eventOrder) {
                return array_search($event->id, $eventOrder);
            })->values();
        }

        foreach ($calendarEvents as $calendarEvent) {
            $calendarEvent = self::processEvent($calendarEvent);
        }

        return $calendarEvents;
    }
}
