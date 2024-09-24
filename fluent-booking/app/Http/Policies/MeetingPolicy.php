<?php

namespace FluentBooking\App\Http\Policies;

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
        if (current_user_can('manage_options')) {
            return true;
        }

        if ($request->method() == 'GET') {
            if (PermissionManager::userCan(['manage_own_calendar','read_all_bookings', 'manage_all_bookings'])) {
                return true;
            }
            if ($request->id) {
                $booking = \FluentBooking\App\Models\Booking::find($request->id);
                if (!$booking) {
                    return false;
                }

                return in_array(get_current_user_id(), $booking->getHostIds());
            }

            return PermissionManager::userCan('manage_own_calendar');
        }

        if (PermissionManager::userCan(['manage_own_calendar', 'manage_all_bookings'])) {
            return true;
        }

        if ($request->id) {
            $booking = \FluentBooking\App\Models\Booking::find($request->id);
            if (!$booking) {
                return false;
            }

            return in_array(get_current_user_id(), $booking->getHostIds());
        }

        return PermissionManager::userCan('manage_own_calendar');
    }

    public function getGroupAttendees(Request $request)
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        if (PermissionManager::userCan(['read_all_bookings', 'manage_all_bookings'])) {
            return true;
        }

        $booking = \FluentBooking\App\Models\Booking::where('group_id', $request->group_id)->first();

        if (!$booking) {
            return false;
        }

        return in_array(get_current_user_id(), $booking->getHostIds());

    }
}
