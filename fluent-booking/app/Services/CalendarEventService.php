<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\Models\Calendar;
use FluentBooking\Framework\Support\Arr;

class CalendarEventService
{
    public static function processEvent($calendarEvent)
    {
        $calendarEvent->payment_html = $calendarEvent->getPaymentHtml();

        $calendarEvent->public_url = $calendarEvent->getPublicUrl();

        $calendarEvent->durations = $calendarEvent->getAvailableDurations();

        $calendarEvent->description = $calendarEvent->getDescription();

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

    public static function isSharedCalendarEvent($calendarEvent)
    {
        $userId = get_current_user_id();

        if ($calendarEvent->user_id == $userId) {
            return true;
        }

        $teamMembers = Arr::get($calendarEvent, 'settings.team_members', []);

        return in_array($userId, $teamMembers);
    }
}
