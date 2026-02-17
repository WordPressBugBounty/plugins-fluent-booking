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

        if ($request->method() == 'GET') {
            if (PermissionManager::userCan(['manage_own_calendar','read_all_bookings'])) {
                return true;
            }

            if ($request->id) {
                $booking = Booking::find($request->id);
                return $this->hasBookingAccess($booking);
            }
        }

        if ($request->id) {
            $booking = Booking::find($request->id);
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

        $booking = Booking::where('group_id', $request->group_id)->first();

        return $this->hasBookingAccess($booking);
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
