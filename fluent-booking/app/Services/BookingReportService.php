<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\Models\Booking;
use FluentBooking\Framework\Support\Arr;

/**
 * Aggregate queries over bookings, and the one definition of "bookings this
 * user is allowed to count".
 *
 * That second job is why this class exists. The dashboard held two different
 * answers to the same question: the widget numbers scoped on the
 * `fcal_booking_hosts` pivot, and the graph beneath them scoped on the
 * `host_user_id` column — so a limited host could read a smaller number from
 * the graph than from the widget directly above it. `scoped()` is now the
 * single answer for both, and it is the union of calendar ownership and host
 * membership (`Booking::whereHostAccess()`).
 *
 * The schedules list is NOT on it. `SchedulesController::buildSchedulesQuery()`
 * and `addCountsForFirstPage()` still filter on `host_user_id` alone, so a host
 * who owns a calendar but is not the named host on its bookings sees fewer rows
 * there than the widgets above now count. Moving that screen onto `scoped()`
 * would change a shipped list's contents, so it is left as a deliberate,
 * recorded divergence rather than folded in here.
 *
 * Aggregation is dimension-and-metric based rather than free-form: callers pick
 * from a fixed set of group-by dimensions and metrics, both of which map to
 * literal SQL fragments held in this file. No caller-supplied string ever
 * reaches the query.
 *
 * @since 2.2.6
 */
class BookingReportService
{
    /**
     * A year plus a day, so "the last 12 months" and "this calendar year"
     * both fit without the caller having to think about it.
     */
    const MAX_RANGE_DAYS = 366;

    /**
     * Ceiling on returned groups. A report is a summary; a caller that needs
     * every row wants the bookings list, not this.
     */
    const MAX_GROUPS = 200;

    /**
     * Group-by dimension => [SQL expression, result key]. `%offset%` is
     * replaced with an integer offset in seconds; see shiftedColumn().
     *
     * @return array
     */
    public static function dimensions()
    {
        return [
            'status'     => ['expr' => 'status', 'label' => __('Status', 'fluent-booking')],
            'event'      => ['expr' => 'event_id', 'label' => __('Event type', 'fluent-booking')],
            'event_type' => ['expr' => 'event_type', 'label' => __('Event kind', 'fluent-booking')],
            'host'       => ['expr' => 'host_user_id', 'label' => __('Host', 'fluent-booking')],
            'calendar'   => ['expr' => 'calendar_id', 'label' => __('Calendar', 'fluent-booking')],
            'source'     => ['expr' => 'source', 'label' => __('Source', 'fluent-booking')],
            'country'    => ['expr' => 'country', 'label' => __('Country', 'fluent-booking')],
            'day'        => ['expr' => 'DATE(%shifted%)', 'label' => __('Day', 'fluent-booking')],
            'month'      => ['expr' => 'DATE_FORMAT(%shifted%, \'%Y-%m\')', 'label' => __('Month', 'fluent-booking')],
            'weekday'    => ['expr' => 'DAYOFWEEK(%shifted%)', 'label' => __('Weekday', 'fluent-booking')],
            'hour'       => ['expr' => 'HOUR(%shifted%)', 'label' => __('Hour', 'fluent-booking')],
        ];
    }

    /**
     * @return array
     */
    public static function metrics()
    {
        return ['count', 'distinct_attendees', 'total_minutes', 'no_show_rate', 'cancellation_rate'];
    }

    /**
     * Which timestamp column a date range and the time dimensions read.
     *
     * @return array
     */
    public static function dateFields()
    {
        return ['start_time', 'created_at', 'end_time'];
    }

    /**
     * Bookings the given user is allowed to see, or all of them when they hold
     * read-all-bookings. The canonical scope — do not reimplement it.
     *
     * @param int|null $userId Defaults to the current user.
     *
     * @return \FluentBooking\Framework\Database\Orm\Builder
     */
    public static function scoped($userId = null)
    {
        $query = Booking::query();

        if (PermissionManager::userCanSeeAllBookings()) {
            return $query;
        }

        $userId = $userId === null ? get_current_user_id() : (int) $userId;

        return $query->whereHostAccess($userId);
    }

    /**
     * Run an aggregate query.
     *
     * @param array $args {
     *     @type array  $group_by   Dimension keys, in order. At most 2.
     *     @type array  $metrics    Metric keys. Defaults to ['count'].
     *     @type string $date_field Which timestamp the range and the day/hour
     *                              dimensions read. Defaults to 'start_time'.
     *     @type string $from       Y-m-d, inclusive.
     *     @type string $to         Y-m-d, inclusive.
     *     @type string $timezone   IANA zone the day/weekday/hour buckets are
     *                              expressed in. Defaults to the site zone.
     *     @type array  $filters    Optional equality filters: status[],
     *                              event_id, calendar_id, host_id, event_type,
     *                              source.
     *     @type array  $having     ['metric' => …, 'op' => …, 'value' => …].
     *     @type string $order_by   A metric key, or a dimension key.
     *     @type string $order      'asc'|'desc'.
     *     @type int    $limit
     * }
     *
     * @return array|\WP_Error
     */
    public static function aggregate($args = [])
    {
        $groupBy = array_values(array_filter((array) Arr::get($args, 'group_by', [])));
        $metrics = array_values(array_filter((array) Arr::get($args, 'metrics', [])));

        if (!$metrics) {
            $metrics = ['count'];
        }

        $dimensions = self::dimensions();

        $unknownDims = array_diff($groupBy, array_keys($dimensions));

        if ($unknownDims) {
            return new \WP_Error('invalid_group_by', sprintf(
                /* translators: %1$s: rejected dimension names, %2$s: accepted dimension names */
                __('Unknown group_by: %1$s. Available: %2$s.', 'fluent-booking'),
                implode(', ', $unknownDims),
                implode(', ', array_keys($dimensions))
            ));
        }

        $unknownMetrics = array_diff($metrics, self::metrics());

        if ($unknownMetrics) {
            return new \WP_Error('invalid_metric', sprintf(
                /* translators: %1$s: rejected metric names, %2$s: accepted metric names */
                __('Unknown metrics: %1$s. Available: %2$s.', 'fluent-booking'),
                implode(', ', $unknownMetrics),
                implode(', ', self::metrics())
            ));
        }

        // Two dimensions already produce a cross-product; a third turns a
        // summary back into a row dump, which is what this tool exists to avoid.
        if (count($groupBy) > 2) {
            return new \WP_Error('too_many_dimensions', __('Group by at most two dimensions.', 'fluent-booking'));
        }

        $dateField = Arr::get($args, 'date_field', 'start_time');

        if (!in_array($dateField, self::dateFields(), true)) {
            return new \WP_Error('invalid_date_field', sprintf(
                /* translators: %s: accepted date field names */
                __('date_field must be one of: %s.', 'fluent-booking'),
                implode(', ', self::dateFields())
            ));
        }

        $range = self::resolveRange(Arr::get($args, 'from'), Arr::get($args, 'to'));

        if (is_wp_error($range)) {
            return $range;
        }

        $timezone = Arr::get($args, 'timezone') ?: DateTimeHelper::getTimeZone();
        $offset   = self::offsetSeconds($timezone, $range['from']);

        $query = self::scoped();

        // Both bounds describe a LOCAL window, so both are converted from local
        // to UTC — and each with its OWN offset, not the range's opening one.
        // Filtering on unshifted UTC while grouping on shifted local made the
        // first and last bucket of every report partial by the size of the
        // offset; using one offset for both bounds then reintroduces the same
        // error, an hour wide, on any range that crosses a DST change (a March
        // report for America/New_York would read its final day at -05:00 when
        // that day is actually -04:00, and swallow the first hour of April).
        $query->whereBetween($dateField, [
            self::localToUtc($range['from'] . ' 00:00:00', $timezone),
            self::localToUtc($range['to'] . ' 23:59:59', $timezone),
        ]);

        $applied = self::applyFilters($query, (array) Arr::get($args, 'filters', []));

        if (is_wp_error($applied)) {
            return $applied;
        }

        $selects = [];
        $groups  = [];

        foreach ($groupBy as $i => $key) {
            $expr = self::resolveExpression($dimensions[$key]['expr'], $dateField, $offset);
            $alias = 'dim_' . $i;
            $selects[] = $expr . ' as ' . $alias;
            $groups[]  = $alias;
        }

        // COUNT(DISTINCT email) builds a distinct set over the whole range and
        // each SUM adds per-row work, so a count-only report selects neither.
        $orderMetric = (string) Arr::get($args, 'order_by', '');

        // Always: resolveOrder falls back to it when no order is named.
        $selects[] = 'COUNT(*) as m_count';

        if (in_array('distinct_attendees', $metrics, true) || $orderMetric === 'distinct_attendees') {
            $selects[] = 'COUNT(DISTINCT email) as m_distinct_attendees';
        }

        if (in_array('total_minutes', $metrics, true) || $orderMetric === 'total_minutes') {
            $selects[] = 'SUM(slot_minutes) as m_total_minutes';
        }

        if (in_array('no_show_rate', $metrics, true)) {
            $selects[] = "SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as m_no_show";
        }

        if (in_array('cancellation_rate', $metrics, true)) {
            $selects[] = "SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as m_cancelled";
        }

        if (self::wantsRate($metrics)) {
            // Rate denominator. `reserved` rows are checkout placeholders for
            // payments that were never completed — counting them as bookings
            // deflates every rate by however many people abandoned a payment form.
            $selects[] = "SUM(CASE WHEN status != 'reserved' THEN 1 ELSE 0 END) as m_real";
        }

        $query->selectRaw(implode(', ', $selects));

        foreach ($groups as $group) {
            $query->groupBy($group);
        }

        $having = self::resolveHaving(Arr::get($args, 'having'));

        if (is_wp_error($having)) {
            return $having;
        }

        if ($having) {
            $query->havingRaw($having);
        }

        $query->orderByRaw(self::resolveOrder($groupBy, $metrics, $args));

        $limit = (int) Arr::get($args, 'limit', 50);
        $limit = max(1, min($limit, self::MAX_GROUPS));

        // Fetch one past the limit so the caller can be told the list was cut
        // rather than reading a truncated report as a complete one.
        $rows = $query->limit($limit + 1)->get();

        $truncated = count($rows) > $limit;

        if ($truncated) {
            $rows = array_slice(is_array($rows) ? $rows : $rows->all(), 0, $limit);
        }

        return [
            'rows'       => self::formatRows($rows, $groupBy, $metrics),
            'group_by'   => $groupBy,
            'metrics'    => $metrics,
            'date_field' => $dateField,
            'range'      => $range,
            'timezone'   => $timezone,
            'truncated'  => $truncated,
            'limit'      => $limit,
        ];
    }

    /**
     * @return array
     */
    /**
     * @return bool
     */
    private static function wantsRate($metrics)
    {
        return (bool) array_intersect($metrics, ['no_show_rate', 'cancellation_rate']);
    }

    private static function formatRows($rows, $groupBy, $metrics)
    {
        $out       = [];
        $wantsRate = self::wantsRate($metrics);

        foreach ($rows as $row) {
            $entry = [];

            foreach ($groupBy as $i => $key) {
                $value = $row->{'dim_' . $i};

                // Never drop a null bucket silently. `country` is only
                // populated behind Cloudflare, so a report that omitted the
                // blanks would read as "everyone is in Germany".
                $entry[$key] = $value === null || $value === '' ? '(unknown)' : $value;
            }

            $count = (int) $row->m_count;
            $rateBase = $wantsRate ? (int) $row->m_real : 0;

            foreach ($metrics as $metric) {
                if ($metric === 'count') {
                    $entry['count'] = $count;
                } elseif ($metric === 'distinct_attendees') {
                    $entry['distinct_attendees'] = (int) $row->m_distinct_attendees;
                } elseif ($metric === 'total_minutes') {
                    $entry['total_minutes'] = (int) $row->m_total_minutes;
                } elseif ($metric === 'no_show_rate') {
                    $entry['no_show_rate'] = $rateBase ? round((int) $row->m_no_show / $rateBase, 4) : 0;
                } elseif ($metric === 'cancellation_rate') {
                    $entry['cancellation_rate'] = $rateBase ? round((int) $row->m_cancelled / $rateBase, 4) : 0;
                }
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Equality filters, each one whitelisted.
     *
     * @return true|\WP_Error
     */
    private static function applyFilters($query, $filters)
    {
        $allowed = ['status', 'event_id', 'calendar_id', 'host_id', 'event_type', 'source'];

        $unknown = array_diff(array_keys($filters), $allowed);

        if ($unknown) {
            return new \WP_Error('invalid_filter', sprintf(
                /* translators: %1$s: rejected filter names, %2$s: accepted filter names */
                __('Unknown filters: %1$s. Available: %2$s.', 'fluent-booking'),
                implode(', ', $unknown),
                implode(', ', $allowed)
            ));
        }

        if ($statuses = array_filter((array) Arr::get($filters, 'status', []))) {
            $query->whereIn('status', array_map('sanitize_text_field', $statuses));
        }

        // array_key_exists, not truthiness: `host_id: 0` and `source: "0"` are
        // filters the caller asked for, and silently dropping them returns the
        // whole unfiltered set under a heading that says otherwise.
        foreach (['event_id' => 'event_id', 'calendar_id' => 'calendar_id', 'host_id' => 'host_user_id'] as $key => $column) {
            if (array_key_exists($key, $filters) && $filters[$key] !== null && $filters[$key] !== '') {
                $query->where($column, (int) $filters[$key]);
            }
        }

        foreach (['event_type', 'source'] as $key) {
            if (array_key_exists($key, $filters) && $filters[$key] !== null && $filters[$key] !== '') {
                $query->where($key, sanitize_text_field($filters[$key]));
            }
        }

        return true;
    }

    /**
     * @return string|\WP_Error '' when there is no having clause.
     */
    private static function resolveHaving($having)
    {
        if (!$having || !is_array($having)) {
            return '';
        }

        $columns = [
            'count'              => 'COUNT(*)',
            'distinct_attendees' => 'COUNT(DISTINCT email)',
            'total_minutes'      => 'SUM(slot_minutes)',
        ];

        $metric = Arr::get($having, 'metric', 'count');

        if (!isset($columns[$metric])) {
            return new \WP_Error('invalid_having', sprintf(
                /* translators: %s: accepted having metrics */
                __('having.metric must be one of: %s.', 'fluent-booking'),
                implode(', ', array_keys($columns))
            ));
        }

        $operators = ['>=' => '>=', '>' => '>', '<=' => '<=', '<' => '<', '=' => '='];
        $op        = Arr::get($having, 'op', '>=');

        if (!isset($operators[$op])) {
            return new \WP_Error('invalid_having', __('having.op must be one of: >=, >, <=, <, =.', 'fluent-booking'));
        }

        // Required, not defaulted to 0: that builds `COUNT(*) >= 0`, so a
        // wrong-shaped having returns everything as though it had filtered.
        if (!is_numeric(Arr::get($having, 'value'))) {
            return new \WP_Error('invalid_having', __('having.value is required and must be a number, e.g. {"metric":"count","op":">=","value":5}.', 'fluent-booking'));
        }

        // Every part of this string is a literal from the maps above except the
        // value, which is cast to an integer.
        return $columns[$metric] . ' ' . $operators[$op] . ' ' . (int) Arr::get($having, 'value', 0);
    }

    /**
     * @return string
     */
    private static function resolveOrder($groupBy, $metrics, $args)
    {
        $columns = [
            'count'              => 'm_count',
            'distinct_attendees' => 'm_distinct_attendees',
            'total_minutes'      => 'm_total_minutes',
        ];

        $requested = Arr::get($args, 'order_by', '');
        $direction = strtolower(Arr::get($args, 'order', '')) === 'asc' ? 'ASC' : 'DESC';

        if (isset($columns[$requested])) {
            return self::tieBreak($columns[$requested] . ' ' . $direction, $groupBy);
        }

        $dimensionIndex = array_search($requested, $groupBy, true);

        if ($dimensionIndex !== false) {
            return self::tieBreak('dim_' . (int) $dimensionIndex . ' ' . $direction, $groupBy);
        }

        // Time series read as a series; everything else reads as a ranking.
        $timeDimensions = ['day', 'month', 'weekday', 'hour'];

        if ($groupBy && in_array($groupBy[0], $timeDimensions, true)) {
            return self::tieBreak('dim_0 ASC', $groupBy);
        }

        return self::tieBreak('m_count DESC', $groupBy);
    }

    /**
     * Settle equal sort keys, so tied rows do not reorder with the plan.
     *
     * @return string
     */
    private static function tieBreak($order, $groupBy)
    {
        $parts = [$order];

        foreach (array_keys($groupBy) as $i) {
            $alias = 'dim_' . (int) $i;

            if (strpos($order, $alias . ' ') !== 0) {
                $parts[] = $alias . ' ASC';
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @return string
     */
    private static function resolveExpression($expr, $dateField, $offset)
    {
        if (strpos($expr, '%shifted%') === false) {
            return $expr;
        }

        return str_replace('%shifted%', self::shiftedColumn($dateField, $offset), $expr);
    }

    /**
     * One local wall-clock instant expressed in UTC, using the offset in force
     * at that instant rather than a fixed one.
     *
     * The range bounds get this treatment individually because a range can cross
     * a daylight-saving change: applying the offset from the range's opening day
     * to its closing day reads a March 31st in America/New_York at -05:00 when
     * it is actually -04:00, and quietly pulls in the first hour of April.
     *
     * @param string $localDateTime 'Y-m-d H:i:s'
     * @param string $timezone
     *
     * @return string 'Y-m-d H:i:s' in UTC
     */
    private static function localToUtc($localDateTime, $timezone)
    {
        try {
            $local = new \DateTime($localDateTime, new \DateTimeZone($timezone));
            $local->setTimezone(new \DateTimeZone('UTC'));

            return $local->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return $localDateTime;
        }
    }

    /**
     * Times are stored in UTC. Grouping them by day or hour without shifting
     * would put an 11pm booking in Berlin on the wrong date — the exact class
     * of error this project keeps guarding against. CONVERT_TZ is not usable
     * because it needs MySQL's timezone tables loaded, which most hosts do not
     * do, so the offset is computed in PHP and applied as a fixed interval.
     *
     * @param string $dateField Whitelisted column name.
     * @param int    $offset    Seconds, already cast.
     *
     * @return string
     */
    private static function shiftedColumn($dateField, $offset)
    {
        if (!$offset) {
            return $dateField;
        }

        return 'DATE_ADD(' . $dateField . ', INTERVAL ' . (int) $offset . ' SECOND)';
    }

    /**
     * The zone's offset at the start of the range. A range that crosses a DST
     * boundary uses one offset throughout, so bookings on the far side can land
     * an hour out; the caller is told which offset was applied.
     *
     * @return int
     */
    public static function offsetSeconds($timezone, $onDate)
    {
        try {
            $zone = new \DateTimeZone($timezone);
            $when = new \DateTime($onDate . ' 12:00:00', new \DateTimeZone('UTC'));

            return $zone->getOffset($when);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * @return array|\WP_Error
     */
    public static function resolveRange($from, $to)
    {
        $to   = $to ? sanitize_text_field($to) : gmdate('Y-m-d');
        $from = $from ? sanitize_text_field($from) : gmdate('Y-m-d', strtotime($to . ' -29 days'));

        foreach ([$from, $to] as $date) {
            // checkdate() as well as the shape: 2026-02-30 matches the pattern
            // and strtotime() then rolls it forward to March 2 silently.
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)
                || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                return new \WP_Error(
                    'invalid_range',
                    __('from and to must be real dates formatted Y-m-d.', 'fluent-booking'),
                    ['received' => $date]
                );
            }
        }

        if ($from > $to) {
            return new \WP_Error('invalid_range', __('from must not be later than to.', 'fluent-booking'));
        }

        $days = (int) floor((strtotime($to) - strtotime($from)) / DAY_IN_SECONDS) + 1;

        if ($days > self::MAX_RANGE_DAYS) {
            return new \WP_Error('range_too_large', sprintf(
                /* translators: %1$d: requested number of days, %2$d: maximum */
                __('That range is %1$d days; the maximum is %2$d. Narrow it, or group by month.', 'fluent-booking'),
                $days,
                self::MAX_RANGE_DAYS
            ));
        }

        return ['from' => $from, 'to' => $to, 'days' => $days];
    }
}
