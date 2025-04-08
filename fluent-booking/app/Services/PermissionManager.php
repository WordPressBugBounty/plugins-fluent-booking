<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\Models\Meta;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\Framework\Support\Arr;

class PermissionManager
{
    public static function allPermissionSets()
    {
        return [
            'manage_own_calendar'               => __('Manage only own Calendar, Events, Bookings & Availability', 'fluent-booking'),
            'read_all_bookings'                 => __('Read Access to All Bookings', 'fluent-booking'),
            'manage_all_bookings'               => __('Read & Write Access to All Bookings', 'fluent-booking'),
            'read_other_calendars'              => __('Read Access of Other Users Calendars', 'fluent-booking'),
            'manage_other_calendars'            => __('Manage Other Users Calendars', 'fluent-booking'),
            'read_and_use_other_availabilities' => __('Read & Use Access of All Availabilities', 'fluent-booking'),
            'manage_other_availabilities'       => __('Manage All Availabilities', 'fluent-booking')
        ];
    }

    public static function hasAllCalendarAccess($readAccess = false)
    {
        $hasCalendarAccess = self::userCan('manage_other_calendars');

        if ($readAccess) {
            $hasCalendarAccess = $hasCalendarAccess || self::userCan('read_other_calendars');
        }
        
        return apply_filters('fluent_booking/has_all_calendar_access', current_user_can('manage_options')) || $hasCalendarAccess;
    }

    public static function canReadCalendar($calendarId)
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $calendar = Calendar::find($calendarId);

        if (!$calendar) {
            return false;
        }

        if ($calendar->user_id == get_current_user_id()) {
            return true;
        }

        if (CalendarService::isSharedCalendar($calendar)) {
            return true;
        }
        
        return self::userCan(['read_other_calendars', 'manage_other_calendars']);
    }

    public static function canUpdateCalendarEvent($slotId)
    {
        $calendarSlot = CalendarSlot::find($slotId);

        if (!$calendarSlot) {
            return false;
        }

        $userId = get_current_user_id();

        $teamMembers = Arr::get($calendarSlot, 'settings.team_members', []);

        if ($calendarSlot->user_id == $userId || in_array($userId, $teamMembers)) {
            return true;
        }

        return current_user_can('manage_options') || self::userCan(['manage_other_calendars']);
    }

    public static function canWriteCalendar($calendarId)
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $calendar = Calendar::find($calendarId);

        if (!$calendar) {
            return false;
        }

        if ($calendar->user_id == get_current_user_id()) {
            return true;
        }

        return self::userCan('manage_other_calendars');
    }

    public static function hasCalendarAccess($calendar)
    {
        $hasAccess = current_user_can('manage_options') || $calendar->user_id == get_current_user_id();

        return apply_filters('fluent_booking/has_calendar_access', $hasAccess, $calendar);
    }

    public static function currentUserHasAnyPemrmission()
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        return !!self::getUserPermissions();
    }

    public static function userCan($permissions)
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $userPermissions = self::getUserPermissions();

        if (!$userPermissions) {
            return false;
        }

        if (is_string($permissions)) {
            return in_array($permissions, $userPermissions);
        }

        if (is_array($permissions)) {
            foreach ($permissions as $permission) {
                if (in_array($permission, $userPermissions)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function getUserPermissions($user = null, $formatted = false)
    {
        if ($user === null) {
            $user = wp_get_current_user();
        }

        if (!$user || !$user->ID) {
            return [];
        }

        $allPermissions = self::allPermissionSets();
        if (user_can($user, 'manage_options')) {
            $permissions = array_merge(
                [
                    'super_admin' => 'All Access (Administrator)'
                ],
                $allPermissions
            );

            if ($formatted) {
                return $permissions;
            }

            return array_keys($permissions);
        }

        // maybe restricted Access
        $permissions = self::getMetaPermissions($user->ID);

        if (!$permissions) {
            $calendar = Calendar::where('user_id', $user->ID)->first();
            if (!$calendar) {
                return [];
            }

            Meta::create([
                'object_type' => 'user_meta',
                'object_id'   => $user->ID,
                'key'         => '_access_permissions',
                'value'       => ['manage_own_calendar']
            ]);

            if ($formatted) {
                return ['manage_own_calendar' => __('Manage only own Calendar, Events, Bookings & Availability', 'fluent-booking')];
            }
            return ['manage_own_calendar'];
        }

        if (!$formatted) {
            return $permissions;
        }

        $formattedPermissions = [];

        foreach ($permissions as $permission) {
            if (isset($allPermissions[$permission])) {
                $formattedPermissions[$permission] = $allPermissions[$permission];
            }
        }

        return $formattedPermissions;
    }

    public static function getMetaPermissions($userId = null)
    {

        if ($userId === null) {
            $userId = get_current_user_id();
        }

        if (!$userId) {
            return [];
        }

        $meta = Meta::where('object_type', 'user_meta')
            ->where('object_id', $userId)
            ->where('key', '_access_permissions')
            ->first();

        if ($meta) {
            return $meta->value;
        }

        return [];
    }

    public static function getMenuPermission()
    {
        if (current_user_can('manage_options')) {
            return 'manage_options';
        }

        $userId = get_current_user_id();

        // Check if the user has any calendar
        $calendar = Calendar::where('user_id', $userId)->first();

        if ($calendar) {
            $user = wp_get_current_user();
            $roles = array_values((array)$user->roles);
            return Arr::get($roles, 0);
        }

        // Check Meta Permissions
        $metaPermission = self::getMetaPermissions($userId);

        if (!$metaPermission) {
            return '';
        }

        $user = wp_get_current_user();
        $roles = array_values((array)$user->roles);

        return Arr::get($roles, 0);
    }

    public static function userCanSeeAllBookings()
    {
        return self::userCan(['read_all_bookings', 'manage_all_bookings']);
    }
}
