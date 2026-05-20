<?php

namespace FluentBooking\App\Http\Policies;

use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\Framework\Foundation\Policy;
use FluentBooking\App\Models\CalendarSlot;

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

        if ($request->method() == 'GET') {
            return PermissionManager::userCan(['manage_all_data', 'read_other_calendars']);
        }

        return false;
    }
}
