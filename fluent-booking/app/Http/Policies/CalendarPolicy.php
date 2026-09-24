<?php

namespace FluentBooking\App\Http\Policies;

use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
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
        if (PermissionManager::userCan('manage_all_data')) {
            return true;
        }

        // Resolve IDs strictly from URL route params; request-body values
        // must never be allowed to redirect the authorization target.
        $calendarId = (int) $this->getRouteParam($request, 'id');
        $eventId    = (int) $this->getRouteParam($request, 'event_id');

        if (!$calendarId) {
            return apply_filters('fluent_booking/verify_calendar_api', current_user_can('manage_options'), $request);
        }

        if ($eventId && !CalendarSlot::where('calendar_id', $calendarId)->where('id', $eventId)->exists()) {
            return false;
        }

        $method = $request->getMethod();

        // Event-scoped for reads too: hosting one event must not expose its sibling events' settings.
        if ($eventId) {
            return PermissionManager::canUpdateCalendarEvent($eventId);
        }

        if ($method == 'GET') {
            return PermissionManager::canReadCalendar($calendarId);
        }

        return PermissionManager::canWriteCalendar($calendarId);
    }

    public function getAllCalendars(Request $request)
    {
        return !!PermissionManager::currentUserHasAnyPermission();
    }

    public function createCalendar(Request $request)
    {
        return $this->canCreateCalendar();
    }

    public function checkSlug(Request $request)
    {
        return $this->canCreateCalendar();
    }

    public function getNewEventLocationFields(Request $request)
    {
        return $this->canCreateCalendar();
    }

    public function getEvent(Request $request, $calendarId, $eventId)
    {
        return PermissionManager::canUpdateCalendarEvent($eventId);
    }

    public function deleteCalendar(Request $request)
    {
        if (PermissionManager::userCan('manage_all_data')) {
            return true;
        }

        $calendarId = (int) $this->getRouteParam($request, 'id');

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

    public function cloneCalendarEvent(Request $request)
    {
        if (PermissionManager::userCan('manage_all_data')) {
            return true;
        }

        $eventId = (int) $this->getRouteParam($request, 'event_id');

        if (!$eventId || !PermissionManager::canUpdateCalendarEvent($eventId)) {
            return false;
        }

        $sourceCalendarId = (int) $this->getRouteParam($request, 'id');
        $destinationCalendarId = intval($request->get('new_calendar_id')) ?: $sourceCalendarId;

        return PermissionManager::canWriteCalendar($destinationCalendarId);
    }

    private function canCreateCalendar()
    {
        return PermissionManager::userCan(['manage_all_data', 'invite_team_members', 'manage_own_calendar']);
    }

    /**
     * Read a URL route parameter. Some routes here (event-lists, root listing,
     * create) have no placeholder, so direct access would warn.
     */
    private function getRouteParam(Request $request, $key)
    {
        $params = (array) $request->get_url_params();
        return isset($params[$key]) ? $params[$key] : null;
    }
}
