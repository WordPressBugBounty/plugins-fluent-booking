<?php

namespace FluentBooking\App\Modules\MCP\Tools;

use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Modules\MCP\Support\MCPHelper;
use FluentBooking\App\Modules\MCP\Support\PermissionGate;
use FluentBooking\App\Services\BookingReportService;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * One aggregation tool where the 34-tool draft had four.
 *
 * `get-booking-stats`, `get-booking-trend`, `get-top-events` and
 * `get-host-utilization` are all the same query with a different GROUP BY, and
 * shipping them separately would have cost four schemas of resident context to
 * express one idea. A model that understands "group by X, measure Y" can
 * produce all four and the ones nobody thought to name.
 *
 * The response is aggregates only. It never returns booking rows — an agent
 * that wants rows has list-bookings, which is paginated and masks PII.
 */
class ReportTools
{
    public static function definitions()
    {
        return [
            'fluent-booking/query-bookings' => [
                'label'               => __('Query bookings', 'fluent-booking'),
                'description'         => __('Aggregate bookings by one or two dimensions. Answers "how many bookings per host last month", "which event types get cancelled most", "what hours do people book". Returns totals only, never booking rows.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'group_by'   => [
                            'type'        => 'array',
                            'description' => __('One or two dimensions. Omit for a single total over the whole range.', 'fluent-booking'),
                            'items'       => [
                                'type' => 'string',
                                'enum' => array_keys(BookingReportService::dimensions()),
                            ],
                        ],
                        'metrics'    => [
                            'type'        => 'array',
                            'description' => __('Defaults to count. Rates are fractions of that group\'s bookings, 0 to 1.', 'fluent-booking'),
                            'items'       => [
                                'type' => 'string',
                                'enum' => BookingReportService::metrics(),
                            ],
                        ],
                        'date_field' => [
                            'type'        => 'string',
                            'description' => __('Which timestamp the range and the day/month/weekday/hour dimensions read. start_time is when the meeting is; created_at is when it was booked. Defaults to start_time.', 'fluent-booking'),
                            'enum'        => BookingReportService::dateFields(),
                        ],
                        'from'       => [
                            'type'        => 'string',
                            'description' => __('Y-m-d, inclusive. Defaults to 29 days before to.', 'fluent-booking'),
                        ],
                        'to'         => [
                            'type'        => 'string',
                            'description' => __('Y-m-d, inclusive. Defaults to today. Maximum span 366 days.', 'fluent-booking'),
                        ],
                        'timezone'   => [
                            'type'        => 'string',
                            'description' => __('IANA zone the day, weekday and hour buckets are expressed in. Defaults to the site timezone.', 'fluent-booking'),
                        ],
                        'filters'    => [
                            'type'        => 'object',
                            'description' => __('Narrow the set before grouping. Any of: status (array), event_id, calendar_id, host_id, event_type, source.', 'fluent-booking'),
                        ],
                        'having'     => [
                            'type'        => 'object',
                            'description' => __('Drop small groups, e.g. {"metric":"count","op":">=","value":5}.', 'fluent-booking'),
                        ],
                        'order_by'   => [
                            'type'        => 'string',
                            'description' => __('A metric or a grouped dimension. Time series default to chronological, everything else to largest first.', 'fluent-booking'),
                        ],
                        'order'      => [
                            'type' => 'string',
                            'enum' => ['asc', 'desc'],
                        ],
                        'limit'      => [
                            'type'        => 'integer',
                            'description' => __('Groups to return. Default 50, maximum 200.', 'fluent-booking'),
                        ],
                    ],
                ],
                'annotations'         => [
                    'title'    => __('Query bookings', 'fluent-booking'),
                    'readonly' => true,
                ],
                'permission_callback' => [PermissionGate::class, 'readGate'],
                'execute_callback'    => [self::class, 'queryBookings'],
            ],
        ];
    }

    /**
     * @param array $params
     * @return array|\WP_Error
     */
    public static function queryBookings($params = [])
    {
        $timezone = MCPHelper::resolveTimezone(Arr::get($params, 'timezone', ''));

        $result = BookingReportService::aggregate([
            'group_by'   => Arr::get($params, 'group_by', []),
            'metrics'    => Arr::get($params, 'metrics', []),
            'date_field' => Arr::get($params, 'date_field', 'start_time'),
            'from'       => Arr::get($params, 'from'),
            'to'         => Arr::get($params, 'to'),
            'timezone'   => $timezone,
            'filters'    => Arr::get($params, 'filters', []),
            'having'     => Arr::get($params, 'having'),
            'order_by'   => Arr::get($params, 'order_by', ''),
            'order'      => Arr::get($params, 'order', ''),
            'limit'      => Arr::get($params, 'limit', 50),
        ]);

        if (is_wp_error($result)) {
            return MCPHelper::error($result->get_error_code(), $result->get_error_message());
        }

        $rows = self::labelRows($result['rows'], $result['group_by']);

        $meta = [
            'date_field'   => $result['date_field'],
            'from'         => $result['range']['from'],
            'to'           => $result['range']['to'],
            'days'         => $result['range']['days'],
            'group_count'  => count($rows),
            'group_by'     => $result['group_by'],
            'group_by_labels' => self::dimensionLabels($result['group_by']),
            'timezone'     => $timezone,
            'scope'        => PermissionGate::currentScope(),
        ];

        if ($result['truncated']) {
            $meta['truncated'] = true;
            $meta['truncation_note'] = sprintf(
                /* translators: %d: the number of groups returned */
                __('More groups matched than the limit of %d. Raise limit, add a having filter, or narrow the range.', 'fluent-booking'),
                $result['limit']
            );
        }

        // Time buckets were shifted by a fixed offset, so say which one. An
        // agent reporting "most bookings at 9am" needs to know whose 9am.
        if (self::hasTimeDimension($result['group_by'])) {
            $offset = BookingReportService::offsetSeconds($timezone, $result['range']['from']);
            $meta['bucket_offset'] = sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', abs($offset) / 3600, (abs($offset) % 3600) / 60);
            $meta['bucket_note']   = __('Day, weekday and hour buckets use one fixed offset for the whole range. A range crossing a daylight-saving change can place bookings on the far side an hour out.', 'fluent-booking');
        }

        return MCPHelper::success(
            [
                'rows'    => $rows,
                'totals'  => self::totals($result['rows'], $result['metrics']),
            ],
            $meta,
            $rows ? '' : 'No bookings matched. Widen the range, or drop a filter.'
        );
    }

    /**
     * Ids are not answers. A report grouped by host or event type resolves them
     * to names here, in one query per dimension, so the agent does not have to
     * spend a round-trip per row working out what "event 7" is.
     *
     * @return array
     */
    /**
     * Human names for the dimensions grouped on, so an agent rendering a table
     * does not have to invent a header for `event_type`.
     *
     * @return array
     */
    private static function dimensionLabels($groupBy)
    {
        $dimensions = BookingReportService::dimensions();
        $labels     = [];

        foreach ((array) $groupBy as $dimension) {
            if (isset($dimensions[$dimension]['label'])) {
                $labels[$dimension] = $dimensions[$dimension]['label'];
            }
        }

        return $labels;
    }

    private static function labelRows($rows, $groupBy)
    {
        if (!$rows) {
            return $rows;
        }

        $labels = [];

        foreach ($groupBy as $dimension) {
            if ($dimension === 'event') {
                $ids = array_unique(array_filter(array_column($rows, 'event')));
                $labels['event'] = $ids
                    ? CalendarSlot::whereIn('id', $ids)->pluck('title', 'id')->toArray()
                    : [];
            }

            if ($dimension === 'host') {
                $ids = array_unique(array_filter(array_column($rows, 'host')));

                // One query for the lot rather than one per host: a report can
                // return up to 200 groups.
                if ($ids) {
                    cache_users(array_map('intval', $ids));
                }

                foreach ($ids as $id) {
                    $user = get_userdata($id);
                    $labels['host'][$id] = $user ? MCPHelper::untrusted($user->display_name, 200) : sprintf('#%d', $id);
                }
            }

            if ($dimension === 'weekday') {
                // MySQL DAYOFWEEK is 1 = Sunday.
                $labels['weekday'] = [
                    1 => __('Sunday', 'fluent-booking'),
                    2 => __('Monday', 'fluent-booking'),
                    3 => __('Tuesday', 'fluent-booking'),
                    4 => __('Wednesday', 'fluent-booking'),
                    5 => __('Thursday', 'fluent-booking'),
                    6 => __('Friday', 'fluent-booking'),
                    7 => __('Saturday', 'fluent-booking'),
                ];
            }
        }

        if (!$labels) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($labels as $dimension => $map) {
                if (isset($row[$dimension]) && isset($map[$row[$dimension]])) {
                    $row[$dimension . '_label'] = $map[$row[$dimension]];
                }
            }
        }

        return $rows;
    }

    /**
     * Column totals, so an agent does not have to sum the rows itself and get
     * it wrong. Rates are omitted: averaging per-group rates is not the rate
     * over the whole set, and computing the real one would need the numerators
     * this response does not carry.
     *
     * @return array
     */
    private static function totals($rows, $metrics)
    {
        $totals = [];

        foreach (['count', 'distinct_attendees', 'total_minutes'] as $metric) {
            if (in_array($metric, $metrics, true)) {
                $totals[$metric] = array_sum(array_column($rows, $metric));
            }
        }

        // distinct_attendees cannot be summed across groups without
        // double-counting anyone who appears in two of them.
        if (isset($totals['distinct_attendees'])) {
            $totals['distinct_attendees_note'] = __('Summed across groups, so an attendee in two groups is counted twice.', 'fluent-booking');
        }

        return $totals;
    }

    /**
     * @return bool
     */
    private static function hasTimeDimension($groupBy)
    {
        return (bool) array_intersect((array) $groupBy, ['day', 'month', 'weekday', 'hour']);
    }
}
