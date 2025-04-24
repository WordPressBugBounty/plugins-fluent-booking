<?php

namespace FluentBooking\App\Http\Policies;

use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\Framework\Foundation\Policy;

class CalendarPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param \FluentBooking\Framework\Http\Request\Request $request
     * @return bool
     */
    public function verifyRequest(Request $request)
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $calendarId = $request->calendar_id;

        if (!$calendarId) {
            return apply_filters('fluent_booking/verify_calendar_api', current_user_can('manage_options'), $request);
        }

        $method = $request->method();

        if ($method == 'GET') {
            return PermissionManager::canReadCalendar($calendarId);
        }

        $eventId = $request->event_id;

        if ($eventId) {
            return PermissionManager::canUpdateCalendarEvent($eventId);
        }

        return PermissionManager::canWriteCalendar($calendarId);
    }

    public function getAllCalendars(Request $request)
    {
        return !!PermissionManager::currentUserHasAnyPemrmission();
    }

    public function createCalendar(Request $request)
    {
        if (PermissionManager::userCan('invite_team_members')) {
            return true;
        }

        if (PermissionManager::userCan('manage_own_calendar')) {

            $exist = Calendar::where('user_id', get_current_user_id())->first();
            if (!$exist) {
                return true;
            }

            return true;
        }

    }

    public function checkSlug(Request $request)
    {
        return PermissionManager::userCan(['invite_team_members', 'manage_own_calendar']);
    }

    public function getEvent(Request $request, $calendarId, $eventId)
    {
        return PermissionManager::canUpdateCalendarEvent($eventId);
    }

    public function deleteCalendar(Request $request)
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $calendarId = $request->id;

        $calendar = Calendar::find($calendarId);

        if (!$calendar) {
            return false;
        }

        return $calendar->user_id == get_current_user_id();
    }

    public function deleteCalendarEvent(Request $request)
    {
        return $this->deleteCalendar($request);
    }
}
