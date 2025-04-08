<?php

namespace FluentBooking\App\Http\Controllers;

use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Services\CurrenciesHelper;
use FluentBooking\App\Services\LandingPage\LandingPageHelper;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\App\Services\AvailabilityService;
use FluentBooking\App\Services\SanitizeService;
use FluentBooking\App\Services\CalendarService;
use FluentBooking\App\Services\CalendarEventService;
use FluentBooking\App\Services\BookingFieldService;
use FluentBooking\App\Hooks\Handlers\AdminMenuHandler;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\Framework\Support\Arr;

class CalendarController extends Controller
{
    public function getAllCalendars(Request $request)
    {
        do_action('fluent_booking/before_get_all_calendars', $request);

        $search = sanitize_text_field(Arr::get($request->get('query'), 'search'));
        $calendarType = sanitize_text_field(Arr::get($request->get('query'), 'calendarType'));

        $applySearchFilter = function($query) use ($search) {
            $query->where('status', '!=', 'expired');
            if (!empty($search)) {
                $query->where('title', 'LIKE', '%' . $search . '%');
            }
        };

        $calendarsQuery = Calendar::with(['slots' => function($query) use ($applySearchFilter) {
            $query->where($applySearchFilter);
        }])
        ->where('status', '!=', 'expired');

        if (!empty($search)) {
            $calendarsQuery->whereHas('slots', $applySearchFilter);
        }

        if (!empty($calendarType) && $calendarType != 'all') {
            $calendarsQuery->where('type', $calendarType);
        }

        $calendarsQuery = $calendarsQuery->latest();

        $hasPermission = PermissionManager::hasAllCalendarAccess(true);

        if (!$hasPermission) {
            $attachedCalendarIds = CalendarService::getAttachedCalendarIds($calendarsQuery);
            $calendarsQuery->whereIn('id', $attachedCalendarIds);
        }

        $calendars = $calendarsQuery->paginate();

        foreach ($calendars as $calendar) {
            $calendar->author_profile = $calendar->getAuthorProfile();
            $calendar->public_url = $calendar->getLandingPageUrl();
            $calendar->event_order = $calendar->getMeta('event_order');
            foreach ($calendar->slots as $key => $slot) {
                if (!$hasPermission && !CalendarEventService::isSharedCalendarEvent($slot)) {
                    unset($calendar->slots[$key]);
                }
                $slot->shortcode = '[fluent_booking id="' . $slot->id . '"]';
                $slot->public_url = $slot->getPublicUrl();
                $slot->duration = $slot->getDefaultDuration();
                $slot->price_total = $slot->getPricingTotal();
                $slot->location_fields = $slot->getLocationFields();
                $slot->author_profiles = $slot->isMultiHostEvent() ? $slot->getAuthorProfiles() : [];
                do_action_ref_array('fluent_booking/calendar_slot', [&$slot]);
            }

            if(empty($calendar->author_profile['ID'])) {
                $calendar->generic_error = '<p style="color: red; margin:0;">Connected Host user is missing</p>';
            }

            do_action_ref_array('fluent_booking/calendar', [&$calendar, 'lists']);
        }

        $data = [
            'calendars' => $calendars
        ];

        if (in_array('calendar_event_lists', $request->get('with', []))) {
            $data['calendar_event_lists'] = [
                'hosts'  => CalendarService::getCalendarOptionsByTitle('only_hosts'),
                'teams'  => CalendarService::getCalendarOptionsByTitle('only_teams'),
                'events' => CalendarService::getCalendarOptionsByTitle('only_events')
            ];
        }

        return $data;
    }

    public function checkSlug(Request $request)
    {
        $slug = sanitize_text_field(trim($request->get('slug')));

        if (!Helper::isCalendarSlugAvailable($slug, true)) {
            return $this->sendError([
                'message' => __('The provided slug is not available. Please choose a different one', 'fluent-booking')
            ], 422);
        }

        return [
            'status' => true
        ];
    }

    public function createCalendar(Request $request)
    {
        $data = $request->get('calendar');

        $rules = [
            'author_timezone'                            => 'required',
            'slot.duration'                              => 'required|numeric|min:5',
            'slot.event_type'                            => 'required',
            'slot.availability_type'                     => 'required',
            'slot.schedule_type'                         => 'required',
            'slot.title'                                 => 'required',
            'slot.weekly_schedules'                      => 'required_if:slot.schedule_type,weekly_schedules',
            'slot.location_settings.*.type'              => 'required',
            'slot.location_settings.*.host_phone_number' => 'required_if:location_settings.*.type,phone_organizer'
        ];

        $messages = [
            'author_timezone.required'                               => __('Author timezone field is required', 'fluent-booking'),
            'slot.duration.required'                                 => __('Event duration field is required', 'fluent-booking'),
            'slot.event_type.required'                               => __('Event type field is required', 'fluent-booking'),
            'slot.availability_type.required'                        => __('Event availability type field is required', 'fluent-booking'),
            'slot.schedule_type.required'                            => __('Event schedule type field is required', 'fluent-booking'),
            'slot.title.required'                                    => __('Event title field is required', 'fluent-booking'),
            'slot.weekly_schedules.required_if'                      => __('Event weekly schedules field is required', 'fluent-booking'),
            'slot.location_settings.*.type.required'                 => __('Event location type field is required', 'fluent-booking'),
            'slot.location_settings.*.host_phone_number.required_if' => __('Event location host phone number field is required', 'fluent-booking')
        ];

        $validationConfig = apply_filters('fluent_booking/create_calendar_validation_rule', [
            'rules'    => $rules,
            'messages' => $messages
        ], $data);

        $this->validate($data, $validationConfig['rules'], $validationConfig['messages']);

        do_action('fluent_booking/before_create_calendar', $data, $this);

        if (!empty($data['user_id']) && PermissionManager::userCan('invite_team_members')) {
            $user = get_user_by('ID', $data['user_id']);
        } else {
            $user = get_user_by('ID', get_current_user_id());
        }

        if (!$user) {
            return $this->sendError([
                'message' => __('User not found', 'fluent-booking')
            ], 422);
        }

        $type = sanitize_text_field(Arr::get($data, 'type', 'simple'));

        $isHostCalendar = $type == 'simple' ? true : false;

        if ($isHostCalendar && Calendar::where('user_id', $user->ID)->where('type', 'simple')->first()) {
            return $this->sendError([
                'message' => __('The user already have a calendar. Please delete it first to create a new one', 'fluent-booking')
            ], 422);
        }

        if ($isHostCalendar) {
            $userName = $user->user_login;
            if (is_email($userName)) {
                $userName = explode('@', $userName);
                $userName = $userName[0] . '-' . time();
            }
            $data['slug'] = sanitize_title($userName, '', 'display');
        }
        
        $slot = $data['slot'];

        if (!$isHostCalendar) {
            $title = sanitize_text_field(Arr::get($data, 'title', ''));
            $data['slug'] = sanitize_title($title, '', 'display');
            $teamMembers = array_map('intval', Arr::get($slot, 'settings.team_members', []));
            if (!in_array($user->ID, $teamMembers)) {
                $user = get_user_by('ID', reset($teamMembers));
                if (!$user) {
                    return $this->sendError([
                        'message' => __('Invalid Team Member', 'fluent-booking')
                    ], 422);
                }
            }
        }

        if (!Helper::isCalendarSlugAvailable($data['slug'], true)) {
            $data['slug'] .= '-' . time();
        }

        if (!empty($data['slug'])) {
            $slug = trim(sanitize_text_field($data['slug']));
            if (!Helper::isCalendarSlugAvailable($slug, true)) {
                return $this->sendError([
                    'message' => __('The provided slug is not available. Please choose a different one', 'fluent-booking')
                ], 422);
            }

            $personName = trim($user->first_name . ' ' . $user->last_name);
            if (!$personName) {
                $personName = $user->display_name;
            }

            $calendarData = [
                'slug'            => $slug,
                'user_id'         => $user->ID,
                'title'           => $isHostCalendar ? $personName : $title,
                'type'            => $type,
                'author_timezone' => sanitize_text_field($data['author_timezone']) ?: 'UTC',
            ];
            $calendar = Calendar::create($calendarData);
        } else {
            $calendar = Calendar::where('user_id', $user->ID)->where('type', 'simple')->first();
        }

        if (!$calendar) {
            return $this->sendError([
                'message' => __('Calendar could not be found. Please try again', 'fluent-booking')
            ], 422);
        }

        $weeklySchedule = Arr::get($data, 'slot.weekly_schedules', []);

        $availability = AvailabilityService::maybeCreateAvailability($calendar, $weeklySchedule);

        $title = (!empty($slot['title'])) ? sanitize_text_field($slot['title']) : $slot['duration'] . ' Minute Meeting';

        $slotData = [
            'title'             => $title,
            'slug'              => Helper::generateSlotSlug((int)$slot['duration'] . 'min', $calendar),
            'calendar_id'       => $calendar->id,
            'user_id'           => $calendar->user_id,
            'duration'          => (int)$slot['duration'],
            'description'       => wp_kses_post(Arr::get($slot, 'description')),
            'settings'          => [
                'team_members'     => !$isHostCalendar ? $teamMembers : [],
                'schedule_type'    => sanitize_text_field($slot['schedule_type']),
                'weekly_schedules' => SanitizeService::weeklySchedules($slot['weekly_schedules'], $calendar->author_timezone, 'UTC')
            ],
            'status'            => SanitizeService::checkCollection($slot['status'], ['active', 'draft']),
            'color_schema'      => sanitize_text_field(Arr::get($slot, 'color_schema', '#0099ff')),
            'event_type'        => sanitize_text_field(Arr::get($slot, 'event_type')),
            'availability_type' => SanitizeService::checkCollection($slot['availability_type'], ['existing_schedule', 'custom'], 'existing_schedule'),
            'availability_id'   => (int)$availability->id,
            'location_type'     => sanitize_text_field(Arr::get($slot, 'location_type')),
            'location_heading'  => wp_kses_post(Arr::get($slot, 'location_heading')),
            'location_settings' => SanitizeService::locationSettings(Arr::get($slot, 'location_settings', [])),
        ];

        $slotData['settings'] = wp_parse_args($slotData['settings'], (new CalendarSlot())->getSlotSettingsSchema());

        $slotData = apply_filters('fluent_booking/create_calendar_event_data', $slotData, $calendar);

        $slot = CalendarSlot::create($slotData);

        do_action('fluent_booking/after_create_calendar', $calendar);

        do_action('fluent_booking/after_create_calendar_slot', $slot, $calendar);

        return [
            'calendar'     => $calendar,
            'slot'         => $slot,
            'redirect_url' => Helper::getAppBaseUrl('calendars/' . $calendar->id . '/slot-settings/' . $slot->id)
        ];
    }

    public function getCalendar(Request $request, $calendarId)
    {
        $calendar = Calendar::with(['slots' => function ($query) {
            $query->where('status', '!=', 'expired');
        }])->findOrFail($calendarId);

        $calendar->author_profile = $calendar->getAuthorProfile();

        $data = [
            'calendar' => $calendar
        ];

        if (in_array('settings_menu', $request->get('with', []))) {
            $data['settings_menu'] = AdminMenuHandler::getCalendarSettingsMenuItems($calendar);
        }

        return $data;
    }

    public function getSharingSettings(Request $request, $calendarId)
    {
        $calendar = Calendar::findOrFail($calendarId);

        return [
            'settings'  => LandingPageHelper::getSettings($calendar),
            'share_url' => $calendar->getLandingPageUrl(true)
        ];
    }

    public function saveSharingSettings(Request $request, $calendarId)
    {
        $calendar = Calendar::findOrFail($calendarId);

        $calendarDataItems = Arr::only($request->get('calendar_data', []), ['title', 'timezone', 'description', 'calendar_avatar', 'featured_image', 'phone']);

        if ($calendarDataItems) {
            $this->validate($calendarDataItems, [
                'title'           => 'required',
                'calendar_avatar' => 'nullable|url',
                'featured_image'  => 'nullable|url'
            ]);

            $updatedTimezone = sanitize_text_field(Arr::get($calendarDataItems, 'timezone'));
            if ($updatedTimezone && $updatedTimezone != $calendar->author_timezone) {
                CalendarService::updateCalendarEventsSchedule($calendarId, $calendar->author_timezone, $updatedTimezone);
                $calendar->author_timezone = $updatedTimezone;
            }

            $calendar->title = sanitize_text_field(Arr::get($calendarDataItems, 'title'));
            $calendar->description = wp_kses_post(Arr::get($calendarDataItems, 'description'));
            $calendar->updateMeta('profile_photo_url', sanitize_url(Arr::get($calendarDataItems, 'calendar_avatar')));
            $calendar->updateMeta('featured_image_url', sanitize_url(Arr::get($calendarDataItems, 'featured_image')));
            $calendar->save();

            if ($calendar->user) {
                $calendar->user->updateMeta('host_phone', sanitize_text_field(Arr::get($calendarDataItems, 'phone')));
            }
        }

        $sharingSettings = $request->get('landing_page_settings', []);
        LandingPageHelper::updateSettings($calendar, $sharingSettings);

        return [
            'message' => __('Landing Page settings has been updated', 'fluent-booking')
        ];
    }

    public function updateCalendar(Request $request, $calendarId)
    {
        $data = $request->all();

        $calendar = Calendar::findOrFail($calendarId);

        do_action_ref_array('fluent_booking/before_update_calendar', [&$calendar, $data]);

        $calendar->description = wp_kses_post($request->get('description'));
        $calendar->save();
        do_action('fluent_booking/after_update_calendar', $calendar, $data);

        $calendar->author_profile = $calendar->getAuthorProfile();

        do_action_ref_array('fluent_booking/calendar', [&$calendar, 'update']);

        return [
            'calendar' => $calendar,
            'message'  => __('Calendar has been updated successfully', 'fluent-booking')
        ];
    }

    public function getEvent(Request $request, $calendarId, $eventId)
    {
        $calendarEvent = CalendarSlot::where('calendar_id', $calendarId)->with(['calendar.user'])->findOrFail($eventId);

        $calendarEvent->author_profile = $calendarEvent->getAuthorProfile();

        $calendarEvent->calendar->author_profile = $calendarEvent->calendar->getAuthorProfile();

        $calendarEvent->public_url = $calendarEvent->getPublicUrl();

        $eventSettings = $calendarEvent->settings;

        $eventSettings['weekly_schedules'] = SanitizeService::weeklySchedules($eventSettings['weekly_schedules'], 'UTC', $calendarEvent->calendar->author_timezone);

        $eventSettings['date_overrides'] = (object)SanitizeService::slotDateOverrides(Arr::get($eventSettings, 'date_overrides', []), 'UTC', $calendarEvent->calendar->author_timezone, $calendarEvent);

        $eventSettings['location_fields'] = $calendarEvent->getLocationFields();

        $eventSettings['hosts_schedules'] = $calendarEvent->getHostsSchedules();

        $calendarEvent->settings = apply_filters('fluent_booking/get_calendar_event_settings', $eventSettings, $calendarEvent, $calendarEvent->calendar);

        $data = [
            'calendar_event' => $calendarEvent
        ];

        if (in_array('calendar', $this->request->get('with', []))) {
            $calendar = $calendarEvent->calendar;
            $calendar->author_profile = $calendar->getAuthorProfile();
            $data['calendar'] = $calendar;
        }

        if (in_array('smart_codes', $this->request->get('with', []))) {
            $data['smart_codes'] = [
                'texts' => Helper::getEditorShortCodes($calendarEvent),
                'html'  => Helper::getEditorShortCodes($calendarEvent, true)
            ];
        }

        if (in_array('settings_menu', $this->request->get('with', []))) {
            $data['settings_menu'] = AdminMenuHandler::getEventSettingsMenuItems($calendarEvent);
        }

        if (in_array('calendar_event_lists', $this->request->get('with', []))) {
            $data['calendar_event_lists'] = CalendarService::getCalendarOptionsByTitle();
        }

        return $data;
    }

    public function getEventSchema(Request $request, $calendarId)
    {
        $calendar = Calendar::findOrFail($calendarId);

        $schema = (new CalendarSlot())->getEventSchema($calendar);

        return [
            'slot' => $schema
        ];
    }

    public function getAvailabilitySettings(Request $request, $calendarId, $eventId)
    {
        $availableSchedules = AvailabilityService::availabilitySchedules();

        $scheduleOptions = AvailabilityService::getScheduleOptions();

        return [
            'schedule_options'    => $scheduleOptions,
            'available_schedules' => $availableSchedules
        ];
    }

    public function createCalendarEvent(Request $request, $calendarId)
    {
        $calendar = Calendar::findOrFail($calendarId);

        $slot = $request->all();

        $rules = [
            'title'                                 => 'required',
            'duration'                              => 'required|numeric|min:5',
            'status'                                => 'required',
            'event_type'                            => 'required',
            'location_settings.*.type'              => 'required',
            'location_settings.*.title'             => 'required_if:location_settings.*.type,custom',
            'location_settings.*.description'       => 'required_if:location_settings.*.type,address_organizer',
            'location_settings.*.host_phone_number' => 'required_if:location_settings.*.type,phone_organizer'
        ];

        $messages = [
            'title.required'                                    => __('Event title field is required', 'fluent-booking'),
            'duration.required'                                 => __('Event duration field is required', 'fluent-booking'),
            'status.required'                                   => __('Event status field is required', 'fluent-booking'),
            'event_type.required'                               => __('Event type field is required', 'fluent-booking'),
            'location_settings.*.type.required'                 => __('Event location type field is required', 'fluent-booking'),
            'location_settings.*.title.required_if'             => __('Event location title field is required', 'fluent-booking'),
            'location_settings.*.description.required_if'       => __('Event location description field is required', 'fluent-booking'),
            'location_settings.*.host_phone_number.required_if' => __('Event location host phone number field is required', 'fluent-booking')
        ];

        $validationConfig = apply_filters('fluent_booking/create_calendar_event_validation_rule', [
            'rules'    => $rules,
            'messages' => $messages
        ], $slot);

        $this->validate($slot, $validationConfig['rules'], $validationConfig['messages']);

        $availability = AvailabilityService::getDefaultSchedule($calendar->user_id);

        $slotData = [
            'title'             => $slot['title'],
            'slug'              => Helper::generateSlotSlug($slot['duration'] . 'min', $calendar),
            'calendar_id'       => $calendar->id,
            'user_id'           => $calendar->user_id,
            'duration'          => (int)$slot['duration'],
            'description'       => wp_kses_post(Arr::get($slot, 'description')),
            'settings'          => [
                'schedule_type'       => sanitize_text_field($slot['settings']['schedule_type']),
                'weekly_schedules'    => SanitizeService::weeklySchedules($slot['settings']['weekly_schedules'], $calendar->author_timezone, 'UTC'),
                'date_overrides'      => SanitizeService::slotDateOverrides(Arr::get($slot['settings'], 'date_overrides', []), $calendar->author_timezone, 'UTC'),
                'range_type'          => sanitize_text_field(Arr::get($slot['settings'], 'range_type')),
                'range_days'          => (int)(Arr::get($slot['settings'], 'range_days', 60)) ?: 60,
                'range_date_between'  => SanitizeService::rangeDateBetween(Arr::get($slot['settings'], 'range_date_between', ['', ''])),
                'schedule_conditions' => SanitizeService::scheduleConditions(Arr::get($slot['settings'], 'schedule_conditions', [])),
                'buffer_time_before'  => sanitize_text_field(Arr::get($slot['settings'], 'buffer_time_before', '0')),
                'buffer_time_after'   => sanitize_text_field(Arr::get($slot['settings'], 'buffer_time_after', '0')),
                'slot_interval'       => sanitize_text_field(Arr::get($slot['settings'], 'slot_interval', '')),
                'team_members'        => array_map('intval', Arr::get($slot['settings'], 'team_members', []))
            ],
            'status'            => SanitizeService::checkCollection($slot['status'], ['active', 'draft'], 'active'),
            'color_schema'      => sanitize_text_field(Arr::get($slot, 'color_schema', '#0099ff')),
            'event_type'        => sanitize_text_field(Arr::get($slot, 'event_type')),
            'availability_type' => 'existing_schedule',
            'availability_id'   => $availability ? $availability->id : null,
            'location_type'     => sanitize_text_field(Arr::get($slot, 'location_type')),
            'location_settings' => SanitizeService::locationSettings(Arr::get($slot, 'location_settings', [])),
            'max_book_per_slot' => (int)Arr::get($slot, 'max_book_per_slot', 1),
            'is_display_spots'  => (bool)Arr::get($slot, 'is_display_spots', false),
        ];

        $slotData = apply_filters('fluent_booking/create_calendar_event_data', $slotData, $calendar);

        do_action('fluent_booking/before_create_event', $calendar, $slotData);

        $createdSlot = CalendarSlot::create($slotData);

        do_action('fluent_booking/after_create_event', $calendar, $createdSlot);

        $calendar->updateEventOrder($createdSlot->id);

        return [
            'message' => __('New Event Type has been created successfully', 'fluent-booking'),
            'slot'    => $createdSlot
        ];
    }

    public function updateEventDetails(Request $request, $calendarId, $eventId)
    {
        $data = $request->all();

        $event = CalendarSlot::where('calendar_id', $calendarId)->findOrFail($eventId);

        $rules = [
            'title'                                 => 'required',
            'duration'                              => 'required|numeric',
            'location_settings.*.type'              => 'required',
            'location_settings.*.title'             => 'required_if:location_settings.*.type,in_person_organizer',
            'location_settings.*.host_phone_number' => 'required_if:location_settings.*.type,phone_organizer'
        ];

        $messages = [
            'title.required'                                    => __('Event title field is required', 'fluent-booking'),
            'duration.required'                                 => __('Event duration field is required', 'fluent-booking'),
            'location_settings.*.type.required'                 => __('Event location type field is required', 'fluent-booking'),
            'location_settings.*.title.required_if'             => __('Event location title field is required', 'fluent-booking'),
            'location_settings.*.host_phone_number.required_if' => __('Event location host phone number field is required', 'fluent-booking')
        ];

        if ('group' === $event->event_type) {
            $rules = array_merge($rules, [
                'max_book_per_slot' => 'required|numeric|min:1',
                'is_display_spots'  => 'required|min:0|max:1',
            ]);
            $messages = array_merge($messages, [
                'max_book_per_slot.required' => __('Event max book per slot field is required', 'fluent-booking'),
                'is_display_spots.required'  => __('Event is display spots field is required', 'fluent-booking')
            ]);
        } else {
            $rules = array_merge($rules, [
                'multi_duration.default_duration'    => 'required_if:multi_duration.enabled,true',
                'multi_duration.available_durations' => 'required_if:multi_duration.enabled,true'
            ]);
            $messages = array_merge($messages, [
                'multi_duration.default_duration.required_if'    => __('Event default duration is required', 'fluent-booking'),
                'multi_duration.available_durations.required_if' => __('Event available durations is required', 'fluent-booking')
            ]);
        }

        $validationConfig = apply_filters('fluent_booking/update_event_details_validation_rule', [
            'rules'    => $rules,
            'messages' => $messages
        ], $event);

        $this->validate($data, $validationConfig['rules'], $validationConfig['messages']);

        $event->title = sanitize_text_field($data['title']);
        $event->duration = (int)$data['duration'];
        $event->status = SanitizeService::checkCollection($data['status'], ['active', 'draft']);
        $event->color_schema = sanitize_text_field(Arr::get($data, 'color_schema', '#0099ff'));
        $event->description = wp_kses_post(Arr::get($data, 'description'));
        $event->max_book_per_slot = (int)Arr::get($data, 'max_book_per_slot');
        $event->is_display_spots = (bool)Arr::get($data, 'is_display_spots');
        $event->location_settings = SanitizeService::locationSettings(Arr::get($data, 'location_settings', []));

        $event->settings = [
            'multi_duration'  => [
                'enabled'             => Arr::isTrue($data, 'multi_duration.enabled'),
                'default_duration'    => Arr::get($data, 'multi_duration.default_duration', ''),
                'available_durations' => array_map('sanitize_text_field', Arr::get($data, 'multi_duration.available_durations', []))
            ]
        ];

        $event->save();

        do_action('fluent_booking/after_update_event_details', $event);

        return [
            'message' => __('Data has been updated', 'fluent-booking'),
            'event'   => $event
        ];
    }

    public function updateEventAvailability(Request $request, $calendarId, $eventId)
    {
        $data = $request->all();

        $event = CalendarSlot::where('calendar_id', $calendarId)->findOrFail($eventId);

        $eventSettings = [
            'schedule_type'      => sanitize_text_field(Arr::get($data, 'schedule_type')),
            'weekly_schedules'   => SanitizeService::weeklySchedules(Arr::get($data, 'weekly_schedules'), $event->calendar->author_timezone, 'UTC'),
            'date_overrides'     => SanitizeService::slotDateOverrides(Arr::get($data, 'date_overrides', []), $event->calendar->author_timezone, 'UTC'),
            'range_type'         => sanitize_text_field(Arr::get($data, 'range_type')),
            'range_days'         => (int)(Arr::get($data, 'range_days', 60)) ?: 60,
            'range_date_between' => SanitizeService::rangeDateBetween(Arr::get($data, 'range_date_between', ['', ''])),
            'common_schedule'    => Arr::isTrue($data, 'common_schedule', false)
        ];

        if ($event->isTeamEvent()) {
            $eventSettings['hosts_schedules'] = array_map('intval', array_combine(
                array_map('intval', array_keys(Arr::get($data, 'hosts_schedules', []))),
                array_map('intval', Arr::get($data, 'hosts_schedules', []))
            ));
        }

        $event->settings = $eventSettings;

        $event->availability_id = (int)Arr::get($data, 'availability_id');
        $event->availability_type = SanitizeService::checkCollection(Arr::get($data, 'availability_type'), ['existing_schedule', 'custom']);

        $event->save();

        return [
            'message' => __('Data has been updated', 'fluent-booking'),
            'event'   => $event
        ];
    }

    public function updateEventLimits(Request $request, $calendarId, $eventId)
    {
        $data = $request->all();

        $event = CalendarSlot::where('calendar_id', $calendarId)->findOrFail($eventId);

        $event->settings = [
            'schedule_conditions'   => SanitizeService::scheduleConditions(Arr::get($data['settings'], 'schedule_conditions', [])),
            'buffer_time_before'    => sanitize_text_field(Arr::get($data, 'settings.buffer_time_before', '0')),
            'buffer_time_after'     => sanitize_text_field(Arr::get($data, 'settings.buffer_time_after', '0')),
            'slot_interval'         => sanitize_text_field(Arr::get($data, 'settings.slot_interval', '')),
            'booking_frequency'     => [
                'enabled' => Arr::isTrue($data, 'settings.booking_frequency.enabled'),
                'limits'  => $this->sanitize_mapped_data(Arr::get($data, 'settings.booking_frequency.limits'))
            ],
            'booking_duration'      => [
                'enabled' => Arr::isTrue($data, 'settings.booking_duration.enabled'),
                'limits'  => $this->sanitize_mapped_data(Arr::get($data, 'settings.booking_duration.limits'))
            ],
            'lock_timezone'         => [
                'enabled'  => Arr::isTrue($data, 'settings.lock_timezone.enabled'),
                'timezone' => sanitize_text_field(Arr::get($data, 'settings.lock_timezone.timezone'))
            ],
        ];

        $event->save();

        return [
            'message' => __('Data has been updated', 'fluent-booking'),
            'event'   => $event
        ];
    }

    public function patchCalendarEvent(Request $request, $calendarId, $eventId)
    {
        $slot = CalendarSlot::where('calendar_id', $calendarId)->findOrFail($eventId);

        $status = $request->get('status');

        if ($status) {
            $slot->status = $status;
            $slot->save();
        }

        return [
            'message' => __('Data has been updated', 'fluent-booking')
        ];

    }

    public function cloneCalendarEvent(Request $request, $calendarId, $eventId)
    {
        $newCalendarId = intval($request->get('new_calendar_id')) ?: $calendarId;

        $calendar = Calendar::findOrFail($newCalendarId);

        $originalEvent = CalendarSlot::with('event_metas')->where('calendar_id', $calendarId)->findOrFail($eventId);

        $clonedEvent = $originalEvent->replicate();

        $clonedEvent->hash = null;

        $clonedEvent->calendar_id = $calendar->id;

        $clonedEvent->user_id = $calendar->user_id;

        $clonedEvent->title = $originalEvent->title . ' (clone)';

        $clonedEvent->slug = Helper::generateSlotSlug($clonedEvent->duration . 'min', $calendar);

        $clonedEvent->save();

        $calendar->updateEventOrder($clonedEvent->id);

        $eventsMeta = $originalEvent->event_metas;

        foreach ($eventsMeta as $meta) {
            $clonedMeta = $meta->replicate();
            $clonedMeta->object_id = $clonedEvent->id;
            $clonedMeta->save();
        }

        return [
            'slot'    => $clonedEvent,
            'message' => __('The Event Type has been cloned successfully', 'fluent-booking')
        ];
    }

    public function saveCalendarEventOrder(Request $request, $calendarId)
    {
        $calendar = Calendar::findOrFail($calendarId);

        $eventOrder = array_map('intval', $request->get('event_order', []));

        $calendar->updateMeta('event_order', array_filter($eventOrder));

        return [
            'calendar' => $calendar,
            'message' => __('Event order has been updated', 'fluent-booking')
        ];
    }

    public function cloneEventEmailNotification(Request $request, $calendarId, $eventId)
    {
        $calendarEvent = CalendarSlot::where('calendar_id', $calendarId)->findOrFail($eventId);

        $fromEventId = intval($request->get('from_event_id'));

        $fromCalendarEvent = CalendarSlot::findOrFail($fromEventId);

        $notification = $fromCalendarEvent->getNotifications(true);

        $calendarEvent->setNotifications($notification);

        $calendarEvent->save();

        return [
            'message'       => __('The Notification has been cloned successfully', 'fluent-booking'),
            'notifications' => $notification
        ];
    }

    public function getEventEmailNotifications(Request $request, $calendarId, $eventId)
    {
        $calendarEvent = CalendarSlot::where('calendar_id', $calendarId)->findOrFail($eventId);

        /*
         * Confirmation Email to Attendee
         * Confirmation Email to Organizer
         * Reminder Email to Attendee [before 1 day, 1 hour, 30 minutes, 5 minutes]
         * Cancelled By Organizer to Attendee
         * Cancelled By Attendee to Organizer
         */
        $data = [
            'notifications' => $calendarEvent->getNotifications(true)
        ];

        if (in_array('smart_codes', $request->get('with', []))) {
            $data['smart_codes'] = [
                'texts' => Helper::getEditorShortCodes($calendarEvent),
                'html'  => Helper::getEditorShortCodes($calendarEvent, true)
            ];
        }

        return $data;
    }

    public function saveEventEmailNotifications(Request $request, $calendarId, $eventId)
    {
        $slot = CalendarSlot::where('calendar_id', $calendarId)->findOrFail($eventId);

        $notifications = $request->get('notifications', []);

        $formattedNotifications = [];

        foreach ($notifications as $key => $value) {
            $formattedNotifications[$key] = [
                'title'   => sanitize_text_field(Arr::get($value, 'title')),
                'enabled' => Arr::isTrue($value, 'enabled'),
                'email'   => $this->sanitize_mapped_data(Arr::get($value, 'email')),
                'is_host' => Arr::isTrue($value, 'is_host')
            ];
        }

        $slot->setNotifications($formattedNotifications);

        return [
            'message' => __('Notifications has been saved', 'fluent-booking')
        ];
    }

    public function getEventBookingFields(Request $request, $calendarId, $eventId)
    {
        $calendarEvent = CalendarSlot::where('calendar_id', $calendarId)->findOrFail($eventId);

        $data = [
            'fields' => $calendarEvent->getBookingFields()
        ];

        if (in_array('smart_codes', $request->get('with', []))) {
            $data['smart_codes'] = [
                'texts' => Helper::getEditorShortCodes($calendarEvent),
                'html'  => Helper::getEditorShortCodes($calendarEvent, true)
            ];
        }

        return $data;
    }

    public function saveEventBookingFields(Request $request, $calendarId, $eventId)
    {
        $calendarEvent = CalendarSlot::where('calendar_id', $calendarId)->findOrFail($eventId);

        $bookingFields = $request->get('booking_fields');

        $optionRequiredFields = ['dropdown', 'radio', 'checkbox-group', 'multi-select'];

        $formattedFields = [];

        $textFields = ['type', 'name', 'label', 'placeholder', 'limit', 'help_text', 'date_format', 'min_date', 'max_date'];
        $booleanFields = ['enabled', 'required', 'system_defined', 'disable_alter', 'is_sms_number'];

        foreach ($bookingFields as $value) {
            if (empty($value['name'])) {
                $value['name'] = BookingFieldService::generateFieldName($calendarEvent, $value['label']);
            } else {
                $value['name'] = BookingFieldService::maybeGenerateFieldName($calendarEvent, $value);
            }

            $textValues = array_map('sanitize_text_field', Arr::only($value, $textFields));

            $booleanValues = array_map(function ($valueItem) {
                return $valueItem === true || $valueItem === 'true' || $valueItem == 1;
            }, Arr::only($value, $booleanFields));

            $formattedField = array_merge($textValues, $booleanValues);

            $formattedField['index'] = (int)Arr::get($value, 'index');
            if (in_array(Arr::get($value, 'type'), $optionRequiredFields)) {
                $sanitizedOptions = array_map('sanitize_text_field', Arr::get($value, 'options'));
                $formattedField['options'] = $sanitizedOptions;
            }
            if ($value['type'] == 'payment' && $calendarEvent->type === 'paid') {
                $formattedField['payment_items'] = Arr::get($value, 'payment_items');
                $formattedField['currency_sign'] = CurrenciesHelper::getGlobalCurrencySign();
            }
            if ($value['type'] == 'file') {
                $formattedField['max_file_allow'] = intval(Arr::get($value, 'max_file_allow'));
                $formattedField['allow_file_types'] = array_map('sanitize_text_field', Arr::get($value, 'allow_file_types'));
                $formattedField['file_size_value'] = intval(Arr::get($value, 'file_size_value'));
                $formattedField['file_size_unit'] = SanitizeService::checkCollection(Arr::get($value, 'file_size_unit'), ['kb','mb']);
            }
            if ($value['type'] == 'hidden') {
                $formattedField['default_value'] = sanitize_text_field(Arr::get($value, 'default_value'));
            }
            if ($value['type'] == 'terms-and-conditions') {
                $formattedField['terms_and_conditions'] = wp_kses_post(Arr::get($value, 'terms_and_conditions'));
            }

            $formattedFields[] = $formattedField;
        }

        $calendarEvent->setBookingFields($formattedFields);

        return [
            'message' => __('Fields has been updated', 'fluent-booking')
        ];
    }

    public function deleteCalendarEvent(Request $request, $calendarId, $calendarEventId)
    {
        $calendar = Calendar::query()->findOrFail($calendarId);

        $calendarEvent = CalendarSlot::query()->where('calendar_id', $calendar->id)->findOrFail($calendarEventId);

        $calendar->updateEventOrder($calendarEvent->id);

        do_action('fluent_booking/before_delete_calendar_event', $calendarEvent, $calendar);

        $calendarEvent->delete();

        do_action('fluent_booking/after_delete_calendar_event', $calendarEventId, $calendar);

        return [
            'message' => __('Calendar Event has been deleted', 'fluent-booking')
        ];
    }

    private function sanitize_mapped_data($settings)
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

    public function deleteCalendar(Request $request, $calendarId)
    {
        $calendar = Calendar::findOrFail($calendarId);

        do_action('fluent_booking/before_delete_calendar', $calendar);

        $calendar->delete();

        do_action('fluent_booking/after_delete_calendar', $calendarId);

        return [
            'message' => __('Calendar Deleted Successfully!', 'fluent-booking')
        ];
    }
}
