<?php

namespace FluentBooking\App\Http\Controllers;

use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\Framework\Support\Arr;

class EventController extends Controller
{
    public function getEvent(Request $request, $eventId)
    {
        $calendarEvent = CalendarSlot::with('calendar')->find($eventId);

        $eventHash = Arr::get($request->all(), 'event_hash');

        if (!$calendarEvent && $eventHash) {
            $calendarEvent = CalendarSlot::with('calendar')->where('hash', $eventHash)->first();
        }

        if (!$calendarEvent) {
            return $this->sendError([
                'message' => __('Calendar Event not found', 'fluent-booking')
            ]);
        }

        $calendarEvent->author_profile = $calendarEvent->getAuthorProfile();

        $calendarEvent->calendar->author_profile = $calendarEvent->calendar->getAuthorProfile();

        return [
            'calendar_event' => $calendarEvent
        ];
    }

}
