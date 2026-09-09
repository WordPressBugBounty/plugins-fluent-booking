<?php

namespace FluentBooking\App\Modules\MCP\Tools;

use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Modules\MCP\Support\MCPHelper;
use FluentBooking\App\Modules\MCP\Support\PermissionGate;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Event types — what can be booked, and on what terms.
 *
 * List and detail are one tool rather than two. They take the same filters, and
 * the response shape difference is unambiguous (an `event_id` returns one
 * configured event, its absence returns rows), so a second ~500-token schema
 * would buy nothing. The detail payload is where the answers to "why is this
 * event behaving like that" live, which is also what makes it the natural
 * companion to diagnose-availability.
 */
class EventTypeTools
{
    const DEFAULT_PER_PAGE = 25;

    public static function definitions()
    {
        return [
            'fluent-booking/get-event-types' => [
                'label'               => __('Get event types', 'fluent-booking'),
                'description'         => __('List bookable event types, or pass event_id for one event\'s full configuration: durations, location, availability, booking limits, buffers and questions.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'event_id'    => [
                            'type'        => 'integer',
                            'description' => __('Return this one event type in full instead of a list.', 'fluent-booking'),
                        ],
                        'calendar_id' => ['type' => 'integer'],
                        'host_id'     => [
                            'type'        => 'integer',
                            'description' => __('The host who OWNS the event type. Note this is not the same as list-bookings\' host_id, which matches the host on a booking.', 'fluent-booking'),
                        ],
                        'event_type'  => [
                            'type' => 'string',
                            'enum' => ContextTools::eventTypes(),
                        ],
                        'status'      => [
                            'type' => 'string',
                            'enum' => ['active', 'draft'],
                        ],
                        'search'      => ['type' => 'string'],
                        'page'        => ['type' => 'integer'],
                        'per_page'    => [
                            'type'        => 'integer',
                            'description' => sprintf(
                                /* translators: %1$d: default page size, %2$d: maximum page size */
                                __('Default %1$d, maximum %2$d.', 'fluent-booking'),
                                self::DEFAULT_PER_PAGE,
                                MCPHelper::MAX_PER_PAGE
                            ),
                        ],
                    ],
                ],
                'annotations'         => [
                    'title'    => __('Get event types', 'fluent-booking'),
                    'readonly' => true,
                ],
                'permission_callback' => [PermissionGate::class, 'readGate'],
                'execute_callback'    => [self::class, 'getEventTypes'],
            ],
        ];
    }

    /**
     * @param array $params
     * @return array|\WP_Error
     */
    public static function getEventTypes($params = [])
    {
        $eventId = absint(Arr::get($params, 'event_id'));

        if ($eventId) {
            return self::getOne($eventId);
        }

        return self::getList($params);
    }

    /**
     * @param int $eventId
     * @return array|\WP_Error
     */
    private static function getOne($eventId)
    {
        $event = CalendarSlot::find($eventId);

        if (!$event) {
            return MCPHelper::error(
                'event_not_found',
                __('No event type matched that id.', 'fluent-booking')
            );
        }

        if (!PermissionManager::canReadCalendar($event->calendar_id)) {
            return MCPHelper::error(
                'permission_denied',
                __('You do not have access to this event type.', 'fluent-booking')
            );
        }

        return MCPHelper::success(self::detail($event), [
            'scope' => PermissionGate::currentScope(),
        ]);
    }

    /**
     * @param array $params
     * @return array
     */
    private static function getList($params)
    {
        // The SAME visibility rule getOne() enforces. The list used to filter
        // on `user_id = me` while getOne() gated on canReadCalendar(), which
        // also admits shared team calendars — so an event type could be absent
        // from the list and fully readable by id, and an agent had no way to
        // discover the ids it was allowed to use.
        $query = PermissionGate::scopeToReadableCalendars(CalendarSlot::query(), 'calendar_id');

        $calendarId = absint(Arr::get($params, 'calendar_id'));

        if ($calendarId) {
            $query->where('calendar_id', $calendarId);
        }

        $hostId = absint(Arr::get($params, 'host_id'));

        if ($hostId) {
            $query->where('user_id', $hostId);
        }

        $eventType = sanitize_text_field((string) Arr::get($params, 'event_type', ''));

        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        $status = sanitize_text_field((string) Arr::get($params, 'status', ''));

        if ($status) {
            $query->where('status', $status);
        }

        $search = sanitize_text_field((string) Arr::get($params, 'search', ''));

        if ($search) {
            $escaped = addcslashes($search, '%_\\');
            $query->where('title', 'LIKE', '%' . $escaped . '%');
        }

        $perPage = MCPHelper::perPage(Arr::get($params, 'per_page'), self::DEFAULT_PER_PAGE);
        $page    = max(1, absint(Arr::get($params, 'page', 1)));

        $total = (clone $query)->count();

        $events = $query->orderBy('id', 'ASC')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $rows = [];

        foreach ($events as $event) {
            $rows[] = self::row($event);
        }

        return MCPHelper::success($rows, array_merge(
            MCPHelper::paginationMeta($total, $page, $perPage),
            ['scope' => PermissionGate::currentScope()]
        ));
    }

    /**
     * Compact row — enough to pick an event, nothing more.
     *
     * @param CalendarSlot $event
     * @return array
     */
    private static function row(CalendarSlot $event)
    {
        return [
            'id'          => (int) $event->id,
            'calendar_id' => (int) $event->calendar_id,
            'host_id'     => (int) $event->user_id,
            'title'       => $event->title,
            'slug'        => $event->slug,
            'duration'    => (int) $event->duration,
            'event_type'  => $event->event_type,
            'status'      => $event->status,
        ];
    }

    /**
     * Full configuration for one event.
     *
     * The limits block is the part that matters: buffers, notice period,
     * per-day caps and the bookable window are the settings that make an event
     * show fewer slots than an operator expects, and reading them here is the
     * first step of every availability investigation.
     *
     * @param CalendarSlot $event
     * @return array
     */
    /**
     * The event's configured locations, in create-booking's vocabulary.
     *
     * @param CalendarSlot $event
     *
     * @return array
     */
    private static function locations(CalendarSlot $event)
    {
        $out = [];

        foreach ((array) $event->location_settings as $location) {
            if (!is_array($location) || !Arr::get($location, 'type')) {
                continue;
            }

            $type = sanitize_text_field(Arr::get($location, 'type'));

            $out[] = [
                'type'  => $type,
                'title' => Arr::get($location, 'title', ''),
                // These two take the address or phone number from the
                // attendee, so create-booking needs location_description.
                'needs_description' => in_array($type, ['phone_guest', 'in_person_guest'], true),
            ];
        }

        return $out;
    }

    private static function detail(CalendarSlot $event)
    {
        $settings = (array) $event->settings;

        $data = array_merge(self::row($event), [
            'description'       => $event->getDescription(),
            // The legacy column, kept for callers that read it. It is empty on
            // events that do have a location, which is why `locations` exists.
            'location_type'     => $event->location_type,
            'locations'         => self::locations($event),
            'multi_duration'    => (bool) $event->isMultiDurationEnabled(),
            'available_durations' => array_values((array) $event->getAvailableDurations()),
            'schedule_timezone' => $event->getScheduleTimezone(),
            'availability'      => [
                'type'        => $event->availability_type,
                'schedule_id' => $event->availability_id ? (int) $event->availability_id : null,
            ],
            'limits'            => self::limits($event, $settings),
            'is_recurring'      => (bool) $event->isRecurringEvent(),
            'is_team_event'     => (bool) $event->isTeamEvent(),
            'max_per_slot'      => (int) $event->getMaxBookingPerSlot(),
            'booking_fields'    => self::bookingFields($event),
            'public_url'        => $event->getPublicUrl(),
        ]);

        $maxLookup = $event->getMaxLookUpDate();

        if ($maxLookup) {
            $data['bookable_until'] = $maxLookup;
        }

        return $data;
    }

    /**
     * @param CalendarSlot $event
     * @param array        $settings
     * @return array
     */
    private static function limits(CalendarSlot $event, $settings)
    {
        return [
            'buffer_before_minutes' => (int) Arr::get($settings, 'buffer_time_before', 0),
            'buffer_after_minutes'  => (int) Arr::get($settings, 'buffer_time_after', 0),
            'slot_interval_minutes' => (int) $event->getSlotInterval(),
            'range_type'            => Arr::get($settings, 'range_type', 'range_days'),
            'range_days'            => (int) Arr::get($settings, 'range_days', 0),
            // getCutoutSeconds() is the authority: the setting is stored as a
            // {value, unit} pair, so reading the raw value would report "4" for
            // both four hours and four days.
            'minimum_notice_minutes' => (int) round($event->getCutoutSeconds() / MINUTE_IN_SECONDS),
            'booking_frequency'     => self::capLimits(Arr::get($settings, 'booking_frequency', [])),
            'booking_duration'      => self::capLimits(Arr::get($settings, 'booking_duration', [])),
        ];
    }

    /**
     * Normalise a booking-frequency / booking-duration cap block.
     *
     * The stored shape is a LIST of {unit, value} pairs, not a map — reading it
     * as `limits.per_day` yields null on every event that has a per-day cap,
     * which would tell an agent investigating a fully-booked day that no cap
     * exists. Re-keyed by unit here so it is usable as a lookup.
     *
     * @param mixed $config
     * @return array
     */
    private static function capLimits($config)
    {
        if (!is_array($config) || !Arr::isTrue($config, 'enabled')) {
            return ['enabled' => false];
        }

        $limits = [];

        foreach ((array) Arr::get($config, 'limits', []) as $limit) {
            $unit  = sanitize_text_field((string) Arr::get($limit, 'unit', ''));
            $value = (int) Arr::get($limit, 'value', 0);

            if ($unit && $value) {
                $limits[$unit] = $value;
            }
        }

        return [
            'enabled' => true,
            'limits'  => $limits,
        ];
    }

    /**
     * The questions an attendee is asked, reduced to what a caller creating a
     * booking actually needs: the key to send, whether it is required, and what
     * the options are. The full field definition carries render metadata that
     * would triple the payload for no gain.
     *
     * @param CalendarSlot $event
     * @return array
     */
    private static function bookingFields(CalendarSlot $event)
    {
        $fields = $event->getBookingFields();

        if (!is_array($fields)) {
            return [];
        }

        $out = [];

        foreach ($fields as $key => $field) {
            if (!is_array($field)) {
                continue;
            }

            if (Arr::get($field, 'enabled') === false) {
                continue;
            }

            $entry = [
                'key'      => (string) Arr::get($field, 'name', $key),
                'label'    => (string) Arr::get($field, 'label', ''),
                'type'     => (string) Arr::get($field, 'type', ''),
                'required' => (bool) Arr::get($field, 'required', false),
            ];

            $options = Arr::get($field, 'options', []);

            if (is_array($options) && $options) {
                $entry['options'] = array_values(array_filter(array_map(function ($option) {
                    if (is_array($option)) {
                        return Arr::get($option, 'value', Arr::get($option, 'label', ''));
                    }

                    return is_scalar($option) ? (string) $option : '';
                }, $options)));
            }

            $out[] = $entry;
        }

        return $out;
    }
}
