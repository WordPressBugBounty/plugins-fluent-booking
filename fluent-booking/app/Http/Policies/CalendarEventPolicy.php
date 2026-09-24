<?php

namespace FluentBooking\App\Http\Policies;

use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\Framework\Foundation\Policy;
use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\Framework\Support\Arr;

class CalendarEventPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param \FluentBooking\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        if (PermissionManager::userCan(['manage_all_data', 'manage_other_calendars'])) {
            return true;
        }

        // Resolve event_id from the URL route only — request-body values
        // must not be permitted to redirect the authorization target.
        // The /bookings/ index route has no placeholder so guard the access.
        $urlParams = (array) $request->get_url_params();
        $eventId = isset($urlParams['event_id']) ? (int) $urlParams['event_id'] : 0;

        if ($eventId) {
            $calendarEvent = CalendarSlot::find($eventId);
            if (!$calendarEvent) {
                return false;
            }
            return in_array(get_current_user_id(), $calendarEvent->getHostIds());
        }

        if ($request->getMethod() == 'GET') {
            return PermissionManager::userCan(['manage_all_data', 'read_other_calendars']);
        }

        return false;
    }

    public function rescheduleBooking(Request $request)
    {
        return $this->canRescheduleRouteBooking($request);
    }

    public function getRescheduleSlots(Request $request)
    {
        return $this->canRescheduleRouteBooking($request);
    }

    /**
     * The manage_all_bookings bypass is scoped to the booking in the URL, so it
     * never widens access to events that are not being rescheduled.
     */
    private function canRescheduleRouteBooking(Request $request)
    {
        $urlParams = (array) $request->get_url_params();
        $bookingId = (int) Arr::get($urlParams, 'id');
        $eventId = (int) Arr::get($urlParams, 'event_id');

        $booking = $bookingId ? Booking::find($bookingId) : null;

        if (!$booking || (int) $booking->event_id !== $eventId) {
            return false;
        }

        if (PermissionManager::userCan(['manage_all_data', 'manage_all_bookings'])) {
            return true;
        }

        return $this->verifyRequest($request);
    }
}
