<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Models\Availability;
use FluentBooking\App\Services\Helper;
use FluentBooking\Framework\Support\Arr;

class CalendarService
{
    public static function createCalendar($data, $useCurrentUser = false, $isFileInput = false)
    {
        $calendarData = self::prepareCalendarData($data, $useCurrentUser, $isFileInput);

        if (is_wp_error($calendarData)) {
            return new \WP_Error($calendarData->get_error_code(), $calendarData->get_error_message());
        }

        $calendarData['slug'] = sanitize_title($calendarData['title']);

        if (!Helper::isCalendarSlugAvailable($calendarData['slug'], true)) {
            $calendarData['slug'] .= '-' . time();
        }

        $calendar = Calendar::create($calendarData);

        $calendarMetas = self::prepareCalendarMetas(Arr::get($data, 'metas', []));

        $calendar->metas()->createMany($calendarMetas);

        $availabilitiesData = Arr::get($data, 'availabilities', []);

        $createdAvailabilities = self::createAvailabilities($calendar, $availabilitiesData);

        $eventsData = Arr::get($data, 'events', []);

        self::createCalendarEvents($calendar, $eventsData, $createdAvailabilities);

        do_action('fluent_booking/after_create_calendar', $calendar);

        return [
            'calendar' => $calendar
        ];
    }

    public static function createAvailabilities($calendar, $availabilitiesData)
    {
        $createdAvailabilities = [];

        foreach ($availabilitiesData as $existingId => $availabilityData) {
            $availability = Arr::only($availabilityData, ['key', 'value']);
            $availability['value']['timezone'] = $calendar->author_timezone;
            $availability['object_id'] = $calendar->user_id;
            $availabilityModel = Availability::create($availability);
            $createdAvailabilities[$existingId] = $availabilityModel->id;
        }

        return $createdAvailabilities;
    }

    public static function createCalendarEvents($calendar, $eventsData, $availabilities = [])
    {
        $defaultEventData = (new CalendarSlot())->getEventDefaultData($calendar);

        if (empty($eventsData)) {
            $eventsData = [$defaultEventData];
        }

        $createEventsData = [];

        $createEventMetasData = [];

        foreach ($eventsData as $eventData) {
            $eventMetas = Arr::get($eventData, 'event_metas', []);

            $eventData = self::prepareEventData($eventData, $calendar, $availabilities);

            $createEventData = wp_parse_args($eventData, $defaultEventData);

            $createEventData['slug'] = Helper::generateSlotSlug((int)$createEventData['duration'] . 'min', $calendar);

            $createEventData['settings'] = wp_parse_args($createEventData['settings'], $defaultEventData['settings']);

            $createEventsData[] = Arr::only($createEventData, (new CalendarSlot())->getFillable());

            $createEventMetasData[] = $eventMetas;
        }

        $createdEvents = $calendar->events()->createMany($createEventsData);

        foreach ($createdEvents as $index => $event) {
            $eventMetasData = Arr::get($createEventMetasData, $index, []);

            $eventMetasData = self::prepareEventMetas($eventMetasData);

            $event->event_metas()->createMany($eventMetasData);
        }

        return $createdEvents;
    }

    protected static function prepareCalendarData($calendarData, $useCurrentUser = false, $isFileInput = false)
    {
        if (!$calendarData) {
            return new \WP_Error('invalid_data', esc_html__('Invalid JSON Data', 'fluent-booking'));
        }

        $preparedData = [
            'title'           => sanitize_text_field(Arr::get($calendarData, 'title')),
            'description'     => sanitize_textarea_field(Arr::get($calendarData, 'description')),
            'user_id'         => intval(Arr::get($calendarData, 'user_id')),
            'status'          => sanitize_text_field(Arr::get($calendarData, 'status', 'active')),
            'type'            => sanitize_text_field(Arr::get($calendarData, 'type', 'simple')),
            'event_type'      => sanitize_text_field(Arr::get($calendarData, 'event_type')),
            'account_type'    => sanitize_text_field(Arr::get($calendarData, 'account_type')),
            'visibility'      => sanitize_text_field(Arr::get($calendarData, 'visibility')),
            'author_timezone' => sanitize_text_field(Arr::get($calendarData, 'author_timezone')),
        ];

        if ($useCurrentUser || !Arr::get($preparedData, 'user_id')) {
            $preparedData['user_id'] = get_current_user_id();
        }

        if (!Arr::get($preparedData, 'author_timezone')) {
            $preparedData['author_timezone'] = wp_timezone_string();
        }

        if (!in_array(Arr::get($preparedData, 'type'), ['simple', 'team', 'event'])) {
            $preparedData['type'] = 'simple';
        }

        $user = get_user_by('ID', $preparedData['user_id']);

        if (!$user) {
            return new \WP_Error('invalid_user', esc_html__('Invalid User ID', 'fluent-booking'));
        }

        $isHostCalendar = $preparedData['type'] == 'simple' ? true : false;

        if ($isHostCalendar || !$preparedData['title']) {
            $preparedData['title'] = is_email($user->user_login) ? explode('@', $user->user_login)[0] : $user->user_login;
        }

        $firstCalendar = Calendar::where('user_id', $preparedData['user_id'])->where('type', 'simple')->first();

        if ($isHostCalendar && $firstCalendar) {
            if ($isFileInput) {
                return new \WP_Error('calendar_exists', esc_html__('The user already have a calendar. Please delete it first to create a new one', 'fluent-booking'));
            }
            return $firstCalendar;
        }

        return $preparedData;
    }

    protected static function prepareCalendarMetas($calendarMetas)
    {
        $preparedCalendarMetas = [];

        foreach ($calendarMetas as $calendarMeta) {
            if (empty($calendarMeta['key']) || empty($calendarMeta['value'])) {
                continue;
            }

            $value = $calendarMeta['value'];

            $preparedCalendarMetas[] = [
                'key'         => sanitize_text_field($calendarMeta['key']),
                'value'       => is_array($value) ? self::sanitize_mapped_data($value) : sanitize_text_field($value),
                'object_type' => sanitize_text_field($calendarMeta['object_type'])
            ];
        }

        return $preparedCalendarMetas;
    }

    protected static function prepareEventData($eventData, $calendar, $availabilities = [])
    {
        $preparedEventData = [
            'title'             => sanitize_text_field(Arr::get($eventData, 'title')),
            'duration'          => (int)Arr::get($eventData, 'duration', 30),
            'description'       => wp_kses_post(Arr::get($eventData, 'description')),
            'type'              => sanitize_text_field(Arr::get($eventData, 'type')),
            'status'            => sanitize_text_field(Arr::get($eventData, 'status', 'active')),
            'color_schema'      => sanitize_text_field(Arr::get($eventData, 'color_schema', '#0099ff')),
            'event_type'        => sanitize_text_field(Arr::get($eventData, 'event_type')),
            'availability_id'   => (int)self::prepareAvailabilityId(Arr::get($eventData, 'availability_id', 0), $availabilities),
            'availability_type' => sanitize_text_field(Arr::get($eventData, 'availability_type')),
            'location_type'     => sanitize_text_field(Arr::get($eventData, 'location_type')),
            'location_settings' => SanitizeService::locationSettings(Arr::get($eventData, 'location_settings', [])),
            'max_book_per_slot' => (int)Arr::get($eventData, 'max_book_per_slot', 1),
            'is_display_spots'  => (bool)Arr::get($eventData, 'is_display_spots', false),
        ];

        if (!empty($eventData['hash'])) {
            $hash = sanitize_text_field($eventData['hash']);
            if (!CalendarSlot::where('hash', $hash)->exists()) {
                $preparedEventData['hash'] = $hash;
            }
        }

        $eventSettings = Arr::get($eventData, 'settings', []);

        if (!$eventSettings) {
            return $preparedEventData;
        }

        $preparedEventData['settings'] = [
            'schedule_type'       => sanitize_text_field(Arr::get($eventSettings, 'schedule_type')),
            'weekly_schedules'    => SanitizeService::weeklySchedules(Arr::get($eventSettings, 'weekly_schedules', []), $calendar->author_timezone, 'UTC', true),
            'date_overrides'      => SanitizeService::slotDateOverrides(Arr::get($eventSettings, 'date_overrides', []), $calendar->author_timezone, 'UTC', null, true),
            'range_type'          => sanitize_text_field(Arr::get($eventSettings, 'range_type')),
            'range_days'          => (int)(Arr::get($eventSettings, 'range_days', 60)) ?: 60,
            'range_date_between'  => SanitizeService::rangeDateBetween(Arr::get($eventSettings, 'range_date_between', ['', ''])),
            'schedule_conditions' => SanitizeService::scheduleConditions(Arr::get($eventSettings, 'schedule_conditions', [])),
            'common_schedule'     => Arr::isTrue($eventSettings, 'common_schedule', false),
            'buffer_time_before'  => sanitize_text_field(Arr::get($eventSettings, 'buffer_time_before', '0')),
            'buffer_time_after'   => sanitize_text_field(Arr::get($eventSettings, 'buffer_time_after', '0')),
            'slot_interval'       => sanitize_text_field(Arr::get($eventSettings, 'slot_interval', '')),
            'team_members'        => array_map('intval', Arr::get($eventSettings, 'team_members', [])),
            'multi_duration'      => [
                'enabled'             => Arr::isTrue($eventSettings, 'multi_duration.enabled'),
                'default_duration'    => Arr::get($eventSettings, 'multi_duration.default_duration', ''),
                'available_durations' => array_map('sanitize_text_field', Arr::get($eventSettings, 'multi_duration.available_durations', []))
            ],
            'booking_frequency'   => [
                'enabled' => Arr::isTrue($eventSettings, 'booking_frequency.enabled'),
                'limits'  => self::sanitize_mapped_data(Arr::get($eventSettings, 'booking_frequency.limits', []))
            ],
            'booking_duration'    => [
                'enabled' => Arr::isTrue($eventSettings, 'booking_duration.enabled'),
                'limits'  => self::sanitize_mapped_data(Arr::get($eventSettings, 'booking_duration.limits', []))
            ],
            'lock_timezone'       => [
                'enabled'  => Arr::isTrue($eventSettings, 'lock_timezone.enabled'),
                'timezone' => sanitize_text_field(Arr::get($eventSettings, 'lock_timezone.timezone'))
            ],
        ];

        return $preparedEventData;
    }

    protected static function prepareEventMetas($eventMetasData)
    {
        $preparedEventMetas = [];

        foreach ($eventMetasData as $eventMeta) {
            if (empty($eventMeta['key']) || empty($eventMeta['value'])) {
                continue;
            }

            $value = $eventMeta['value'];

            if ($eventMeta['key'] == 'email_notification') {
                $value = self::updateNotificationImageUrl($value);
            }

            $preparedEventMetas[] = [
                'key'         => sanitize_text_field($eventMeta['key']),
                'value'       => is_array($value) ? self::sanitize_mapped_data($value) : sanitize_text_field($value),
                'object_type' => sanitize_text_field($eventMeta['object_type'])
            ];
        }

        return $preparedEventMetas;
    }

    protected static function prepareAvailabilityId($availabilityId, $availabilities)
    {
        if ($availabilityId && isset($availabilities[$availabilityId])) {
            return $availabilities[$availabilityId];
        }

        if ($availabilities) {
            $firstKey = array_key_first($availabilities);
            return $availabilities[$firstKey];
        }

        return $availabilityId;
    }

    protected static function updateNotificationImageUrl($notifications)
    {
        $formattedNotifications = [];

        foreach ($notifications as $key => $notification) {
            $emailBody = Arr::get($notification, 'email.body');
            if (!$emailBody) {
                continue;
            }

            $newImageUrl = FLUENT_BOOKING_URL . 'assets/images';
            $pattern = '/(https:\/\/[^"]*?' . preg_quote('assets/images', '/') . ')/';
            $emailBody = preg_replace($pattern, $newImageUrl, $emailBody);
            $notification['email']['body'] = $emailBody;

            $formattedNotifications[$key] = $notification;
        }

        return $formattedNotifications;
    }

    public static function getSlotOptions($calendarId = null, $userId = null)
    {
        $calendarSlots = CalendarSlot::select(['id', 'title'])
            ->when($calendarId, function ($query) use ($calendarId) {
                return $query->where('calendar_id', $calendarId);
            })
            ->when($userId, function ($query) use ($userId) {
                return $query->where('user_id', $userId);
            })
            ->where('status', '!=', 'expired')
            ->latest()
            ->get();

        $options = [];
        foreach ($calendarSlots as $slot) {
            $options[] = [
                'id'    => $slot->id,
                'label' => $slot->title,
            ];
        }
        return apply_filters('fluent_booking/calendar_event_options', $options, $calendarId);
    }

    public static function getCalendarOptionsByHost()
    {
        $calendars = Calendar::select(['id', 'title'])
            ->when(!PermissionManager::hasAllCalendarAccess(true), function ($query) {
                return $query->where('user_id', get_current_user_id());
            })
            ->with(['slots' => function ($query) {
                $query->where('status', '!=', 'expired');
            }])
            ->latest()
            ->get();

        $formattedCalendars = [];
        foreach ($calendars as $index => $calendar) {
            $slots = Arr::get($calendar, 'slots');
            if (!empty($slots)) {
                $options = [];
                foreach ($slots as $slot) {
                    $options[] = [
                        'label' => Arr::get($slot, 'title'),
                        'value' => Arr::get($slot, 'id')
                    ];
                }
                if (!empty($options)) {
                    $formattedCalendars[$index] = [
                        'label'   => Arr::get($calendar, 'title'),
                        'options' => $options
                    ];
                }
            }
        }
        return $formattedCalendars;
    }

    public static function getCalendarOptionsByTitle($condition = '')
    {
        $calendarsQuery = Calendar::select(['id', 'title', 'user_id'])
            ->where('status', '!=', 'expired')
            ->with(['slots' => function ($query) {
                $query->where('status', '!=', 'expired');
            }]);

        switch ($condition) {
            case 'only_hosts':
                $calendarsQuery->where('type', 'simple');
                break;
            case 'only_teams':
                $calendarsQuery->where('type', 'team');
                break;
            case 'only_events':
                $calendarsQuery->where('type', 'event');
                break;
        }

        if (!PermissionManager::hasAllCalendarAccess(true)) {
            $attachedCalendarIds = self::getAttachedCalendarIds($calendarsQuery);
            $calendarsQuery->whereIn('id', $attachedCalendarIds);
        }

        $calendars = $calendarsQuery->latest()->get();

        $formattedCalendars = [];
        foreach ($calendars as $index => $calendar) {
            $slots = Arr::get($calendar, 'slots');
            if (!empty($slots)) {
                $options = [];
                foreach ($slots as $slot) {
                    $options[] = [
                        'id'    => Arr::get($slot, 'id'),
                        'title' => Arr::get($slot, 'title')
                    ];
                }
                if (!empty($options)) {
                    $formattedCalendars[$index] = [
                        'id'      => Arr::get($calendar, 'id'),
                        'title'   => Arr::get($calendar, 'title'),
                        'options' => $options
                    ];
                }
            }
        }
        return apply_filters('fluent_booking/calendar_options_by_title', $formattedCalendars);
    }

    public static function getAttachedCalendarIds($calendarsQuery)
    {
        $userId = get_current_user_id();

        $calendars = $calendarsQuery->get();

        $calendarIds = [];
        foreach ($calendars as $calendar) {
            if ($calendar->user_id == $userId) {
                $calendarIds[] = $calendar->id;
                continue;
            }

            $events = Arr::get($calendar, 'slots', []);
            foreach ($events as $event) {
                $teamMembers = Arr::get($event, 'settings.team_members', []);
                if (in_array($userId, $teamMembers)) {
                    $calendarIds[] = $calendar->id;
                }
            }
        }

        return $calendarIds;
    }

    public static function isSharedCalendar($calendar)
    {
        $calendarEvents = $calendar->events;

        $userId = get_current_user_id();

        foreach ($calendarEvents as $event) {
            if ($event->user_id == $userId) {
                return true;
            }
            $teamMembers = Arr::get($event, 'settings.team_members', []);
            if (in_array($userId, $teamMembers)) {
                return true;
            }
        }

        return false;
    }

    public static function updateCalendarEventsSchedule($calendarId, $oldTimezone, $updatedTimezone)
    {
        $calendarEvents = CalendarSlot::query()->where('calendar_id', $calendarId)->get();

        foreach ($calendarEvents as $event) {
            if ($weeklySchedule = Arr::get($event->settings, 'weekly_schedules', [])) {
                $originalSchedule = SanitizeService::weeklySchedules($weeklySchedule, 'UTC', $oldTimezone);
                $weeklySchedule = SanitizeService::weeklySchedules($originalSchedule, $updatedTimezone, 'UTC', true);
            }

            if ($dateOverride = Arr::get($event->settings, 'date_overrides', [])) {
                $originalOverride = SanitizeService::slotDateOverrides($dateOverride, 'UTC', $oldTimezone);
                $dateOverride = SanitizeService::slotDateOverrides($originalOverride, $updatedTimezone, 'UTC', null, true);
            }

            $event->settings = [
                'weekly_schedules' => $weeklySchedule,
                'date_overrides'   => $dateOverride
            ];

            $event->save();
        }
    }

    private static function sanitize_mapped_data($settings)
    {
        $sanitizerMap = [
            'value'                 => 'intval',
            'unit'                  => 'sanitize_text_field',
            'subject'               => 'sanitize_text_field',
            'body'                  => 'fcal_sanitize_html',
            'additional_recipients' => 'sanitize_text_field'
        ];

        return Helper::fcal_backend_sanitizer($settings, $sanitizerMap);
    }
}
