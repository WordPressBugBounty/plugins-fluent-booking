<?php

namespace FluentBooking\App\Http\Policies;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\Framework\Foundation\Policy;

class MeetingPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param \FluentBooking\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        if (PermissionManager::userCan(['manage_all_bookings', 'manage_all_data'])) {
            return true;
        }

        // Authorize only against the URL route parameter so request-body
        // values cannot override the resource being acted on.
        $bookingId = $this->getRouteBookingId($request);

        if ($request->method() == 'GET') {
            if (PermissionManager::userCan(['manage_own_calendar','read_all_bookings'])) {
                return true;
            }

            if ($bookingId) {
                $booking = Booking::find($bookingId);
                return $this->hasBookingAccess($booking);
            }
        }

        if ($bookingId) {
            $booking = Booking::find($bookingId);
            return $this->hasBookingAccess($booking);
        }

        return false;
    }

    public function getGroupAttendees(Request $request)
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        if (PermissionManager::userCanSeeAllBookings()) {
            return true;
        }

        $groupId = $this->getRouteParam($request, 'group_id');

        if (!$groupId) {
            return false;
        }

        $booking = Booking::where('group_id', $groupId)->first();

        return $this->hasBookingAccess($booking);
    }

    public function getBookingActivities(Request $request)
    {
        return $this->authorizeBookingAccess($request);
    }

    public function getBookingMetaInfo(Request $request)
    {
        return $this->authorizeBookingAccess($request);
    }

    private function authorizeBookingAccess(Request $request)
    {
        if (PermissionManager::userCan(['manage_all_bookings', 'manage_all_data', 'read_all_bookings'])) {
            return true;
        }

        $bookingId = $this->getRouteBookingId($request);

        if (!$bookingId) {
            return false;
        }

        $booking = Booking::find($bookingId);

        return $this->hasBookingAccess($booking);
    }

    /**
     * Resolve the booking ID from the URL route parameter only.
     *
     * Why: merged request inputs let JSON body values shadow URL params,
     * which previously allowed authorizing against an attacker-owned ID
     * while the controller acted on the URL-targeted victim ID.
     */
    private function getRouteBookingId(Request $request)
    {
        return $this->getRouteParam($request, 'id');
    }

    /**
     * Read a URL-only route parameter safely. Routes such as /schedules/
     * and /schedules/export have no path placeholders, so a direct
     * access would emit an undefined-array-key warning under PHP 8.
     */
    private function getRouteParam(Request $request, $key)
    {
        $params = (array) $request->get_url_params();
        return isset($params[$key]) ? $params[$key] : null;
    }

    private function hasBookingAccess($booking)
    {
        if (!$booking) {
            return false;
        }

        $userId = get_current_user_id();
        if (in_array($userId, $booking->getHostIds())) {
            return true;
        }

        if (!PermissionManager::userCan('manage_own_calendar')) {
            return false;
        }

        $calendarEvent = CalendarSlot::find($booking->event_id);
        if (!$calendarEvent) {
            return false;
        }

        return in_array($userId, $calendarEvent->getHostIds());
    }
}
