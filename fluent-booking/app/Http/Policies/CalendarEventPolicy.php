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
        if (current_user_can('manage_options') || PermissionManager::userCan('manage_other_calendars')) {
            return true;
        }

        if ($request->event_id) {
            $calendarEvent = CalendarSlot::find($request->event_id);
            if (!$calendarEvent) {
                return false;
            }
            return in_array(get_current_user_id(), $calendarEvent->getHostIds());
        }

        if ($request->method() == 'GET') {
            return PermissionManager::userCan('read_other_calendars');
        }

        return false;
    }
}
