<?php

namespace FluentBooking\App\Modules\MCP\Prompts;

use FluentBooking\App\Modules\MCP\Support\MCPHelper;
use FluentBooking\App\Modules\MCP\Support\PermissionGate;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * MCP prompts — worked procedures the operator can invoke by name.
 *
 * Prompts are the right home for anything that would otherwise have been
 * crammed into a tool description, because a prompt costs almost nothing until
 * someone runs it: clients list its name, description and arguments, and fetch
 * the body only on invocation. That makes them cheap in exactly the currency
 * this design spends everywhere else — resident context.
 *
 * So each one here is a procedure rather than a paragraph: which tools to call,
 * in what order, and what to do when they disagree. The three shipped are the
 * three tasks that came up over and over while building the tools, and they
 * double as the eval fixtures in §15 of the spec.
 *
 * @since 2.2.6
 */
class BookingPrompts
{
    public static function definitions()
    {
        return [
            'fluent-booking/daily-briefing' => [
                'label'               => __('Daily briefing', 'fluent-booking'),
                'description'         => __('Summarise a day\'s schedule: what is booked, what needs a decision, and what changed.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'date'     => [
                            'type'        => 'string',
                            'description' => __('Y-m-d. Defaults to today.', 'fluent-booking'),
                        ],
                        'host_id'  => [
                            'type'        => 'integer',
                            'description' => __('Brief for one host only.', 'fluent-booking'),
                        ],
                        'timezone' => [
                            'type'        => 'string',
                            'description' => __('IANA zone to report times in.', 'fluent-booking'),
                        ],
                    ],
                ],
                'is_prompt'           => true,
                'permission_callback' => [PermissionGate::class, 'readGate'],
                'execute_callback'    => [self::class, 'dailyBriefing'],
            ],

            'fluent-booking/troubleshoot-event' => [
                'label'               => __('Troubleshoot an event type', 'fluent-booking'),
                'description'         => __('Work out why an event type is showing no slots, or the wrong ones, and say what to change.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'event_id'  => [
                            'type'        => 'integer',
                            'description' => __('The event type to investigate.', 'fluent-booking'),
                        ],
                        'complaint' => [
                            'type'        => 'string',
                            'description' => __('What the operator or attendee actually reported, in their own words.', 'fluent-booking'),
                        ],
                        'from'      => ['type' => 'string'],
                        'to'        => ['type' => 'string'],
                    ],
                    'required'   => ['event_id'],
                ],
                'is_prompt'           => true,
                'permission_callback' => [PermissionGate::class, 'readGate'],
                'execute_callback'    => [self::class, 'troubleshootEvent'],
            ],

            'fluent-booking/weekly-report' => [
                'label'               => __('Weekly report', 'fluent-booking'),
                'description'         => __('A week\'s booking numbers, with the comparison and the caveats that make them trustworthy.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'from' => [
                            'type'        => 'string',
                            'description' => __('Y-m-d, start of the period. Defaults to the last 7 days.', 'fluent-booking'),
                        ],
                        'to'   => ['type' => 'string'],
                    ],
                ],
                'is_prompt'           => true,
                'permission_callback' => [PermissionGate::class, 'readGate'],
                'execute_callback'    => [self::class, 'weeklyReport'],
            ],
        ];
    }

    /**
     * @param array $params
     * @return array
     */
    public static function dailyBriefing($params = [])
    {
        $date     = self::date(Arr::get($params, 'date'), gmdate('Y-m-d'));
        $hostId   = absint(Arr::get($params, 'host_id'));
        $timezone = sanitize_text_field(Arr::get($params, 'timezone', ''));

        $filters = "period: \"all\", from: \"{$date}\", to: \"{$date}\"";

        if ($hostId) {
            $filters .= ", host_id: {$hostId}";
        }

        if ($timezone) {
            $filters .= ", timezone: \"{$timezone}\"";
        }

        $text = self::lines([
            "Brief the operator on {$date}.",
            '',
            'Do this:',
            '',
            '1. Call `fluent-booking/get-booking-context` first. It gives you the site timezone, the current time, and which bookings this account may see. Every time you report below must carry a stated zone.',
            "2. Call `fluent-booking/list-bookings` with {$filters} for the day's schedule.",
            '3. Call it again with `status: ["pending"]` and no date range. Requests waiting on a decision are the thing most likely to be forgotten, and they are not necessarily on today.',
            '',
            'Then write the briefing:',
            '',
            '- **The day.** Each booking in start order: time (with zone), duration, attendee, event type, host. Say plainly if the day is empty.',
            '- **Needs a decision.** Pending requests, oldest first, with how long each has been waiting.',
            '- **Worth noticing.** Back-to-back bookings with no gap; anything cancelled or rescheduled in the last 24 hours; a host carrying noticeably more than the others.',
            '',
            'Rules:',
            '',
            '- If `meta.scope` is `own_calendars`, say so in one line at the top. The operator is seeing their own calendars, not the site.',
            '- If `meta.pii_masked` is true, do not guess at the masked addresses.',
            '- Report what the tools returned. If something looks wrong, say it looks wrong and name the tool that said it — do not quietly correct it.',
        ]);

        return self::prompt(
            /* translators: %s: the date being briefed */
            sprintf(__('Daily briefing for %s', 'fluent-booking'), $date),
            $text
        );
    }

    /**
     * @param array $params
     * @return array
     */
    public static function troubleshootEvent($params = [])
    {
        $eventId   = absint(Arr::get($params, 'event_id'));
        $complaint = sanitize_textarea_field(Arr::get($params, 'complaint', ''));
        $from      = self::date(Arr::get($params, 'from'), gmdate('Y-m-d'));
        $to        = self::date(Arr::get($params, 'to'), gmdate('Y-m-d', strtotime('+13 days')));

        $lines = [
            "Find out why event type {$eventId} is not offering the slots someone expected, between {$from} and {$to}.",
        ];

        if ($complaint) {
            $lines[] = '';
            $lines[] = 'What was reported:';
            $lines[] = '';
            $lines[] = '> ' . $complaint;
        }

        $lines = array_merge($lines, [
            '',
            'Do this, in order:',
            '',
            "1. `fluent-booking/diagnose-availability` with `event_id: {$eventId}`, `from: \"{$from}\"`, `to: \"{$to}\"`. This is the tool built for exactly this question — start here, not with the slot list.",
            "2. `fluent-booking/get-event-types` with `event_id: {$eventId}` for the configuration behind whichever checks failed.",
            '3. `fluent-booking/get-available-slots` for the same window, to see what an attendee would actually be offered.',
            '',
            'Reading the diagnosis:',
            '',
            '- Every entry in `checks` has `passed` and `detail`. A failed check is a cause; a passed one is not evidence of health, only that it was not the problem.',
            '- `empty_dates` attributes each blank day to a reason. `event_inactive`, `before_bookable_window`, `after_bookable_window`, `date_override_closed`, `no_weekly_hours`, `daily_cap_reached`, `fully_booked` and `minimum_notice` each point at a different setting.',
            '- **`unexplained` means the diagnostic could not account for the day.** That is a real finding, not noise. Say so explicitly rather than passing over it — it usually means the slot engine and the stored settings disagree.',
            '- If `truncated` is set on the slot response, the list was cut at `truncated_at`. Do not read the last date as the end of the calendar.',
            '',
            'Then answer:',
            '',
            '1. **What is wrong** — one sentence a non-technical operator understands.',
            '2. **Why** — the specific setting, with its current value.',
            '3. **What to change** — the setting and what to change it to. If the fix is not clear, say what you would need to know.',
            '',
            'If every check passes and slots are being offered, say the calendar looks correct and ask what the attendee actually saw — the complaint is then probably about a different event type, a different timezone, or a cache.',
        ]);

        return self::prompt(
            /* translators: %d: the event type id */
            sprintf(__('Troubleshoot event type %d', 'fluent-booking'), $eventId),
            self::lines($lines)
        );
    }

    /**
     * @param array $params
     * @return array
     */
    public static function weeklyReport($params = [])
    {
        $to   = self::date(Arr::get($params, 'to'), gmdate('Y-m-d'));
        $from = self::date(Arr::get($params, 'from'), gmdate('Y-m-d', strtotime($to . ' -6 days')));

        $span      = (int) floor((strtotime($to) - strtotime($from)) / DAY_IN_SECONDS) + 1;
        $priorTo   = gmdate('Y-m-d', strtotime($from . ' -1 day'));
        $priorFrom = gmdate('Y-m-d', strtotime($priorTo . ' -' . ($span - 1) . ' days'));

        $text = self::lines([
            "Report on bookings from {$from} to {$to}.",
            '',
            'Do this:',
            '',
            "1. `fluent-booking/query-bookings` with `from: \"{$from}\"`, `to: \"{$to}\"`, `group_by: [\"status\"]`, `metrics: [\"count\", \"total_minutes\"]` — the headline numbers.",
            "2. The same call for {$priorFrom} to {$priorTo}, the equal-length period before it, so the comparison is like for like.",
            '3. `group_by: ["event"]` with `metrics: ["count", "cancellation_rate", "no_show_rate"]` — which event types are working.',
            '4. `group_by: ["host"]` with `metrics: ["count", "total_minutes"]` — how the load is distributed.',
            '5. `group_by: ["weekday"]` and `group_by: ["hour"]` — when people actually book. Pass a `timezone`, and report the offset that comes back in `meta.bucket_offset`.',
            '',
            'Then write the report:',
            '',
            '- **Headline.** Total bookings and hours, with the change against the prior period as both a number and a percentage.',
            '- **By event type.** Ranked. Call out any cancellation or no-show rate that stands apart from the others.',
            '- **By host.** Ranked by hours, not by count — a host doing four 90-minute sessions is busier than one doing six 15-minute calls.',
            '- **Patterns.** The busiest weekday and hour, and anything that moved.',
            '',
            'Caveats you must carry through, because they change what the numbers mean:',
            '',
            '- Report `meta.scope`. On `own_calendars` these are the operator\'s own bookings, not the site\'s.',
            '- If `meta.truncated` is set, more groups matched than were returned. Say so rather than presenting a partial ranking as complete.',
            '- `distinct_attendees` cannot be summed across groups without double-counting; the response says so too.',
            '- Weekday and hour buckets use one fixed offset for the whole range, so a range crossing a daylight-saving change can be an hour out at the far end.',
            '- A percentage change off a small base is noise. Below about ten bookings, give the raw numbers and skip the percentage.',
        ]);

        return self::prompt(
            /* translators: %1$s: start date, %2$s: end date */
            sprintf(__('Booking report, %1$s to %2$s', 'fluent-booking'), $from, $to),
            $text
        );
    }

    /**
     * The MCP prompt result shape: a description plus one or more messages.
     *
     * @param string $description
     * @param string $text
     *
     * @return array
     */
    private static function prompt($description, $text)
    {
        return [
            'description' => $description,
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array $lines
     * @return string
     */
    private static function lines($lines)
    {
        return implode("\n", $lines);
    }

    /**
     * @param mixed  $value
     * @param string $fallback
     *
     * @return string
     */
    private static function date($value, $fallback)
    {
        $value = sanitize_text_field((string) $value);

        return MCPHelper::isRealDate($value) ? $value : $fallback;
    }
}
