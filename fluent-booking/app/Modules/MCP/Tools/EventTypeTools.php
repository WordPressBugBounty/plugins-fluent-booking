<?php

namespace FluentBooking\App\Modules\MCP\Tools;

use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Modules\MCP\Support\MCPHelper;
use FluentBooking\App\Modules\MCP\Support\PermissionGate;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Event types: what can be booked, and on what terms.
 *
 * List and detail share one tool to save a schema: with `event_id` it returns
 * one event's full configuration, without it a list of rows.
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
        // Same visibility rule as getOne(), so every readable id is listable.
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
     * Compact row, enough to pick an event.
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

    /**
     * Full configuration for one event. The limits block (buffers, notice,
     * caps, bookable window) explains why an event shows fewer slots.
     *
     * @param CalendarSlot $event
     * @return array
     */
    private static function detail(CalendarSlot $event)
    {
        $settings = (array) $event->settings;

        $data = array_merge(self::row($event), [
            'description'       => $event->getDescription(),
            // Legacy column, often empty even when locations are set.
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
            // The setting is a {value, unit} pair; getCutoutSeconds() resolves it.
            'minimum_notice_minutes' => (int) round($event->getCutoutSeconds() / MINUTE_IN_SECONDS),
            'booking_frequency'     => self::capLimits(Arr::get($settings, 'booking_frequency', [])),
            'booking_duration'      => self::capLimits(Arr::get($settings, 'booking_duration', [])),
        ];
    }

    /**
     * Normalise a booking-frequency / booking-duration cap block.
     *
     * Stored as a list of {unit, value} pairs, not a map. Re-keyed by unit.
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
     * Booking form fields, reduced to what create-booking needs: key, whether
     * required, and options. Render metadata is dropped.
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
