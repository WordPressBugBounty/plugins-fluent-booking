<?php

namespace FluentBooking\App\Models;

use FluentBooking\App\Models\Model;
use FluentBooking\App\Services\BookingFieldService;
use FluentBooking\App\Services\LocationService;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\App\Services\Helper;
use FluentBooking\Framework\Support\Arr;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\App\Services\EditorShortCodeParser;

class Booking extends Model
{
    protected $table = 'fcal_bookings';

    protected $guarded = ['id'];

    private static $bookingType = 'scheduling';

    protected $fillable = [
        'calendar_id',
        'event_id',
        'parent_id',
        'group_id',
        'hash',
        'person_user_id',
        'host_user_id',
        'person_contact_id',
        'person_time_zone',
        'start_time',
        'end_time',
        'slot_minutes',
        'first_name',
        'last_name',
        'email',
        'message',
        'internal_note',
        'phone',
        'country',
        'ip_address',
        'browser',
        'device',
        'other_info',
        'location_details',
        'cancelled_by',
        'status',
        'payment_method',
        'payment_status',
        'event_type',
        'source',
        'source_id',
        'source_url',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term'
    ];


    /**
     * $searchable Columns in table to search
     * @var array
     */
    protected $searchable = [
        'email',
        'first_name',
        'last_name'
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!isset($model->person_user_id) && $userId = get_current_user_id()) {
                $model->person_user_id = $userId;
            }

            if (is_null($model->group_id) || !isset($model->group_id)) {
                $model->group_id = static::assignNextGroupId();
            }

            if (defined('FLUENTCRM') && !empty($model->email) && apply_filters('fluent_calender/auto_booking_fluent_crm_sync', true)) {
                $contact = FluentCrmApi('contacts')->getContact($model->email);
                if ($contact) {
                    $model->person_contact_id = $contact->id;
                }
            }

            if (empty($model->booking_type)) {
                $model->booking_type = self::$bookingType;
            }

            $model->hash = md5(wp_generate_uuid4() . time());
        });

        static::deleting(function ($model) { // before delete() method call this
            $model->booking_meta()->delete();
            $model->booking_activities()->delete();
        });

        static::addGlobalScope('main_bookings', function ($builder) {
            $builder->where('booking_type', self::$bookingType);
        });
    }

    public function calendar()
    {
        return $this->belongsTo(Calendar::class, 'calendar_id');
    }

    public function slot()
    {
        return $this->belongsTo(CalendarSlot::class, 'event_id');
    }

    public function calendar_event()
    {
        return $this->belongsTo(CalendarSlot::class, 'event_id');
    }

    public function booking_meta()
    {
        return $this->hasMany(BookingMeta::class, 'booking_id');
    }

    public function booking_activities()
    {
        return $this->hasMany(BookingActivity::class, 'booking_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public static function assignNextGroupId()
    {
        $lastEvent = static::orderBy('group_id', 'desc')->first(['group_id']);

        return $lastEvent ? $lastEvent->group_id + 1 : 1;
    }

    public function getCustomFormData($isFormatted = true, $isPublic = false)
    {
        if ($isFormatted) {
            return BookingFieldService::getFormattedCustomBookingData($this, true, $isPublic);
        }

        return $this->getMeta('custom_fields_data', []);
    }

    public static function getHostTotalBooking($eventId, $hostIds, $ranges)
    {
        return self::where('event_id', $eventId)
            ->whereIn('host_user_id', $hostIds)
            ->whereBetween('start_time', $ranges)
            ->whereIn('status', ['scheduled', 'completed'])
            ->count();
    }

    public function getAdditionalGuests($isHtml = false)
    {
        $additionalGuests = $this->getMeta('additional_guests', []);
        if (!$additionalGuests) {
            return [];
        }

        if ($isHtml) {
            return wpautop(implode('<br>', $additionalGuests));
        }

        return $additionalGuests;
    }

    public function getHostEmails($excludeHostId = null)
    {
        $hostIds = $this->getHostIds();

        $emails = [];
        foreach ($hostIds as $hostId) {
            if ($hostId != $excludeHostId) {
                if ($user = get_user_by('ID', $hostId)) {
                    $emails[] = $user->user_email;
                }
            }
        }

        return $emails;
    }

    public function hosts()
    {
        $class = __NAMESPACE__ . '\User';

        return $this->belongsToMany(
            $class,
            'fcal_booking_hosts',
            'booking_id',
            'user_id'
        )
            ->withPivot('status')
            ->withTimestamps();
    }

    public function getHostIds()
    {
        return $this->hosts()->pluck('user_id')->toArray();
    }

    public function scopeUpcoming($query)
    {
        return $query->where('end_time', '>=', gmdate('Y-m-d H:i:s')); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
    }

    public function scopePast($query)
    {
        return $query->where('end_time', '<', gmdate('Y-m-d H:i:s')); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
    }

    public function scopeApplyDateRangeFilter($query, $range)
    {
        if (empty($range['start_date']) || empty($range['end_date'])) {
            return $query;
        }

        if (!empty($range['time_zone']) && $range['time_zone'] != 'UTC') {
            $range['start_date'] = date('Y-m-d H:i:s', strtotime($range['start_date'] . ' -1 day'));
            $range['end_date'] = date('Y-m-d H:i:s', strtotime($range['end_date'] . ' +1 day'));
        }

        return $query->whereBetween('start_time', [$range['start_date'], $range['end_date']]);
    }

    public function scopeApplyComputedStatus($query, $status)
    {
        $validStatuses = [
            'upcoming',
            'completed',
            'cancelled',
            'pending',
            'no_show',
            'latest_bookings'
        ];

        if (!in_array($status, $validStatuses)) {
            return $query;
        }

        if ($status == 'upcoming') {
            return $query->where('end_time', '>=', gmdate('Y-m-d H:i:s')) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                ->where('status', 'scheduled');
        }

        if ($status == 'completed') {
            return $query->where('end_time', '<', gmdate('Y-m-d H:i:s')) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                ->where('status', '!=', 'cancelled')
                ->where('status', '!=', 'rejected')
                ->orWhere('status', 'completed'); // maybe cron did not mark few as completed yet
        }

        if ($status == 'cancelled') {
            return $query->where('status', 'cancelled')
                ->orWhere('status', 'rejected');
        }

        if ($status == 'pending') {
            return $query->whereIn('status', ['pending', 'reserved']);
        }

        if ($status == 'latest_bookings') {
            return $query->where('status', '!=', 'reserved');
        }

        return $query->where('status', $status);
    }

    public function scopeApplyBookingOrderByStatus($query, $status)
    {
        if ($status == 'upcoming') {
            return $query->orderBy('start_time', 'ASC');
        }

        if ($status == 'latest_bookings') {
            return $query->orderBy('created_at', 'DESC');
        }

        if (in_array($status, ['completed', 'cancelled'])) {
            return $query->orderBy('updated_at', 'DESC');
        }

        return $query->orderBy('start_time', 'DESC');
    }

    public function getFullBookingDateTimeText($timeZone = 'UTC', $isHtml = false)
    {
        $startDateTime = DateTimeHelper::convertFromUtc($this->start_time, $timeZone, 'Y-m-d H:i:s');
        $endDateTime = DateTimeHelper::convertFromUtc($this->end_time, $timeZone, 'Y-m-d H:i:s');

        $html = DateTimeHelper::formatToLocale($startDateTime, 'time') . ' - ' . DateTimeHelper::formatToLocale($endDateTime, 'time') . ', ';
        $html .= DateTimeHelper::formatToLocale($startDateTime, 'date');

        if ($isHtml && in_array($this->status, ['cancelled', 'rejected'])) {
            $html = '<del>' . $html . '</del>';
        }

        return $html;
    }

    public function getShortBookingDateTime($timeZone = 'UTC')
    {
        // date format for Fri Feb 10, 2023
        $startDate = DateTimeHelper::convertFromUtc($this->start_time, $timeZone, 'D M d, Y');
        $startTime = DateTimeHelper::convertFromUtc($this->start_time, $timeZone, 'h:ia');

        $localDate = date_i18n('D M d, Y', strtotime($startDate));
        $localTime = date_i18n('h:ia', strtotime($startTime));

        return $localDate . ' ' . $localTime;
    }

    public function getPreviousMeetingTime($timeZone = 'UTC')
    {
        $previousMeetingTime = $this->getMeta('previous_meeting_time');
        $html = DateTimeHelper::convertFromUtc($previousMeetingTime, $timeZone, 'D M d, Y');
        $html .= ' ' . DateTimeHelper::convertFromUtc($previousMeetingTime, $timeZone, 'h:ia');

        return $html;
    }

    public function getAttendeeStartTime($format = 'Y-m-d H:i:s')
    {
        return DateTimeHelper::convertFromUtc($this->start_time, $this->person_time_zone, $format);
    }

    public function getAttendeeEndTime($format = 'Y-m-d H:i:s')
    {
        return DateTimeHelper::convertFromUtc($this->end_time, $this->person_time_zone, $format);
    }

    public function getOtherBookingTimes()
    {
        $otherBookings = self::where('parent_id', $this->id)->get();

        return $otherBookings->map(function ($otherBooking) {
            return $otherBooking->getFullBookingDateTimeText($this->person_time_zone, true) . ' (' . $this->person_time_zone . ')';
        })->toArray();
    }

    public function getHostAndGuestDetailsHtml()
    {
        $authors = $this->getHostsDetails();

        $guestNames = (array) trim($this->first_name . ' ' . $this->last_name);

        if ($this->isMultiGuestBooking()) {
            $otherGuests = self::where('parent_id', $this->id)->get()->map(function ($guest) {
                return trim($guest->first_name . ' ' . $guest->last_name);
            })->toArray();
            $guestNames = array_merge($guestNames, $otherGuests);
        }

        $hostUserId = $this->host_user_id;

        $authorListHtml = '<ul class="fcal_listed">';

        foreach ($authors as $author) {
            $authorBadge = ($author['id'] == $hostUserId) ? '<span class="fcal_host_badge">' . __('Host', 'fluent-booking') . '</span>' : '';
            $authorListHtml .= '<li class="fcal_host_name">' . $author['name'] . $authorBadge . '</li>';
        }

        foreach ($guestNames as $guestName) {
            $authorListHtml .= '<li class="fcal_guest_name">' . $guestName . '</li>';
        }
        $authorListHtml .= '</ul>';
    
        return $authorListHtml;
    }

    public function getLocationDetailsHtml()
    {
        $details = $this->location_details;
        $locationType = Arr::get($details, 'type');

        $html = '';
        if ($locationType === 'in_person_guest') {
            $html = '<b>' . __('Invitee Address:', 'fluent-booking') . ' </b>' . Arr::get($details, 'description');
        } else if ($locationType === 'in_person_organizer') {
            $html = '<b>' . Arr::get($details, 'title') . ' </b>';
            $description = Arr::get($details, 'description');
            if ($description) {
                $html .= wpautop($description);
            }
        } else if ($locationType === 'phone_guest') {
            $html = '<b>' . __('Phone Call:', 'fluent-booking') . ' </b>' . $this->phone;
        } else if ($locationType === 'phone_organizer') {
            $html = '<b>' . __('Phone Call:', 'fluent-booking') . ' </b>' . Arr::get($details, 'description') . __(' (Host phone number)', 'fluent-booking');
        } else if ($locationType === 'custom') {
            $html = '<b>' . Arr::get($details, 'title') . '</b>';
            $html .= wpautop(Arr::get($details, 'description'));
        } else if (in_array($locationType, ['google_meet', 'online_meeting', 'zoom_meeting', 'ms_teams'])) {
            $platformLabels = [
                'google_meet'    => __('Google Meet', 'fluent-booking'),
                'online_meeting' => __('Online Meeting', 'fluent-booking'),
                'zoom_meeting'   => __('Zoom Video', 'fluent-booking'),
                'ms_teams'       => __('MS Teams', 'fluent-booking'),
            ];

            $html = '<b>' . $platformLabels[$locationType] . '</b> ';
            $meetingLink = Arr::get($details, 'online_platform_link');
            if ($meetingLink) {
                $html .= '<a target="_blank" href="' . esc_url($meetingLink) . '">' . __('Join Meeting', 'fluent-booking') . '</a>';
            }
        } else {
            return '--';
        }

        return apply_filters('fluent_booking/location_details_html', $html, $details);
    }

    public function getLocationAsText()
    {
        $details = $this->location_details;

        $locationType = Arr::get($details, 'type');
        $meetingLink = Arr::get($details, 'online_platform_link');

        $onlinePlatforms = ['google_meet', 'zoom_meeting', 'online_meeting', 'ms_teams'];

        if ($meetingLink && in_array($locationType, $onlinePlatforms)) {
            return $meetingLink;
        }

        if ($locationType == 'phone_organizer') {
            return Arr::get($details, 'description');
        }

        if ($locationType == 'phone_guest') {
            return $this->phone;
        }

        $text = wp_strip_all_tags($this->getLocationDetailsHtml());

        return apply_filters('fluent_booking/location_details_text', $text, $details);
    }

    public function getMessage()
    {
        if (empty($this->message)) {
            return 'n/a';
        }
        return $this->message;
    }

    public function setLocationDetailsAttribute($locationDetails)
    {
        $this->attributes['location_details'] = \maybe_serialize($locationDetails);
    }

    public function getLocationDetailsAttribute($locationDetails)
    {
        return \maybe_unserialize($locationDetails);
    }

    public function getOngoingStatus()
    {
        if ($this->status != 'scheduled') {
            return [];
        }

        $currentTime = time();
        $startTime = strtotime($this->start_time);
        $endTime = strtotime($this->end_time);

        if ($currentTime > $startTime && $currentTime < $endTime) {
            return ['happening_now' => __('Happening Now', 'fluent-booking')];
        }
        
        if (($startTime - $currentTime) < 1800 && ($startTime - $currentTime) > 0) {
            return ['starting_soon' => __('Starting Soon', 'fluent-booking')];
        }
        
        if (($endTime - $currentTime) > -3600 && ($endTime - $currentTime) < 0) {
            return ['recently_happened' => __('Recently Happened', 'fluent-booking')];
        }

        return [];
    }

    public function getBookingStatus()
    {
        $status = $this->status;
        
        $statusLabels = [
            'scheduled'   => __('Scheduled', 'fluent-booking'),
            'rescheduled' => __('Rescheduled', 'fluent-booking'),
            'completed'   => __('Completed', 'fluent-booking'),
            'pending'     => __('Pending', 'fluent-booking'),
            'cancelled'   => __('Cancelled', 'fluent-booking'),
            'rejected'    => __('Rejected', 'fluent-booking')
        ];

        return Arr::get($statusLabels, $status, $status);
    }

    public function getPaymentStatus()
    {
        $status = $this->payment_status;
        
        $statusLabels = [
            'pending'            => __('Pending', 'fluent-booking'),
            'paid'               => __('Paid', 'fluent-booking'),
            'failed'             => __('Failed', 'fluent-booking'),
            'refunded'           => __('Refunded', 'fluent-booking'),
            'partially-paid'     => __('Partially Paid', 'fluent-booking'),
            'partially-refunded' => __('Partially Refunded', 'fluent-booking')
        ];

        return Arr::get($statusLabels, $status, $status);
    }

    public function payment_order()
    {
        if (defined('FLUENT_BOOKING_PRO_DIR_FILE')) {
            return $this->hasOne(\FluentBookingPro\App\Models\Order::class, 'parent_id');
        }
        return $this->belongsTo(static::class, 'parent_id')->whereNull('id');
    }

    public function getCancelReason($isText = false, $isHtml = false)
    {
        $row = BookingActivity::where('booking_id', $this->id)
            ->where('type', 'cancel_reason')
            ->first();

        if ($row) {
            if ($isText) {
                return $row->description;
            }
            if ($isHtml) {
                return wp_unslash($row->description);
            }
        }

        return $row;
    }

    public function getRejectReason($isText = false, $isHtml = false)
    {
        $row = BookingActivity::where('booking_id', $this->id)
            ->where('type', 'reject_reason')
            ->first();

        if ($row) {
            if ($isText) {
                return $row->description;
            }
            if ($isHtml) {
                return wp_unslash($row->description);
            }
        }

        return $row;
    }

    public function addCancelOrRejectReason($title, $reason, $type = 'cancel_reason')
    {
        if (!$reason && !$title) {
            return null;
        }

        if ($type == 'cancel_reason') {
            $exist = $this->getCancelReason();
        } else {
            $exist = $this->getRejectReason();
        }

        if ($exist) {
            $exist->title = $title;
            $exist->description = $reason;
            $exist->save();
            return $exist;
        }

        return BookingActivity::create([
            'booking_id'  => $this->id,
            'type'        => $type,
            'title'       => $title,
            'description' => $reason
        ]);
    }

    public function cancelMeeting($reason = '', $cancelledByType = 'guest', $cancelledByUserId = null)
    {
        if ($this->status == 'cancelled') {
            return $this;
        }

        $cancellableStatuses = [
            'scheduled',
            'pending'
        ];

        if (!in_array($this->status, $cancellableStatuses)) {
            return new \WP_Error('invalid_status', __('This booking is not cancellable.', 'fluent-booking'));
        }

        $this->status = 'cancelled';
        if ($cancelledByUserId) {
            $this->cancelled_by = $cancelledByUserId;
        }

        if (!$cancelledByUserId) {
            $cancelledByUserId = get_current_user_id();
        }

        $this->save();
        $this->updateMeta('cancelled_by_type', $cancelledByType);

        $userName = $cancelledByType;
        if ($cancelledByUserId && $user = get_user_by('ID', $cancelledByUserId)) {
            $userName = $user->display_name;
        }

        if ($reason) {
            /* translators: Name of the user who cancelled the meeting */
            $this->addCancelOrRejectReason(sprintf(__('Meeting has been cancelled by %s', 'fluent-booking'), $userName), $reason);
            do_action('fluent_booking/booking_schedule_cancelled', $this, $this->calendar_event);
            return;
        }

        BookingActivity::create([
            'booking_id'  => $this->id,
            'status'      => 'closed',
            'type'        => 'error',
            'title'       => __('Meeting Cancelled', 'fluent-booking'),
            /* translators: Name of the user who cancelled the meeting */
            'description' => sprintf(__('Meeting has been cancelled by %s', 'fluent-booking'), $userName)
        ]);

        do_action('fluent_booking/booking_schedule_cancelled', $this, $this->calendar_event);
    }

    public function rejectMeeting($reason = '', $rejectByUserId = null)
    {
        if ($this->status != 'pending') {
            return;
        }

        $this->status = 'rejected';
        $this->save();

        $rejectByUserId = $rejectByUserId ?: get_current_user_id();

        if ($reason) {
            $userName = 'host';
            if ($rejectByUserId && $user = get_user_by('ID', $rejectByUserId)) {
                $userName = $user->display_name;
            }
            /* translators: Name of the user who rejected the booking */
            $this->addCancelOrRejectReason(sprintf(__('Booking request has been rejected by %s', 'fluent-booking'), $userName), $reason, 'reject_reason');
        }

        do_action('fluent_booking/booking_schedule_rejected', $this, $this->calendar_event);
    }

    public function getRescheduleReason($html = false)
    {
        $rescheduleReason = $this->getMeta('reschedule_reason', '');

        if ($rescheduleReason && $html) {
            return wp_unslash($rescheduleReason);
        }

        return $rescheduleReason;
    }

    private function generateBookingTitle($eventTitle, $authorName, $guestName)
    {
        /* translators: 1: Calendar slot title, 2: Author name, 3: Full name of the gueset */
        $bookingTitle = sprintf(__('%1$s meeting between %2$s and %3$s', 'fluent-booking'), $eventTitle, $authorName, $guestName);

        return $bookingTitle;
    }

    public function getBookingTitle($html = false)
    {
        $calendarEvent = $this->calendar_event;

        $eventTitle = $calendarEvent->title;

        $authorName = $this->getHostDetails(false)['name'];

        $guestName = trim($this->first_name . ' ' . $this->last_name);

        $bookingTitle = Arr::get($calendarEvent, 'settings.booking_title');

        $bookingTitle = EditorShortCodeParser::parse($bookingTitle, $this);

        $bookingTitle = $bookingTitle ?: $this->generateBookingTitle($eventTitle, $authorName, $guestName);

        if ($html && strpos($bookingTitle, $eventTitle) !== false) {
            $bookingTitle = str_replace($eventTitle, "<strong>{$eventTitle}</strong>", $bookingTitle);
        }

        return apply_filters('fluent_booking/booking_meeting_title', $bookingTitle, $authorName, $guestName, $calendarEvent, $this);
    }

    public function getActivities()
    {
        return BookingActivity::where('booking_id', $this->id)
            ->orderBy('id', 'DESC')
            ->get();
    }

    public function updateMeta($key, $value)
    {
        $exist = BookingMeta::where('booking_id', $this->id)
            ->where('meta_key', $key)
            ->first();

        if ($exist) {
            $exist->value = $value;
            $exist->save();
            return $exist;
        }

        return BookingMeta::create([
            'booking_id' => $this->id,
            'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'value'      => $value
        ]);
    }

    public function deleteMeta($key)
    {
        return BookingMeta::where('booking_id', $this->id)
            ->where('meta_key', $key)
            ->delete();
    }

    public function getMeta($key, $default = '')
    {
        $exist = BookingMeta::where('booking_id', $this->id)
            ->where('meta_key', $key)
            ->first();

        if ($exist) {
            return $exist->value;
        }

        return $default;
    }


    /**
     * Local scope to filter hosts by search/query string
     * @param string $search
     */
    public function scopeSearchBy($query, $search)
    {
        if ($search) {
            $fields = $this->searchable;
            $query->where(function ($query) use ($fields, $search) {
                $query->where(array_shift($fields), 'LIKE', "%$search%");

                $nameArray = explode(' ', $search);
                if (count($nameArray) >= 2) {
                    $query->orWhere(function ($q) use ($nameArray) {
                        $fname = array_shift($nameArray);
                        $lastName = implode(' ', $nameArray);
                        $q->where('first_name', 'LIKE', "%$fname%")
                            ->orWhere('last_name', 'LIKE', "%$lastName%");
                    });
                }

                foreach ($fields as $field) {
                    $query->orWhere($field, 'LIKE', "%$search%");
                }
            });
        }

        return $query;
    }

    public function getRedirectUrlWithQuery()
    {
        $settings = $this->calendar_event->settings;

        $isEnabled     = Arr::isTrue($settings, 'custom_redirect.enabled');
        $redirectUrl   = Arr::get($settings, 'custom_redirect.redirect_url', '');
        $queryString   = Arr::get($settings, 'custom_redirect.query_string', '');
        $isQueryString = Arr::get($settings, 'custom_redirect.is_query_string', 'no') == 'yes';

        if ($isQueryString && $queryString) {
            if (strpos($redirectUrl, '?')) {
                $redirectUrl .= '&' . $queryString;
            } else {
                $redirectUrl .= '?' . $queryString;
            }
        }

        if (!$isEnabled || empty($redirectUrl)) {
            return '';
        }

        $redirectUrl = EditorShortCodeParser::parse($redirectUrl, $this);

        $isUrlParser = apply_filters('fluent_booking/will_parse_redirect_url_value', true, $this);

        if ($isUrlParser) {
            if (strpos($redirectUrl, '=&') || '=' == substr($redirectUrl, -1)) {
                $urlArray    = explode('?', $redirectUrl);
                $baseUrl     = array_shift($urlArray);
                $query       = wp_parse_url($redirectUrl)['query'];
                $queryParams = explode('&', $query);

                $params = [];
                foreach ($queryParams as $queryParam) {
                    $paramArray = explode('=', $queryParam);
                    if (!empty($paramArray[1])) {
                        $params[$paramArray[0]] = $paramArray[1];
                    }
                }
                $redirectUrl = add_query_arg($params, $baseUrl);
            }
        }

        return $redirectUrl;
    }

    public function getConfirmationUrl()
    {
        return add_query_arg([
            'fluent-booking' => 'booking',
            'meeting_hash'   => $this->hash,
            'type'           => 'confirmation',
        ], Helper::getBookingReceiptLandingBaseUrl());
    }

    public function getAdminViewUrl()
    {
        return Helper::getAppBaseUrl('scheduled-events?period=upcoming&booking_id=' . $this->id);
    }

    public function getIcsDownloadUrl()
    {
        return add_query_arg([
            'fluent-booking' => 'booking',
            'meeting_hash'   => $this->hash,
            'type'           => 'confirmation',
            'ics'            => 'download',
        ], Helper::getBookingReceiptLandingBaseUrl());
    }

    public function getRescheduleUrl()
    {
        return add_query_arg([
            'fluent-booking' => 'booking',
            'meeting_hash'   => $this->hash,
            'type'           => 'reschedule',
        ], Helper::getBookingReceiptLandingBaseUrl());
    }

    public function getCancelUrl()
    {
        return add_query_arg([
            'fluent-booking' => 'booking',
            'meeting_hash'   => $this->hash,
            'type'           => 'cancel',
        ], Helper::getBookingReceiptLandingBaseUrl());
    }

    public function hasBookingAccess()
    {
        $hostIds = $this->getHostIds();
        $hasAccess = PermissionManager::userCan('manage_all_bookings');
        return in_array(get_current_user_id(), $hostIds) || $hasAccess;
    }

    private function canPerformAction($settings)
    {
        if (!in_array($this->status, ['scheduled', 'pending'])) {
            return false;
        }

        if (!Arr::isTrue($settings, 'enabled')) {
            return true;
        }

        if (Arr::get($settings, 'type') == 'conditional') {
            $conditionUnit  = Arr::get($settings, 'condition.unit');
            $conditionValue = Arr::get($settings, 'condition.value');
    
            $bookingStartTime = strtotime($this->start_time);
            $currentTime = time();
    
            $conditionTime = $conditionValue * 60;
            if ($conditionUnit == 'hours') {
                $conditionTime = $conditionTime * 60;
            }
    
            return $bookingStartTime - $currentTime > $conditionTime;
        }

        return false;
    }

    public function canCancel()
    {
        $settings = $this->calendar_event->getCanNotCancelSettings();

        return $this->canPerformAction($settings);
    }

    public function canReschedule()
    {
        $settings = $this->calendar_event->getCanNotRescheduleSettings();

        return $this->canPerformAction($settings);
    }

    public function isMultiGuestBooking()
    {
        return $this->event_type == 'group' || $this->event_type == 'group_event';
    }

    public function isRoundRobinBooking()
    {
        return $this->event_type == 'round_robin';
    }

    public function isMultiHostBooking()
    {
        return in_array($this->event_type, ['single_event', 'group_event', 'collective']);
    }

    public function getHostProfiles($public = true)
    {
        $hostIds = $this->getHostIds();

        $hosts = [];
        foreach ($hostIds as $hostId) {
            $calendar = Calendar::where('user_id', $hostId)->where('type', 'simple')->first();
            if ($calendar) {
                $hosts[] = $calendar->getAuthorProfile($public);
            }
        }

        return $hosts;
    }

    public function getInviteePhoneNumber($calendarEvent)
    {
        $customFormData = $this->getCustomFormData(false);

        $customFields = BookingFieldService::getBookingFields($calendarEvent, true);

        foreach ($customFields as $field) {
            $fieldValue = Arr::get($customFormData, $field['name']);
            if ($fieldValue && $field['type'] == 'phone' && Arr::isTrue($field, 'is_sms_number')) {
                return $fieldValue;
            }
        }

        return $this->phone;
    }

    public function getCancellationMessage()
    {
        $message = Arr::get($this->calendar_event->settings, 'can_not_cancel.message');

        $message = EditorShortCodeParser::parse($message, $this);

        if ($message) {
            return $message;
        }

        return __('Sorry! you can not cancel this', 'fluent-booking');
    }

    public function getRescheduleMessage()
    {
        $message = Arr::get($this->calendar_event->settings, 'can_not_reschedule.message');

        $message = EditorShortCodeParser::parse($message, $this);

        if ($message) {
            return $message;
        }

        return __('Sorry! you can not reschedule this', 'fluent-booking');
    }

    public function getHostDetails($isPublic = true, $hostId = null)
    {
        $hostId = $hostId ?: $this->host_user_id;

        if ($hostId && $user = get_user_by('ID', $hostId)) {
            $name = trim($user->first_name . ' ' . $user->last_name);
            if (!$name) {
                $name = $user->display_name;
            }
            $data = [
                'id'         => $user->ID,
                'name'       => $name,
                'email'      => $user->user_email,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'avatar'     => Helper::fluentBookingUserAvatar($user->ID, $user)
            ];
        } else {
            $data = $this->calendar->getAuthorProfile(false);
        }

        if ($isPublic) {
            unset($data['email']);
        }

        return $data;
    }

    public function getHostsDetails($isPublic = true, $excludeHostId = null)
    {
        $hostIds = $this->getHostIds();

        $hosts = [];
        foreach ($hostIds as $hostId) {
            if ($hostId != $excludeHostId) {
                $hosts[] = $this->getHostDetails($isPublic, $hostId);
            }
        }

        return $hosts;
    }

    public function getHostTimezone()
    {
        if ($this->host_user_id) {
            $calendar = Calendar::where('user_id', $this->host_user_id)
                ->where('type', 'simple')
                ->first();

            if (!$calendar) {
                return '';
            }
            return $calendar->author_timezone;
        }
        return '';
    }

    public function getIcsBookingDescription()
    {
        $description = str_replace(PHP_EOL, '\\n', $this->getConfirmationData());

        if ($this->message) {
            $description  .= __('Note: ', 'fluent-booking') . '\\n' . $this->message . '\\n' . '\\n';
        }

        if ($additionalData = $this->getAdditionalData(false)) {
            if (!empty($description )) {
                $description  .= "\\n";
            } else {
                $description  = '';
            }

            $additionalData = str_replace(PHP_EOL, '\\n', $additionalData);

            $description  .= $additionalData;
        }

        return $description;
    }

    public function getAdditionalData($isHtml = false)
    {
        $customData = BookingFieldService::getFormattedCustomBookingData($this, $isHtml, true);

        if (!$customData) {
            return '';
        }

        if (!$isHtml) {
            $lines = array_filter(array_map(function ($data) {
                return !empty($data['value']) ? $data['label'] . ': ' . PHP_EOL . esc_html($data['value']) : null;
            }, $customData));
        
            return implode(PHP_EOL . PHP_EOL, $lines);
        }

        $html = '<table>';
        foreach ($customData as $data) {
            if (empty($data['value'])) {
                continue;
            }
            $html .= '<tr>';
            $html .= '<td><b>' . $data['label'] . '</b></td>';
            $html .= '<td>' . $data['value'] . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        return $html;
    }

    public function getConfirmationData($html = false)
    {
        $author = $this->getHostDetails(false);

        $guestName = trim($this->first_name . ' ' . $this->last_name);
        
        $bookingTitle = $this->getBookingTitle();

        $separator = $html ? '<br>' : PHP_EOL;

        $sections = [
            'what'  => [
                'title'   => __('What', 'fluent-booking'),
                'content' => $bookingTitle,
            ],
            'when'  => [
                'title'   => __('When', 'fluent-booking'),
                'content' => $this->getFullBookingDateTimeText($this->person_time_zone, !$html) . ' (' . $this->person_time_zone . ')',
            ],
            'who'   => [
                'title'   => __('Who', 'fluent-booking'),
                'content' => $author['name'] . ' - ' . __('Organizer', 'fluent-booking') . $separator . $author['email'] . $separator . $separator . $guestName . $separator . $this->email
            ],
            'where' => [
                'title'   => __('Where', 'fluent-booking'),
                'content' => $this->getLocationAsText()
            ],
        ];

        if ($html) {
            unset($sections['who']);
        }

        $lines = array_map(function ($section) use ($separator) {
            return $section['title'] . ': ' . $separator . esc_html($section['content']);
        }, $sections);

        return implode($separator . $separator, $lines) . $separator . $separator;
    }

    public function getMeetingBookmarks($assetsUrl = '')
    {
        $bookingTitle = $this->getBookingTitle();

        $eventTitle = $this->calendar_event->title;

        return apply_filters('fluent_booking/meeting_bookmarks', [
            'google'   => [
                'title' => __('Google Calendar', 'fluent-booking'),
                'url'   => add_query_arg([
                    'dates'    => gmdate('Ymd\THis\Z', strtotime($this->start_time)) . '/' . gmdate('Ymd\THis\Z', strtotime($this->end_time)),
                    'text'     => $bookingTitle,
                    'details'  => $eventTitle,
                    'location' => urlencode(LocationService::getBookingLocationUrl($this)),
                ], 'https://calendar.google.com/calendar/r/eventedit'),
                'icon'  => $assetsUrl . 'images/g-icon.svg'
            ],
            'outlook'  => [
                'title' => __('Outlook', 'fluent-booking'),
                'url'   => add_query_arg([
                    'startdt'  => gmdate('Ymd\THis\Z', strtotime($this->start_time)),
                    'enddt'    => gmdate('Ymd\THis\Z', strtotime($this->end_time)),
                    'subject'  => $bookingTitle,
                    'path'     => '/calendar/action/compose',
                    'body'     => $eventTitle,
                    'rru'      => 'addevent',
                    'location' => urlencode(LocationService::getBookingLocationUrl($this)),
                ], 'https://outlook.live.com/calendar/0/deeplink/compose'),
                'icon'  => $assetsUrl . 'images/ol-icon.svg'
            ],
            'msoffice' => [
                'title' => __('Microsoft Office', 'fluent-booking'),
                'url'   => add_query_arg([
                    'startdt'  => gmdate('Ymd\THis\Z', strtotime($this->start_time)),
                    'enddt'    => gmdate('Ymd\THis\Z', strtotime($this->end_time)),
                    'subject'  => $bookingTitle,
                    'path'     => '/calendar/action/compose',
                    'body'     => $eventTitle,
                    'rru'      => 'addevent',
                    'location' => urlencode(LocationService::getBookingLocationUrl($this)),
                ], 'https://outlook.office.com/calendar/0/deeplink/compose'),
                'icon'  => $assetsUrl . 'images/msoffice.svg'
            ],
            'other'    => [
                'title' => __('Other Calendar', 'fluent-booking'),
                'url'   => $this->getIcsDownloadUrl(),
                'icon'  => $assetsUrl . 'images/ics.svg'
            ]
        ], $this);
    }

}