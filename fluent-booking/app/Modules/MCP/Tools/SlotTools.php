<?php

namespace FluentBooking\App\Modules\MCP\Tools;

use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Modules\MCP\Support\AvailabilityDiagnostics;
use FluentBooking\App\Modules\MCP\Support\MCPHelper;
use FluentBooking\App\Modules\MCP\Support\PermissionGate;
use FluentBooking\App\Modules\MCP\Support\SlotResolver;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Availability — the highest-value read in the whole surface, and the one with
 * the tightest response budget.
 *
 * One tool, not two. A single-slot check ("is 2pm Tuesday free?") is the same
 * question with a narrower answer, so it is a `start_time` parameter here
 * rather than a second permanently-resident schema.
 */
class SlotTools
{
    public static function definitions()
    {
        return [
            'fluent-booking/get-available-slots' => [
                'label'               => __('Get available slots', 'fluent-booking'),
                'description'         => __('Bookable times for an event type, keyed by date in the requested timezone. Pass start_time instead of a range to check one specific slot. Uses the same engine as the public booking page.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'event_id'   => [
                            'type'        => 'integer',
                            'description' => __('The event type to check.', 'fluent-booking'),
                        ],
                        'from'       => [
                            'type'        => 'string',
                            'description' => __('First date to check, Y-m-d. Defaults to today.', 'fluent-booking'),
                        ],
                        'to'         => [
                            'type'        => 'string',
                            'description' => __('Last date to check, Y-m-d. Defaults to 14 days out; 62 days maximum.', 'fluent-booking'),
                        ],
                        'start_time' => [
                            'type'        => 'string',
                            'description' => __('Check one slot instead of a range: Y-m-d H:i:s in the given timezone.', 'fluent-booking'),
                        ],
                        'timezone'   => [
                            'type'        => 'string',
                            'description' => __('IANA timezone the times are returned in. Defaults to the site timezone.', 'fluent-booking'),
                        ],
                        'duration'   => [
                            'type'        => 'integer',
                            'description' => __('Minutes, for events that offer several durations. Defaults to the event default.', 'fluent-booking'),
                        ],
                        'host_id'    => [
                            'type'        => 'integer',
                            'description' => __('Restrict to one host on a team event.', 'fluent-booking'),
                        ],
                    ],
                    'required'   => ['event_id'],
                ],
                'annotations'         => [
                    'title'    => __('Get available slots', 'fluent-booking'),
                    'readonly' => true,
                ],
                'permission_callback' => [PermissionGate::class, 'readGate'],
                'execute_callback'    => [self::class, 'getSlots'],
            ],

            'fluent-booking/diagnose-availability' => [
                'label'               => __('Diagnose availability', 'fluent-booking'),
                'description'         => __('Explain why an event type is or is not offering slots. Returns every rule that can remove slots with its configured value, and attributes each empty date to the specific rule responsible.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'event_id' => [
                            'type'        => 'integer',
                            'description' => __('The event type to investigate.', 'fluent-booking'),
                        ],
                        'from'     => [
                            'type'        => 'string',
                            'description' => __('First date to investigate, Y-m-d. Defaults to today.', 'fluent-booking'),
                        ],
                        'to'       => [
                            'type'        => 'string',
                            'description' => __('Last date to investigate, Y-m-d. Defaults to 14 days out; 62 days maximum.', 'fluent-booking'),
                        ],
                        'timezone' => [
                            'type'        => 'string',
                            'description' => __('IANA timezone the dates are interpreted in.', 'fluent-booking'),
                        ],
                        'host_id'  => [
                            'type'        => 'integer',
                            'description' => __('Investigate one host on a team event.', 'fluent-booking'),
                        ],
                    ],
                    'required'   => ['event_id'],
                ],
                'annotations'         => [
                    'title'    => __('Diagnose availability', 'fluent-booking'),
                    'readonly' => true,
                ],
                'permission_callback' => [PermissionGate::class, 'readGate'],
                'execute_callback'    => [self::class, 'diagnose'],
            ],
        ];
    }

    /**
     * @param array $params
     * @return array|\WP_Error
     */
    public static function diagnose($params = [])
    {
        $event = self::resolveEvent($params);

        if (is_wp_error($event)) {
            return $event;
        }

        $timezone = MCPHelper::resolveTimezone(Arr::get($params, 'timezone', ''));

        $range = SlotResolver::resolveRange(
            Arr::get($params, 'from', ''),
            Arr::get($params, 'to', '')
        );

        if (is_wp_error($range)) {
            return $range;
        }

        list($from, $to) = $range;

        $hostId = SlotResolver::validateHostId($event, Arr::get($params, 'host_id'));

        if (is_wp_error($hostId)) {
            return $hostId;
        }

        $report = AvailabilityDiagnostics::run($event, $from, $to, $timezone, $hostId);

        $failed = [];

        foreach ($report['checks'] as $check) {
            if (empty($check['passed'])) {
                $failed[] = $check['check'];
            }
        }

        $nextStep = $failed
            ? sprintf(
                /* translators: %s: comma-separated names of the configuration checks that failed */
                __('These checks fail on their own: %s. Fix those before looking at individual dates.', 'fluent-booking'),
                implode(', ', $failed)
            )
            : '';

        return MCPHelper::success($report, ['timezone' => $timezone], $nextStep);
    }

    /**
     * @param array $params
     * @return array|\WP_Error
     */
    public static function getSlots($params = [])
    {
        $event = self::resolveEvent($params);

        if (is_wp_error($event)) {
            return $event;
        }

        $timezone = MCPHelper::resolveTimezone(Arr::get($params, 'timezone', ''));
        $duration = absint(Arr::get($params, 'duration')) ?: null;

        $hostId = SlotResolver::validateHostId($event, Arr::get($params, 'host_id'));

        if (is_wp_error($hostId)) {
            return $hostId;
        }

        $startTime = sanitize_text_field((string) Arr::get($params, 'start_time', ''));

        if ($startTime) {
            // The same strict, DST-aware conversion create-booking uses. A bare
            // strtotime() truthiness check accepted "2026-08-24" (silently
            // meaning midnight, so the answer was "not available" and the agent
            // concluded the day was closed) and "next tuesday" (resolved
            // relative to now). Worse, it accepted strings create-booking then
            // rejected, so an agent could be told a slot was free and be unable
            // to book it with the same value.
            $startUtc = MCPHelper::toUtc($startTime, $timezone);

            if (is_wp_error($startUtc)) {
                return $startUtc;
            }

            $check = SlotResolver::checkSlot($event, $startUtc, $timezone, $duration, $hostId);

            if (is_wp_error($check)) {
                return $check;
            }

            return MCPHelper::success(
                array_merge(['event_id' => (int) $event->id], $check),
                ['timezone' => $timezone]
            );
        }

        $range = SlotResolver::resolveRange(
            Arr::get($params, 'from', ''),
            Arr::get($params, 'to', '')
        );

        if (is_wp_error($range)) {
            return $range;
        }

        list($from, $to) = $range;

        $result = SlotResolver::getSlots($event, $from, $to, $timezone, $duration, $hostId);

        if (is_wp_error($result)) {
            return $result;
        }

        $data = array_merge(
            [
                'event_id'   => (int) $event->id,
                'event_type' => $event->event_type,
                'duration'   => (int) $event->getDuration($duration),
                'from'       => $from,
                'to'         => $to,
            ],
            $result
        );

        // Only worth stating when it actually constrains the answer; on an
        // indefinite range it is false and would just be noise.
        $maxLookup = $event->getMaxLookUpDate();

        if ($maxLookup) {
            $data['bookable_until'] = $maxLookup;
        }

        $nextStep = '';

        if (empty($result['slots'])) {
            $nextStep = __('No slots in this window. Call diagnose-availability with the same event_id to see which rule removed them.', 'fluent-booking');
        }

        return MCPHelper::success($data, ['timezone' => $timezone], $nextStep);
    }

    /**
     * Load the event and confirm the caller may see it.
     *
     * Slots are public information on the booking page, but reaching them
     * through an authenticated operator tool implies acting on that calendar, so
     * the same read gate the admin uses applies here.
     *
     * @param array $params
     * @return CalendarSlot|\WP_Error
     */
    private static function resolveEvent($params)
    {
        $eventId = absint(Arr::get($params, 'event_id'));

        if (!$eventId) {
            return MCPHelper::error(
                'missing_identifier',
                __('event_id is required. Call get-booking-context or get-event-types to find one.', 'fluent-booking')
            );
        }

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

        if (!$event->calendar) {
            return MCPHelper::error(
                'event_not_found',
                __('This event type has no calendar attached, so availability cannot be computed.', 'fluent-booking')
            );
        }

        return $event;
    }
}
