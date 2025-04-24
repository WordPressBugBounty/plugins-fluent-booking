<?php

namespace FluentBooking\App\Models;

use FluentBooking\App\Models\Model;
use FluentBooking\App\Services\SanitizeService;
use FluentBooking\App\Services\AvailabilityService;
use FluentBooking\App\Services\BookingFieldService;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Services\CurrenciesHelper;
use FluentBooking\App\Services\LocationService;
use FluentBooking\Framework\Support\Arr;

class CalendarSlot extends Model
{
    protected $table = 'fcal_calendar_events';

    protected $guarded = ['id'];

    protected $fillable = [
        'user_id',
        'hash',
        'calendar_id',
        'duration',
        'title',
        'slug',
        'media_id',
        'description',
        'settings',
        'availability_type',
        'availability_id',
        'status',
        'type',
        'color_schema',
        'location_type',
        'location_heading',
        'location_settings',
        'event_type',
        'is_display_spots',
        'max_book_per_slot',
        'created_at',
        'updated_at',
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->user_id)) {
                $model->user_id = get_current_user_id();
            }

            if (empty($model->hash)) {
                $model->hash = md5(wp_generate_uuid4() . time());
            }
        });
    }

    public function setSettingsAttribute($settings)
    {
        $originalSettings = $this->getOriginal('settings');

        $originalSettings = \maybe_unserialize($originalSettings);

        foreach ($settings as $key => $value) {
            $originalSettings[$key] = $value;
        }

        $this->attributes['settings'] = \maybe_serialize($originalSettings);
    }

    public function getSettingsAttribute($settings)
    {
        return \maybe_unserialize($settings);
    }

    public function setLocationSettingsAttribute($locationSettings)
    {
        $this->attributes['location_settings'] = \maybe_serialize($locationSettings);
    }

    public function getLocationSettingsAttribute($locationSettings)
    {
        return \maybe_unserialize($locationSettings);
    }

    public function getShortDescriptionAttribute()
    {
        $description = preg_replace('/<[^>]*>/', ' ', $this->getDescription());

        $maxLength = apply_filters('fluent_booking/event_short_description_length', 160, $this);
        
        $description = Helper::excerpt($description, $maxLength);

        return apply_filters('fluent_booking/event_short_description', $description, $this);
    }

    public function calendar()
    {
        return $this->belongsTo(Calendar::class, 'calendar_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'event_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function event_metas()
    {
        return $this->hasMany(Meta::class, 'object_id', 'id')
            ->whereIn('object_type', ['calendar_event', 'integration']);
    }

    public function isGroup()
    {
        return $this->event_type == 'group';
    }

    public function isSingleEvent()
    {
        return $this->event_type == 'single_event';
    }

    public function isGroupEvent()
    {
        return $this->event_type == 'group_event';
    }

    public function isRoundRobin()
    {
        return $this->event_type == 'round_robin';
    }

    public function isCollective()
    {
        return $this->event_type == 'collective';
    }

    public function isTeamEvent()
    {
        return $this->isRoundRobin() || $this->isCollective();
    }

    public function isOneOffEvent()
    {
        return $this->isSingleEvent() || $this->isGroupEvent();
    }

    public function isMultiHostEvent()
    {
        return $this->isTeamEvent() || $this->isOneOffEvent();
    }

    public function isMultiHostsEvent()
    {
        return $this->isCollective() || $this->isOneOffEvent();
    }

    public function isMultiGuestEvent()
    {
        return $this->isGroup() || $this->isGroupEvent();
    }

    public function isProEvent()
    {
        return $this->isGroup() || $this->isTeamEvent() || $this->isOneOffEvent();
    }

    public function allowMultiBooking()
    {
        return Arr::isTrue($this->settings, 'multiple_booking.enabled');
    }

    public function multiBookingLimit()
    {
        return Arr::get($this->settings, 'multiple_booking.limit', 5);
    }

    public function getAuthorProfile($public = true, $userID = null)
    {
        $userID = $userID ?: $this->user_id;

        $user = get_user_by('id', $userID);
        if (!$user) {
            return false;
        }

        $name = trim($user->first_name . ' ' . $user->last_name);

        if (!$name) {
            $name = $user->display_name;
        }

        $data = [
            'ID'     => $user->ID,
            'name'   => $name,
            'avatar' => Helper::fluentBookingUserAvatar($user->ID, $user)
        ];

        if (!$public) {
            $data['email'] = $user->user_email;
        }

        return $data;
    }

    public function getAuthorProfiles($public = true)
    {
        $teamMembers = [];
        $teamMemberIds = $this->getHostIds();

        foreach ($teamMemberIds as $teamMemberId) {
            $calendar = Calendar::where('user_id', $teamMemberId)->where('type', 'simple')->first();
            if ($calendar) {
                $teamMembers[] = $calendar->getAuthorProfile($public);
            }
        }

        return $teamMembers;
    }

    public function isLocationFieldRequired()
    {
        $locationSettings = Arr::get($this, 'location_settings');

        if (count($locationSettings) > 1) {
            return true;
        }

        return false;
    }

    public function isPhoneRequired()
    {
        if (count($this->location_settings) == 1) {
            return Arr::get($this->location_settings, '0.type') == 'phone_guest';
        }

        return false;
    }

    public function isAddressRequired()
    {
        if (count($this->location_settings) == 1) {
            return Arr::get($this->location_settings, '0.type') == 'in_person_guest';
        }

        return false;
    }

    public function isGuestFieldRequired()
    {
        return true;
    }

    public function getEventDefaultData($calendar)
    {
        $weeklySchedule = Helper::getWeeklyScheduleSchema();

        $availability = AvailabilityService::maybeCreateAvailability($calendar, $weeklySchedule);

        $defaultData = [
            'title'             => '',
            'calendar_id'       => $calendar->id,
            'user_id'           => $calendar->user_id,
            'status'            => 'active',
            'event_type'        => 'single',
            'description'       => '',
            'duration'          => '30',
            'color_schema'      => '#0099ff',
            'availability_type' => 'existing_schedule',
            'availability_id'   => (int)$availability->id,
            'max_book_per_slot' => 1,
            'is_display_spots'  => false,
            'location_settings' => [
                [
                    'type'              => '',
                    'title'             => '',
                    'description'       => '',
                    'host_phone_number' => ''
                ]
            ],
            'settings'          => [
                'schedule_type'         => 'weekly_schedules',
                'weekly_schedules'      => $weeklySchedule,
                'date_overrides'        => [],
                'range_type'            => 'range_days',
                'range_days'            => 60,
                'range_date_between'    => ['', ''],
                'schedule_conditions'   => [
                    'value' => 4,
                    'unit'  => 'hours'
                ]
            ]
        ];

        return $defaultData;
    }

    public function getEventSchema($calendar)
    {
        $userCalendarId = $calendar->type == 'simple' ? $calendar->id : null;

        $settingsSchema = $this->getSlotSettingsSchema($userCalendarId);

        $schema = [
            'title'             => '',
            'status'            => 'active',
            'description'       => '',
            'duration'          => '30',
            'color_schema'      => '#0099ff',
            'calendar'          => $calendar,
            'settings'          => $settingsSchema,
            'max_book_per_slot' => 1,
            'location_settings' => [
                [
                    'type'              => '',
                    'title'             => '',
                    'description'       => '',
                    'host_phone_number' => ''
                ]
            ]
        ];

        return $schema;
    }

    public function getSlotSettingsSchema($calendarId = null)
    {
        $calendarEvent = $calendarId ? CalendarSlot::where('calendar_id', $calendarId)->first() : null;

        return [
            'schedule_type'       => 'weekly_schedules',
            'weekly_schedules'    => Helper::getWeeklyScheduleSchema(),
            'date_overrides'      => [],
            'range_type'          => 'range_days',
            'range_days'          => 60,
            'range_date_between'  => ['', ''],
            'schedule_conditions' => [
                'value' => 4,
                'unit'  => 'hours'
            ],
            'location_fields'     => $this->getLocationFields($calendarEvent)
        ];
    }

    public function getNotifications($isEdit = false)
    {
        $statuses = $this->getMeta('email_notifications');

        if ($statuses) {

            $defaults = Helper::getDefaultEmailNotificationSettings();

            foreach ($defaults as $key => $default) {
                if (isset($statuses[$key])) {
                    if ($isEdit) {
                        $statuses[$key]['title'] = $default['title'];
                    }
                    $emailBody = str_replace('fluent-booking-pro/core', 'fluent-booking', $statuses[$key]['email']['body']);
                    $statuses[$key]['email']['body'] = $emailBody;
                }
            }

            if (!Arr::get($statuses, 'rescheduled_by_host')) {
                $statuses['rescheduled_by_host'] = $defaults['rescheduled_by_host'];
            }

            if (!Arr::get($statuses, 'rescheduled_by_attendee')) {
                $statuses['rescheduled_by_attendee'] = $defaults['rescheduled_by_attendee'];
            }

            if (!Arr::get($statuses, 'booking_request_host')) {
                $statuses['booking_request_host'] = $defaults['booking_request_host'];
            }

            if (!Arr::get($statuses, 'booking_request_attendee')) {
                $statuses['booking_request_attendee'] = $defaults['booking_request_attendee'];
            }

            if (!Arr::get($statuses, 'declined_by_host')) {
                $statuses['declined_by_host'] = $defaults['declined_by_host'];
            }

            return $statuses;
        }

        return Helper::getDefaultEmailNotificationSettings();
    }

    public function setNotifications($notifications)
    {
        $this->updateMeta('email_notifications', $notifications);
    }

    public function getBookingFields()
    {
        return BookingFieldService::getBookingFields($this);
    }

    public function setBookingFields($bookingFields)
    {
        return $this->updateMeta('booking_fields', $bookingFields);
    }

    public function getScheduleTimezone($hostId = null)
    {
        if ($hostId && !$this->isTeamCommonSchedule()) {
            $schedule = $this->getHostSchedule($hostId);
            return Arr::get($schedule, 'value.timezone', 'UTC');
        }

        if ($this->availability_type == 'existing_schedule') {
            $schedule = Availability::findOrFail($this->availability_id);
            return Arr::get($schedule, 'value.timezone', 'UTC');
        }

        return $this->calendar->author_timezone;
    }

    public function isMultiDurationEnabled()
    {
        return Arr::isTrue($this->settings, 'multi_duration.enabled');
    }

    public function getDuration($duration = null)
    {
        if ($this->isMultiDurationEnabled()) {
            if (in_array($duration, Arr::get($this->settings, 'multi_duration.available_durations', []))) {
                return $duration;
            } else {
                return Arr::get($this->settings, 'multi_duration.default_duration', '');
            }
        }

        return $this->duration;
    }

    public function getDefaultDuration()
    {
        if ($this->isMultiDurationEnabled()) {
            return Arr::get($this->settings, 'multi_duration.default_duration', '');
        }

        return $this->duration;
    }

    public function getAvailableDurations()
    {
        if ($this->isMultiDurationEnabled()) {
            $durationLookup = Helper::getDurationLookup(true);
            $availableDurations = Arr::get($this->settings, 'multi_duration.available_durations', []);

            return array_map(function ($duration) use ($durationLookup) {
                return $durationLookup[$duration];
            }, $availableDurations);
        }

        $durationLookup = Helper::getDurationLookup();

        $duration = $durationLookup[$this->duration] ?? Helper::formatDuration($this->duration);

        return [$duration];
    }

    public function getDescription()
    {
        if ($this->description) {
            return $this->description;
        }

        if ($this->isMultiDurationEnabled()) {
            return __('Choose your duration and book a meeting with me', 'fluent-booking');
        }

        // translators: %d is the duration of the meeting in minutes
        return sprintf(__('Book a meeting with me for %d minutes', 'fluent-booking'), $this->duration);
    }

    public function getSlotInterval($duration = null)
    {
        $duration = $duration ?: $this->duration;

        $interval = Arr::get($this->settings, 'slot_interval', '');

        $slotInterval = empty($interval) ? $duration : intval($interval);

        return $slotInterval;
    }

    public function isReserveTime()
    {
        return Arr::isTrue($this->settings, 'reserve_time', false);
    }

    public function getAvailableTimes()
    {
        return Arr::get($this->settings, 'available_times', []);
    }

    public function getTotalBufferTime()
    {
        $bufferTimeBefore = Arr::get($this->settings, 'buffer_time_before', 0);
        $bufferTimeAfter = Arr::get($this->settings, 'buffer_time_after', 0);

        return $bufferTimeBefore + $bufferTimeAfter;
    }

    public function getMaxBookableDateTime($startDate, $timeZone = 'UTC', $format = 'Y-m-d 23:59:59')
    {
        $rangeType = Arr::get($this->settings, 'range_type', 'range_days');

        $lastDay = gmdate('Y-m-t 23:59:59', strtotime($startDate)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        if ($timeZone != 'UTC') {
            $lastDay = DateTimeHelper::convertToTimeZone($lastDay, $timeZone, 'UTC', $format);
        }

        if ($rangeType == 'range_indefinite') {
            return $lastDay;
        }

        $maxLookupDate = $this->getMaxLookUpDate();

        $maxDate = DateTimeHelper::convertToTimeZone($maxLookupDate, $this->calendar->author_timezone, 'UTC');

        if (strtotime($maxDate) > strtotime($lastDay)) {
            return $lastDay;
        }

        return $maxDate;
    }

    public function getMinBookableDateTime($startDate = null, $timeZone = null)
    {
        $startDate = $startDate ?: gmdate('Y-m-d H:i:s'); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $rangeType = Arr::get($this->settings, 'range_type', 'range_days');

        if ($rangeType == 'range_date_between') {
            $range = Arr::get($this->settings, 'range_date_between', []);
            if (is_array($range) && count(array_filter($range)) == 2) {
                if (strtotime($range[0]) >= strtotime($startDate)) {
                    $startDate = gmdate('Y-m-d H:i:s', strtotime($range[0])); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                }
            }
        }

        $totalCutStamp = DateTimeHelper::getTimestamp() + $this->getCutoutSeconds();

        if (strtotime($startDate) < $totalCutStamp) {
            $startDate = gmdate('Y-m-d H:i:s', $totalCutStamp); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        }

        return $startDate;
    }

    public function getMaxLookUpDate()
    {
        $rangeType = Arr::get($this->settings, 'range_type', 'range_days');

        if ($rangeType == 'range_indefinite') {
            return false;
        }

        if ($rangeType == 'range_date_between') {
            $range = Arr::get($this->settings, 'range_date_between', []);
            if (is_array($range) && count(array_filter($range)) == 2) {
                return gmdate('Y-m-d 23:59:59', strtotime($range[1])); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            }
        }

        $rangeDays = Arr::get($this->settings, 'range_days', 60) ?: 60;

        return gmdate('Y-m-d 23:59:59', time() + $rangeDays * DAY_IN_SECONDS); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
    }

    public function getMinLookUpDate($timeZone = 'UTC')
    {
        $rangeType = Arr::get($this->settings, 'range_type', 'range_days');

        $minDate = gmdate('Y-m-d H:i:s'); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        if ($rangeType == 'range_date_between') {
            $range = Arr::get($this->settings, 'range_date_between', []);
            if (is_array($range) && count(array_filter($range)) == 2) {
                $minDate = gmdate('Y-m-d H:i:s', strtotime($range[0])); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            }
        }

        if ($timeZone != 'UTC') {
            $minDate = DateTimeHelper::convertToTimeZone($minDate, $timeZone, 'UTC');
        }

        return $minDate;
    }

    public function getCutoutSeconds()
    {
        $conditions = Arr::get($this->settings, 'schedule_conditions', []);

        if (!$conditions || empty($conditions['unit'])) {
            return 0;
        }

        return strtotime('+' . $conditions['value'] . ' ' . $conditions['unit'], 0) - strtotime('+0 seconds', 0);
    }

    public function getHostIds($hostId = null)
    {
        if ($hostId) {
            return [$hostId];
        }

        if ($this->isMultiHostEvent()) {
            return Arr::get($this->settings, 'team_members', []);
        }

        return [$this->user_id];
    }

    public function getMaxBookingPerSlot()
    {
        return $this->max_book_per_slot;
    }

    public function getPublicUrl()
    {
        $calendar = $this->calendar;
        if (!$calendar) {
            return false;
        }

        $baseUr = $calendar->getLandingPageUrl();

        if (!$baseUr) {
            return '';
        }

        if (defined('FLUENT_BOOKING_LANDING_SLUG')) {
            return $baseUr . '/' . $this->slug;
        }

        return $baseUr . '&event=' . $this->slug;
    }

    public function getMeta($key, $default = null)
    {
        $meta = Meta::where('object_type', 'calendar_event')
            ->where('object_id', $this->id)
            ->where('key', $key)
            ->first();

        if (!$meta) {
            return $default;
        }

        return $meta->value;
    }

    public function updateMeta($key, $value)
    {
        $exist = Meta::where('object_type', 'calendar_event')
            ->where('object_id', $this->id)
            ->where('key', $key)
            ->first();

        if ($exist) {
            $exist->value = $value;
            $exist->save();
        } else {
            $exist = Meta::create([
                'object_type' => 'calendar_event',
                'object_id'   => $this->id,
                'key'         => $key,
                'value'       => $value
            ]);
        }

        return $exist;
    }

    public function getLocationFields($calendarEvent = null)
    {
        return apply_filters('fluent_booking/get_location_fields', [
            'conferencing' => [
                'label'   => __('Conferencing', 'fluent-booking'),
                'options' => [
                    'google_meet'  => [
                        'title'         => __('Google Meet (Pro)', 'fluent-booking'),
                        'disabled'      => true,
                        'location_type' => 'conferencing'
                    ],
                    'ms_teams'     => [
                        'title'         => __('MS Teams (Pro)', 'fluent-booking'),
                        'disabled'      => true,
                        'location_type' => 'conferencing'
                    ],
                    'zoom_meeting' => [
                        'title'         => __('Zoom Video (Pro)', 'fluent-booking'),
                        'disabled'      => true,
                        'location_type' => 'conferencing'
                    ],
                ],
            ],
            'in_person'    => [
                'label'   => __('In Person', 'fluent-booking'),
                'options' => [
                    'in_person_guest'     => [
                        'title' => __('In Person (Attendee Address)', 'fluent-booking'),
                    ],
                    'in_person_organizer' => [
                        'title' => __('In Person (Organizer Address)', 'fluent-booking'),
                    ],
                ],
            ],
            'phone'        => [
                'label'   => __('Phone', 'fluent-booking'),
                'options' => [
                    'phone_guest'     => [
                        'title' => __('Attendee Phone Number', 'fluent-booking'),
                    ],
                    'phone_organizer' => [
                        'title' => __('Organizer Phone Number', 'fluent-booking'),
                    ],
                ],
            ],
            'online'       => [
                'label'   => __('Online', 'fluent-booking'),
                'options' => [
                    'online_meeting' => [
                        'title' => __('Online Meeting', 'fluent-booking'),
                    ],
                ],
            ],
            'other'        => [
                'label'   => __('Other', 'fluent-booking'),
                'options' => [
                    'custom' => [
                        'title' => __('Custom', 'fluent-booking'),
                    ],
                ],
            ],
        ], $calendarEvent ?: $this);
    }

    public function isDisplaySpots()
    {
        return $this->is_display_spots == true;
    }

    public function isAdditionalGuestEnabled()
    {
        $guestField = BookingFieldService::getBookingFieldByName($this, 'guests');

        return Arr::isTrue($guestField, 'enabled', false);
    }

    public function isConfirmationEnabled()
    {
        return Arr::isTrue($this->settings, 'requires_confirmation.enabled');
    }

    public function isConfirmationRequired($bookingStartTime, $bookingCreatedTime = null)
    {
        if (!$this->isConfirmationEnabled() || !is_string($bookingStartTime)) {
            return false;
        }

        $type = Arr::get($this->settings, 'requires_confirmation.type', 'always');
        if ($type == 'always') {
            return true;
        }

        $bookingStartTime = strtotime($bookingStartTime);
        $bookingCreatedTime = $bookingCreatedTime ? strtotime($bookingCreatedTime) : time();

        $conditionUnit = Arr::get($this->settings, 'requires_confirmation.condition.unit', 'minutes');
        $conditionValue = Arr::get($this->settings, 'requires_confirmation.condition.value', 0);

        $conditionTime = $conditionValue * 60;
        if ($conditionUnit == 'hours') {
            $conditionTime = $conditionTime * 60;
        }

        return $bookingStartTime - $bookingCreatedTime < $conditionTime;
    }

    public function getCanNotCancelSettings()
    {
        if (!isset($this->settings['can_not_cancel'])) {
            $enabled = Arr::get($this->settings, 'can_cancel') == 'no' ? true : false;
            return [
                'enabled' => $enabled,
                'type'    => 'always',
            ];
        }
        return Arr::get($this->settings, 'can_not_cancel', []);
    }

    public function getCanNotRescheduleSettings()
    {
        if (!isset($this->settings['can_not_reschedule'])) {
            $enabled = Arr::get($this->settings, 'can_reschedule') == 'no' ? true : false;
            return [
                'enabled' => $enabled,
                'type'    => 'always',
            ];
        }
        return Arr::get($this->settings, 'can_not_reschedule', []);
    }

    public function defaultLocationHtml()
    {
        if (empty($this->location_settings)) {
            return '';
        }

        $default = Arr::get($this, 'location_settings');
        if (!$default) {
            return '';
        }

        return LocationService::getLocationIconHeadingHtml($default, $this);
    }

    public function defaultPaymentIcon($currency, $amount)
    {
        $svg = '<svg viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" data-v-ea893728=""><path fill="currentColor" d="M256 640v192h640V384H768v-64h150.976c14.272 0 19.456 1.472 24.64 4.288a29.056 29.056 0 0 1 12.16 12.096c2.752 5.184 4.224 10.368 4.224 24.64v493.952c0 14.272-1.472 19.456-4.288 24.64a29.056 29.056 0 0 1-12.096 12.16c-5.184 2.752-10.368 4.224-24.64 4.224H233.024c-14.272 0-19.456-1.472-24.64-4.288a29.056 29.056 0 0 1-12.16-12.096c-2.688-5.184-4.224-10.368-4.224-24.576V640h64z"></path><path fill="currentColor" d="M768 192H128v448h640V192zm64-22.976v493.952c0 14.272-1.472 19.456-4.288 24.64a29.056 29.056 0 0 1-12.096 12.16c-5.184 2.752-10.368 4.224-24.64 4.224H105.024c-14.272 0-19.456-1.472-24.64-4.288a29.056 29.056 0 0 1-12.16-12.096C65.536 682.432 64 677.248 64 663.04V169.024c0-14.272 1.472-19.456 4.288-24.64a29.056 29.056 0 0 1 12.096-12.16C85.568 129.536 90.752 128 104.96 128h685.952c14.272 0 19.456 1.472 24.64 4.288a29.056 29.056 0 0 1 12.16 12.096c2.752 5.184 4.224 10.368 4.224 24.64z"></path><path fill="currentColor" d="M448 576a160 160 0 1 1 0-320 160 160 0 0 1 0 320zm0-64a96 96 0 1 0 0-192 96 96 0 0 0 0 192z"></path></svg>';
        $html = '<span class="fcal_slot_payment_icon">' . $svg . $currency . $amount . '</span>';
        return $html;
    }

    public function isPaymentEnabled($duration = null)
    {
        if ($this->isMultiDurationEnabled()) {
            $paymentSettings = $this->getPaymentSettings();
            if (Arr::get($paymentSettings, 'multi_payment_enabled') == 'yes') {
                $duration = $duration ?? $this->getDefaultDuration();
                if (!Arr::get($paymentSettings, 'multi_payment_items.' . $duration . '.value')) {
                    return false;
                }
            }
        }

        return $this->type == 'paid' && Helper::isPaymentEnabled();
    }

    public function isWooEnabled()
    {
        return $this->type == 'woo' && defined('WC_PLUGIN_FILE');
    }

    public function getPaymentItems($duration = null)
    {
        $paymentSettings = $this->getPaymentSettings();

        $isMultiEnabled = Arr::get($paymentSettings, 'multi_payment_enabled') == 'yes';

        if ($this->isMultiDurationEnabled() && $isMultiEnabled) {
            $duration = $duration ?? $this->getDefaultDuration();
            return [Arr::get($paymentSettings, 'multi_payment_items.' . $duration)];
        }

        return Arr::get($paymentSettings, 'items', []);
    }

    public function getPricingTotal()
    {
        if (!$this->isPaymentEnabled()) {
            return 0;
        }

        $total = 0;
        $items = $this->getPaymentItems();
        foreach ($items as $item) {
            $total += $item['value'];
        }

        return $total;
    }

    public function getWooProductPrice()
    {
        $paymentSettings = $this->getPaymentSettings();

        $productId = $paymentSettings['woo_product_id'];

        if (Arr::get($paymentSettings, 'multi_payment_enabled') == 'yes') {
            $duration = $this->getDefaultDuration();
            $productId = Arr::get($paymentSettings, 'multi_payment_woo_ids.' . $duration);
        }

        $price = 0;
        $product = wc_get_product($productId);
        if ($product) {
            $price = $product->get_price();
        }

        return $price;
    }

    public function getWooProductPriceByDuration($productIds = [])
    {
        $productPrices = [];

        foreach ($productIds as $duration => $productId) {
            $product = wc_get_product($productId);
            if ($product) {
                $productPrices[$duration] = [
                    'value' => $product->get_price()
                ];
            }
        }

        return $productPrices;
    }

    public function getPaymentHtml()
    {
        $paymentHtml = '';

        $driver = Arr::get($this->getPaymentSettings(), 'driver');

        if ($driver == 'native' && $this->isPaymentEnabled()) {
            $currency = CurrenciesHelper::getGlobalCurrencySign();
            $totalPayment = $this->getPricingTotal();
            $paymentHtml = $this->defaultPaymentIcon($currency, $totalPayment);
        }

        if ($driver == 'woo' && $this->isWooEnabled()) {
            $currency = get_woocommerce_currency_symbol();
            $totalPayment = $this->getWooProductPrice();
            $paymentHtml = $this->defaultPaymentIcon($currency, $totalPayment);
        }

        return $paymentHtml;
    }

    public function isTeamDefaultSchedule()
    {
        if ($this->isTeamEvent()) {
            if (!Arr::isTrue($this->settings, 'common_schedule', false)) {
                return true;
            }
        }
        return false;
    }

    public function isTeamCommonSchedule()
    {
        if ($this->isTeamEvent()) {
            if (Arr::isTrue($this->settings, 'common_schedule', false)) {
                return true;
            }
        }
        return false;
    }

    public function isRoundRobinDefaultSchedule()
    {
        return $this->isRoundRobin() && $this->isTeamDefaultSchedule();
    }

    public function isRoundRobinCommonSchedule()
    {
        return $this->isRoundRobin() && $this->isTeamCommonSchedule();
    }

    public function isCollectiveDefaultSchedule()
    {
        return $this->isCollective() && $this->isTeamDefaultSchedule();
    }

    private function getProcessedWeeklySlots($schedule)
    {
        $scheduleData = Arr::get($schedule, 'value.weekly_schedules', []);
        $scheduleTimezone = Arr::get($schedule, 'value.timezone', 'UTC');
        $schedule = SanitizeService::weeklySchedules($scheduleData, 'UTC', $scheduleTimezone);
        return AvailabilityService::getUtcWeeklySchedules($schedule, $scheduleTimezone);
    }

    private function getProcessedDateOverrides($schedule)
    {
        $scheduleData = Arr::get($schedule, 'value.date_overrides', []);
        $scheduleTimezone = Arr::get($schedule, 'value.timezone', 'UTC');
        $schedule = SanitizeService::slotDateOverrides($scheduleData, 'UTC', $scheduleTimezone);
        $overrideSlots = AvailabilityService::getUtcDateOverrides($schedule, $scheduleTimezone);
        $overrideDays = AvailabilityService::getDateOverrideDays($schedule, $scheduleTimezone);
        return [$overrideSlots, $overrideDays];
    }

    public function getWeeklySlots($hostId = null)
    {
        if ($hostId && !$this->isTeamCommonSchedule()) {
            $schedule = $this->getHostSchedule($hostId);
            return $this->getProcessedWeeklySlots($schedule);
        }

        if ($this->availability_type === 'existing_schedule') {
            $schedule = Availability::findOrFail($this->availability_id);
            return $this->getProcessedWeeklySlots($schedule);
        }

        $scheduleData = Arr::get($this->settings, 'weekly_schedules', []);
        $schedule = SanitizeService::weeklySchedules($scheduleData, 'UTC', $this->calendar->author_timezone);
        return AvailabilityService::getUtcWeeklySchedules($schedule, $this->calendar->author_timezone);
    }

    public function getDateOverrides($hostId = null)
    {
        if ($hostId && !$this->isTeamCommonSchedule()) {
            $schedule = $this->getHostSchedule($hostId);
            return $this->getProcessedDateOverrides($schedule);
        }

        if ($this->availability_type === 'existing_schedule') {
            $schedule = Availability::findOrFail($this->availability_id);
            return $this->getProcessedDateOverrides($schedule);
        }

        $scheduleData = Arr::get($this->settings, 'date_overrides', []);
        $schedule = SanitizeService::slotDateOverrides($scheduleData, 'UTC', $this->calendar->author_timezone);
        $overrideSlots = AvailabilityService::getUtcDateOverrides($schedule, $this->calendar->author_timezone);
        $overrideDays = AvailabilityService::getDateOverrideDays($schedule, $this->calendar->author_timezone);
        return [$overrideSlots, $overrideDays];
    }

    public function getHostIdsSortedByBookings($startDate, $hostId = null)
    {
        $hostIds = $this->getHostIds($hostId);

        if (count($hostIds) <= 1) {
            return $hostIds;
        }

        $hostBookings = [];
        foreach ($hostIds as $hostId) {
            $dayStart = gmdate('Y-m-d 00:00:00', strtotime($startDate)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            $dayEnd = gmdate('Y-m-d 23:59:59', strtotime($startDate)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            $hostBookings[$hostId] = Booking::getHostTotalBooking($this->id, [$hostId], [$dayStart, $dayEnd]);
        }
        usort($hostIds, function ($a, $b) use ($hostBookings) {
            return $hostBookings[$a] - $hostBookings[$b];
        });

        return $hostIds;
    }

    public function getPaymentSettings()
    {
        $settings = $this->getMeta('payment_settings', []);

        $duration = $this->getDefaultDuration();

        $defaults = [
            'enabled'               => 'no',
            'multi_payment_enabled' => 'no',
            'stripe_enabled'        => 'no',
            'paypal_enabled'        => 'no',
            'offline_enabled'       => 'no',
            'driver'                => 'native',
            'items'                 => [
                [
                    'title' => __('Booking Fee', 'fluent-booking'),
                    'value' => 100
                ]
            ],
            'woo_product_id'        => '',
            'multi_payment_items'   => [
                $duration => [
                    'title' => __('Booking Fee', 'fluent-booking'),
                    'value' => 0
                ]
            ],
            'multi_payment_woo_ids' => [
                $duration => ''
            ]
        ];

        $defaults = apply_filters('fluent_booking/event_payment_settings_defaults', $defaults, $this);

        if (!$settings) {
            $settings = $defaults;
        }

        $settings = wp_parse_args($settings, $defaults);

        return apply_filters('fluent_booking/get_event_payment_settings', $settings, $this);
    }

    public function getHostSchedule($hostId) 
    {
        $hostSchedules = Arr::get($this->settings, 'hosts_schedules', []);
        if (isset($hostSchedules[$hostId])) {
            return Availability::find($hostSchedules[$hostId]);
        }
        return AvailabilityService::getDefaultSchedule($hostId);
    }

    public function getHostsSchedules() {
        $hostIds = $this->getHostIds();
        $hostSchedules = Arr::get($this->settings, 'hosts_schedules', []);
        foreach ($hostIds as $hostId) {
            $hostSchedules[$hostId] = $hostSchedules[$hostId] ?? AvailabilityService::getDefaultSchedule($hostId)['id'];
        }
        return $hostSchedules;
    }

    public function getCalendarEventsMeta()
    {
        $eventsMeta = Meta::where('object_id', $this->id)
            ->where('object_type', 'calendar_event')
            ->get();

        return $eventsMeta;
    }

    public function getIntegrationsMeta()
    {
        $integrationsMeta = Meta::where('object_id', $this->id)
            ->where('object_type', 'integration')
            ->get();

        return $integrationsMeta;
    }

    /**
     * Get the attributes that have been changed since last sync.
     *
     * @return array
     */
    public function getDirty()
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $this->fillable)) {
                continue;
            }

            if (!array_key_exists($key, $this->original)) {
                $dirty[$key] = $value;
            } elseif ($value !== $this->original[$key] &&
                !$this->originalIsNumericallyEquivalent($key)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }
}
