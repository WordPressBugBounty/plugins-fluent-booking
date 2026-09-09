<?php

namespace FluentBooking\App\Modules\MCP\Tools;

use FluentBooking\App\Models\Availability;
use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Models\User;
use FluentBooking\App\Modules\MCP\Support\MCPHelper;
use FluentBooking\App\Modules\MCP\Support\PermissionGate;
use FluentBooking\App\Modules\MCP\Support\RestBridge;
use FluentBooking\App\Modules\MCP\Support\WriteGuard;
use FluentBooking\App\Services\AvailabilityService;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\App\Services\SanitizeService;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * The `scheduling` toolset: configuration work, off by default.
 *
 * Most agent sessions read bookings and availability and never touch setup, so
 * these four tools stay out of the default context budget until an operator
 * turns them on. When they are on they cost about as much again as the core
 * nine — which is exactly why they are a separate switch rather than always
 * present.
 *
 * Writes here go through RestBridge so the admin's own validation runs. Reads
 * are projected by hand, because the admin's responses are shaped for a UI.
 *
 * @see \FluentBooking\App\Modules\MCP\Support\RestBridge
 */
class SchedulingTools
{
    const REFERENCE_KINDS = ['hosts', 'calendars', 'location_providers', 'booking_fields', 'availability_schedules'];

    /**
     * Ceiling on any one reference list. Never applied silently — every list
     * that hits it says so and reports the real total, because a list that
     * stops at 100 with no note reads as a complete list of 100.
     */
    const LIST_LIMIT = 100;

    public static function definitions()
    {
        return [
            'fluent-booking/get-availability' => [
                'label'               => __('Get availability', 'fluent-booking'),
                'description'         => __('Availability schedules — the weekly hours and date overrides an event type draws on. Lists them, or returns one in full when schedule_id is given. Hours are returned in the schedule\'s own timezone unless you ask for another.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'schedule_id' => [
                            'type'        => 'integer',
                            'description' => __('Return this one schedule in full instead of the list.', 'fluent-booking'),
                        ],
                        'host_id'     => [
                            'type'        => 'integer',
                            'description' => __('Only schedules belonging to this host.', 'fluent-booking'),
                        ],
                        'timezone'    => [
                            'type'        => 'string',
                            'description' => __('Express the weekly hours in this IANA zone. Defaults to each schedule\'s own.', 'fluent-booking'),
                        ],
                    ],
                ],
                'annotations'         => [
                    'title'    => __('Get availability', 'fluent-booking'),
                    'readonly' => true,
                ],
                'permission_callback' => [PermissionGate::class, 'readGate'],
                'execute_callback'    => [self::class, 'getAvailability'],
            ],

            'fluent-booking/manage-availability' => [
                'label'               => __('Manage availability', 'fluent-booking'),
                'description'         => __('Create, rename, edit, clone, set as default or delete an availability schedule. dry_run previews any action and changes nothing. update replaces the whole weekly grid and delete removes the schedule, so both need a confirm_token from a dry run; delete also refuses a schedule still in use.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'action'           => [
                            'type' => 'string',
                            'enum' => ['create', 'update', 'rename', 'clone', 'set_default', 'delete'],
                        ],
                        'schedule_id'      => [
                            'type'        => 'integer',
                            'description' => __('Required for everything except create.', 'fluent-booking'),
                        ],
                        'title'            => [
                            'type'        => 'string',
                            'description' => __('Required for create and rename.', 'fluent-booking'),
                        ],
                        'timezone'         => [
                            'type'        => 'string',
                            'description' => __('IANA zone the weekly hours and overrides are expressed in. Defaults to the schedule\'s own.', 'fluent-booking'),
                        ],
                        'weekly_schedules' => [
                            'type'        => 'object',
                            'description' => __('update only. Keyed sun..sat, each {enabled, slots:[{start,end}]} in 24h HH:MM. Replaces the whole grid.', 'fluent-booking'),
                        ],
                        'date_overrides'   => [
                            'type'        => 'object',
                            'description' => __('update only. Keyed by date, each Y-m-d mapping to [{start,end}] in 24h HH:MM. Replaces all existing overrides; dates in the past are dropped.', 'fluent-booking'),
                        ],
                        'dry_run'          => [
                            'type'        => 'boolean',
                            'description' => __('Preview any action without changing anything.', 'fluent-booking'),
                        ],
                        'confirm_token'    => [
                            'type'        => 'string',
                            'description' => __('From a dry run. Required for update and delete.', 'fluent-booking'),
                        ],
                        'idempotency_key'  => [
                            'type'        => 'string',
                            'description' => __('Your own id for this change. Retrying with the same key replays the first result instead of repeating the change.', 'fluent-booking'),
                        ],
                    ],
                    'required'   => ['action'],
                ],
                'annotations'         => [
                    'title'       => __('Manage availability', 'fluent-booking'),
                    'readonly'    => false,
                    'destructive' => true,
                ],
                'permission_callback' => [PermissionGate::class, 'scheduleWriteGate'],
                'execute_callback'    => [self::class, 'manageAvailability'],
            ],

            'fluent-booking/manage-event-type' => [
                'label'               => __('Manage event type', 'fluent-booking'),
                'description'         => __('Create, edit, duplicate, activate, deactivate or delete an event type. dry_run previews any action and changes nothing. Edits are sectioned the same way the admin saves them, so send only the section you are changing; keys you leave out keep their current values, except weekly_schedules and date_overrides, which are replaced whole. update on the availability and limits sections needs a confirm_token, as does delete, which also refuses while ANY booking exists — past or cancelled included, since all of them are deleted with the event type — unless you pass force.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'action'        => [
                            'type' => 'string',
                            'enum' => ['create', 'update', 'duplicate', 'activate', 'deactivate', 'delete'],
                        ],
                        'event_id'      => [
                            'type'        => 'integer',
                            'description' => __('Required for everything except create.', 'fluent-booking'),
                        ],
                        'calendar_id'   => [
                            'type'        => 'integer',
                            'description' => __('Required for create; the calendar the event type belongs to.', 'fluent-booking'),
                        ],
                        'section'       => [
                            'type'        => 'string',
                            'description' => __('update only. Which group of settings the fields belong to.', 'fluent-booking'),
                            'enum'        => ['details', 'availability', 'limits', 'booking_fields'],
                        ],
                        'fields'        => [
                            'type'        => 'object',
                            'description' => __('The settings to write. For create: title, duration, event_type, status, location_settings. For update: whatever that section accepts — call get-event-types with event_id to see the current values first.', 'fluent-booking'),
                        ],
                        'force'         => [
                            'type'        => 'boolean',
                            'description' => __('delete only. Delete even though bookings exist. Every booking on the event type goes with it — past, cancelled and upcoming — plus their activity history and payment records.', 'fluent-booking'),
                        ],
                        'dry_run'       => [
                            'type'        => 'boolean',
                            'description' => __('Preview any action without changing anything.', 'fluent-booking'),
                        ],
                        'confirm_token' => [
                            'type'        => 'string',
                            'description' => __('From a dry run. Required for delete and for update on the availability and limits sections.', 'fluent-booking'),
                        ],
                        'idempotency_key' => [
                            'type'        => 'string',
                            'description' => __('Your own id for this change. Retrying with the same key replays the first result instead of repeating the change.', 'fluent-booking'),
                        ],
                    ],
                    'required'   => ['action'],
                ],
                'annotations'         => [
                    'title'       => __('Manage event type', 'fluent-booking'),
                    'readonly'    => false,
                    'destructive' => true,
                ],
                'permission_callback' => [PermissionGate::class, 'scheduleWriteGate'],
                'execute_callback'    => [self::class, 'manageEventType'],
            ],

            'fluent-booking/list-reference-data' => [
                'label'               => __('List reference data', 'fluent-booking'),
                'description'         => __('The lookup lists get-booking-context leaves out on larger sites, plus the ones only configuration work needs: hosts, calendars, location providers, an event type\'s booking fields, and availability schedules. Ask only for the kinds you need.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'kinds'    => [
                            'type'  => 'array',
                            'items' => [
                                'type' => 'string',
                                'enum' => self::REFERENCE_KINDS,
                            ],
                        ],
                        'event_id' => [
                            'type'        => 'integer',
                            'description' => __('Required for booking_fields; they are per event type.', 'fluent-booking'),
                        ],
                    ],
                    'required'   => ['kinds'],
                ],
                'annotations'         => [
                    'title'    => __('List reference data', 'fluent-booking'),
                    'readonly' => true,
                ],
                'permission_callback' => [PermissionGate::class, 'readGate'],
                'execute_callback'    => [self::class, 'listReferenceData'],
            ],
        ];
    }

    /**
     * @param array $params
     * @return array|\WP_Error
     */
    public static function getAvailability($params = [])
    {
        $scheduleId = absint(Arr::get($params, 'schedule_id'));
        $timezone   = sanitize_text_field(Arr::get($params, 'timezone', ''));

        if ($scheduleId) {
            $schedule = Availability::find($scheduleId);

            if (!$schedule) {
                return MCPHelper::error('not_found', __('No availability schedule with that id.', 'fluent-booking'));
            }

            if (!self::canReadSchedule($schedule)) {
                return MCPHelper::error('permission_denied', __('You do not have permission to read this schedule.', 'fluent-booking'));
            }

            $projected = self::projectSchedule($schedule, $timezone, true);

            // The row already resolved which zone its hours are really in — a
            // requested one only if valid, the schedule's own otherwise. Read
            // it back rather than restating the request.
            return MCPHelper::success($projected, ['timezone' => $projected['timezone']]);
        }

        $query = Availability::orderBy('id', 'desc');

        if (!PermissionManager::userCan(['manage_all_data', 'read_and_use_other_availabilities', 'manage_other_availabilities'])) {
            $query->where('object_id', get_current_user_id());
        } elseif ($hostId = absint(Arr::get($params, 'host_id'))) {
            $query->where('object_id', $hostId);
        }

        $total = (clone $query)->count();

        $schedules = [];

        // Fetch one past the cap so a truncated list can say so. A list that
        // silently stops at 100 reads as a complete list of 100.
        $rows = [];

        foreach ($query->limit(self::LIST_LIMIT + 1)->get() as $schedule) {
            if (count($rows) >= self::LIST_LIMIT) {
                break;
            }

            $rows[] = $schedule;
        }

        // One grouped query for the page rather than a usage count per row.
        $usage = self::usageCounts(array_map(function ($schedule) {
            return (int) $schedule->id;
        }, $rows));

        foreach ($rows as $schedule) {
            $id = (int) $schedule->id;

            $schedules[] = self::projectSchedule($schedule, $timezone, false, isset($usage[$id]) ? $usage[$id] : 0);
        }

        $meta = [
            'count' => count($schedules),
            'total' => (int) $total,
            'scope' => PermissionGate::currentScope(),
        ];

        if ($total > count($schedules)) {
            $meta['truncated'] = true;
            $meta['truncation_note'] = sprintf(
                /* translators: 1: number returned, 2: number that exist */
                __('Showing %1$d of %2$d schedules. Narrow with host_id.', 'fluent-booking'),
                count($schedules),
                (int) $total
            );
        }

        return MCPHelper::success(
            ['schedules' => $schedules],
            $meta,
            $schedules ? 'Call again with schedule_id for one schedule\'s weekly hours and date overrides.' : ''
        );
    }

    /**
     * @param array $params
     * @return array|\WP_Error
     */
    public static function manageAvailability($params = [])
    {
        return self::deduped('fluent-booking/manage-availability', $params, function () use ($params) {
            return self::manageAvailabilityAction($params);
        }, function ($params) {
            $scheduleId = absint(Arr::get($params, 'schedule_id'));

            // A create has no id yet, so the title stands in for one: a retry
            // that means the same schedule names it the same way.
            return 'availability:' . ($scheduleId ?: 'new:' . md5(strtolower(trim((string) Arr::get($params, 'title', ''))))) . ':' . sanitize_text_field(Arr::get($params, 'action', ''));
        });
    }

    /**
     * @param array $params
     * @return array|\WP_Error
     */
    private static function manageAvailabilityAction($params)
    {
        $action  = sanitize_text_field(Arr::get($params, 'action', ''));
        $dryRun  = Arr::isTrue($params, 'dry_run');

        if ($action === 'create') {
            $title = sanitize_text_field(Arr::get($params, 'title', ''));

            if (!$title) {
                return MCPHelper::error('missing_title', __('create needs a title.', 'fluent-booking'));
            }

            // create takes a title and a timezone and lays down the stock
            // Mon–Fri grid; it has nowhere to put hours, so refuse rather than
            // report success on a schedule with the wrong ones.
            if (Arr::get($params, 'weekly_schedules') || Arr::get($params, 'date_overrides')) {
                return MCPHelper::error(
                    'hours_not_accepted_on_create',
                    __('create makes a schedule with the default weekly hours; it cannot set them. Create it first, then call update with weekly_schedules to replace the grid.', 'fluent-booking')
                );
            }

            if ($dryRun) {
                return self::additivePreview('create', [
                    'title'    => $title,
                    'timezone' => sanitize_text_field(Arr::get($params, 'timezone', '')) ?: MCPHelper::resolveTimezone(''),
                ]);
            }

            return self::bridged('POST', '/availability', [
                'title'    => $title,
                'timezone' => sanitize_text_field(Arr::get($params, 'timezone', '')),
            ]);
        }

        $scheduleId = absint(Arr::get($params, 'schedule_id'));

        if (!$scheduleId) {
            return MCPHelper::error('missing_schedule_id', __('schedule_id is required. Call get-availability to find one.', 'fluent-booking'));
        }

        $schedule = Availability::find($scheduleId);

        if (!$schedule) {
            return MCPHelper::error('not_found', __('No availability schedule with that id.', 'fluent-booking'));
        }

        if (!self::canWriteSchedule($schedule)) {
            return MCPHelper::error(
                'permission_denied',
                __('You do not have permission to change this schedule.', 'fluent-booking'),
                ['schedule_id' => $scheduleId]
            );
        }

        if ($action === 'delete') {
            return self::deleteAvailability($schedule, $params);
        }

        if ($action === 'clone') {
            if ($dryRun) {
                return self::additivePreview('clone', ['source' => self::scheduleSummary($schedule)]);
            }

            return self::bridged('POST', '/availability/' . $scheduleId . '/clone');
        }

        if ($action === 'set_default') {
            if ($dryRun) {
                return self::reversiblePreview('set_default', [
                    'schedule' => self::scheduleSummary($schedule),
                    'effect'   => __('This schedule becomes the default for new event types. The current default stops being the default; nothing else changes.', 'fluent-booking'),
                ]);
            }

            return self::bridged('POST', '/availability/' . $scheduleId . '/update-status', ['default' => true]);
        }

        if ($action === 'rename') {
            $title = sanitize_text_field(Arr::get($params, 'title', ''));

            if (!$title) {
                return MCPHelper::error('missing_title', __('rename needs a title.', 'fluent-booking'));
            }

            if ($dryRun) {
                return self::reversiblePreview('rename', [
                    'schedule' => self::scheduleSummary($schedule),
                    'from'     => $schedule->key,
                    'to'       => $title,
                ]);
            }

            return self::bridged('POST', '/availability/' . $scheduleId . '/update-title', ['title' => $title]);
        }

        if ($action === 'update') {
            $timezone = sanitize_text_field(Arr::get($params, 'timezone', '')) ?: Arr::get($schedule, 'value.timezone', 'UTC');

            $weekly = Arr::get($params, 'weekly_schedules');

            if (!is_array($weekly) || !$weekly) {
                return MCPHelper::error(
                    'missing_weekly_schedules',
                    __('update replaces the whole weekly grid, so weekly_schedules is required. Read the schedule with get-availability first and send it back changed.', 'fluent-booking')
                );
            }

            // `update` replaces the entire grid, so the hours it overwrites are
            // gone — there is no per-day merge and no undo. That makes it
            // destructive in every sense that matters, and it is gated the same
            // way delete is: preview, then a token bound to these exact hours.
            $tool        = 'fluent-booking/manage-availability';
            $entityKey   = 'availability:' . $scheduleId . ':update';
            $fingerprint = self::scheduleFingerprint($schedule);
            $digest      = WriteGuard::paramsDigest($params);

            if ($dryRun) {
                $current = AvailabilityService::getFormattedSchedule($schedule);

                return MCPHelper::success(WriteGuard::preview($tool, $entityKey, $fingerprint, [
                    'action'       => 'update',
                    'schedule'     => self::scheduleSummary($schedule),
                    'replacing'    => Arr::get($current, 'settings.weekly_schedules', []),
                    'with'         => $weekly,
                    'timezone'     => $timezone,
                    'date_override_count' => count((array) Arr::get($params, 'date_overrides', [])),
                    'note'         => __('The whole weekly grid and every date override are replaced by what you send. Days you omit become unavailable.', 'fluent-booking'),
                ], $digest), [], WriteGuard::CONFIRM_NEXT_STEP);
            }

            $confirmed = WriteGuard::confirm($tool, $entityKey, $fingerprint, Arr::get($params, 'confirm_token', ''), $digest);

            if (is_wp_error($confirmed)) {
                return $confirmed;
            }

            return self::bridged('POST', '/availability/' . $scheduleId, [
                'schedule' => [
                    'settings' => [
                        'timezone'         => $timezone,
                        'weekly_schedules' => $weekly,
                        'date_overrides'   => (array) Arr::get($params, 'date_overrides', []),
                    ],
                ],
            ]);
        }

        return MCPHelper::error('unsupported_action', __('Unknown action.', 'fluent-booking'));
    }

    /**
     * Preview shape for an action that only ever adds something.
     *
     * `dry_run` has to mean "changed nothing" for EVERY action a tool exposes,
     * not only the ones that happen to need a confirm token. An agent trained to
     * preview first — and the prompts shipped with this plugin train exactly
     * that — would otherwise find that its cautious path was the destructive
     * one. Additive and reversible actions still answer a dry run; they just
     * hand back no token, because none is needed to proceed.
     *
     * @param string $action
     * @param array  $preview
     * @return array
     */
    private static function additivePreview($action, $preview)
    {
        return MCPHelper::success(
            [
                'dry_run' => true,
                'preview' => ['action' => $action] + $preview,
            ],
            [],
            'Nothing exists yet and nothing was changed. Call again without dry_run to create it; no confirm_token is needed.'
        );
    }

    /**
     * @param string $action
     * @param array  $preview
     * @return array
     */
    private static function reversiblePreview($action, $preview)
    {
        return MCPHelper::success(
            [
                'dry_run' => true,
                'preview' => ['action' => $action] + $preview,
            ],
            [],
            'Nothing was changed. This action is reversible — call again without dry_run to apply it; no confirm_token is needed.'
        );
    }

    /**
     * @return array
     */
    private static function scheduleSummary(Availability $schedule)
    {
        return [
            'id'          => (int) $schedule->id,
            'title'       => $schedule->key,
            'host_id'     => (int) $schedule->object_id,
            'usage_count' => AvailabilityService::getAvailabilityUsageCount($schedule->id),
        ];
    }

    /**
     * @return string
     */
    private static function eventFingerprint(CalendarSlot $event)
    {
        $updatedAt = $event->updated_at instanceof \DateTimeInterface
            ? $event->updated_at->format('Y-m-d H:i:s')
            : $event->updated_at;

        return implode('|', [$event->id, $event->status, $updatedAt]);
    }

    /**
     * @return string
     */
    private static function scheduleFingerprint(Availability $schedule)
    {
        $updatedAt = $schedule->updated_at instanceof \DateTimeInterface
            ? $schedule->updated_at->format('Y-m-d H:i:s')
            : $schedule->updated_at;

        return implode('|', [$schedule->id, $schedule->key, $updatedAt]);
    }

    /**
     * @return array|\WP_Error
     */
    private static function deleteAvailability(Availability $schedule, $params)
    {
        $usage = AvailabilityService::getAvailabilityUsageCount($schedule->id);

        if ($usage) {
            return MCPHelper::error(
                'schedule_in_use',
                /* translators: %d: number of event types using the schedule */
                sprintf(__('%d event types use this schedule. Point them at another one before deleting it.', 'fluent-booking'), $usage),
                ['usage_count' => $usage]
            );
        }

        $tool        = 'fluent-booking/manage-availability';
        $entityKey   = 'availability:' . $schedule->id . ':delete';
        $fingerprint = self::scheduleFingerprint($schedule);
        $digest      = WriteGuard::paramsDigest($params);

        if (Arr::isTrue($params, 'dry_run')) {
            return MCPHelper::success(WriteGuard::preview($tool, $entityKey, $fingerprint, [
                'action'   => 'delete',
                'schedule' => self::scheduleSummary($schedule),
                'note'     => __('Nothing currently uses this schedule.', 'fluent-booking'),
            ], $digest), [], WriteGuard::CONFIRM_NEXT_STEP);
        }

        $confirmed = WriteGuard::confirm($tool, $entityKey, $fingerprint, Arr::get($params, 'confirm_token', ''), $digest);

        if (is_wp_error($confirmed)) {
            return $confirmed;
        }

        return self::bridged('DELETE', '/availability/' . $schedule->id);
    }

    /**
     * @param array $params
     * @return array|\WP_Error
     */
    public static function manageEventType($params = [])
    {
        return self::deduped('fluent-booking/manage-event-type', $params, function () use ($params) {
            return self::manageEventTypeAction($params);
        }, function ($params) {
            $eventId = absint(Arr::get($params, 'event_id'));
            $action  = sanitize_text_field(Arr::get($params, 'action', ''));

            if ($eventId) {
                return 'event:' . $eventId . ':' . $action . ':' . sanitize_text_field(Arr::get($params, 'section', ''));
            }

            $title = (string) Arr::get($params, 'fields.title', '');

            return 'event:new:' . absint(Arr::get($params, 'calendar_id')) . ':' . md5(strtolower(trim($title)));
        });
    }

    /**
     * Give the scheduling writes the same retry safety the booking writes have.
     *
     * Without it a retried create or clone leaves two live bookable records
     * and nothing detects it — and because wrapExecuteCallback() rejects
     * undeclared parameters, an agent could not even opt in.
     *
     * @param string   $tool
     * @param array    $params
     * @param callable $fn
     * @param callable $entityKey
     *
     * @return mixed
     */
    private static function deduped($tool, $params, callable $fn, callable $entityKey)
    {
        $key = (string) Arr::get($params, 'idempotency_key', '');

        // A dry run changes nothing, so there is nothing to deduplicate — and
        // recording one would replay a preview in place of the real write.
        if (!$key || Arr::isTrue($params, 'dry_run')) {
            return $fn();
        }

        return WriteGuard::idempotent(
            $tool,
            call_user_func($entityKey, $params),
            $key,
            $fn,
            WriteGuard::paramsDigest($params)
        );
    }

    /**
     * @param array $params
     * @return array|\WP_Error
     */
    private static function manageEventTypeAction($params)
    {
        $action = sanitize_text_field(Arr::get($params, 'action', ''));
        $fields = (array) Arr::get($params, 'fields', []);
        $dryRun = Arr::isTrue($params, 'dry_run');

        if ($action === 'create') {
            $calendarId = absint(Arr::get($params, 'calendar_id'));

            if (!$calendarId) {
                return MCPHelper::error('missing_calendar_id', __('create needs calendar_id. Call list-reference-data with kinds:["calendars"].', 'fluent-booking'));
            }

            if (!PermissionManager::canWriteCalendar($calendarId)) {
                return MCPHelper::error('permission_denied', __('You do not have permission to add event types to this calendar.', 'fluent-booking'));
            }

            $payload = self::createPayload($calendarId, $fields);

            if (is_wp_error($payload)) {
                return $payload;
            }

            if ($dryRun) {
                return self::additivePreview('create', [
                    'calendar_id' => $calendarId,
                    'title'       => Arr::get($payload, 'title', ''),
                    'duration'    => (int) Arr::get($payload, 'duration', 0),
                    'event_type'  => Arr::get($payload, 'event_type', ''),
                    'status'      => Arr::get($payload, 'status', ''),
                    'locations'   => array_values(array_filter(array_map(function ($location) {
                        return Arr::get($location, 'type');
                    }, (array) Arr::get($payload, 'location_settings', [])))),
                ]);
            }

            return self::bridged('POST', '/calendars/' . $calendarId . '/events', $payload);
        }

        $eventId = absint(Arr::get($params, 'event_id'));

        if (!$eventId) {
            return MCPHelper::error('missing_event_id', __('event_id is required. Call get-event-types to find one.', 'fluent-booking'));
        }

        $event = CalendarSlot::find($eventId);

        if (!$event) {
            return MCPHelper::error('not_found', __('No event type with that id.', 'fluent-booking'));
        }

        // Gate here, not at the bridge. RestBridge's policy does refuse the
        // write, but a dry_run returns its preview before ever reaching the
        // bridge — so without this check the preview would hand an event
        // type's title, status and upcoming booking count to a caller with no
        // write access to it, along with a confirm_token implying they may
        // proceed.
        if (!PermissionManager::canWriteCalendar($event->calendar_id)) {
            return MCPHelper::error(
                'permission_denied',
                __('You do not have permission to change this event type.', 'fluent-booking'),
                ['event_id' => $eventId]
            );
        }

        $calendarId = (int) $event->calendar_id;
        $base       = '/calendars/' . $calendarId . '/events/' . $eventId;

        if ($action === 'activate' || $action === 'deactivate') {
            $status = $action === 'activate' ? 'active' : 'draft';

            if ($dryRun) {
                $preview = [
                    'event'  => self::eventSummary($event),
                    'status' => ['from' => $event->status, 'to' => $status],
                ];

                if ($action === 'deactivate') {
                    // Deactivating is reversible as a database change, but it
                    // takes a live booking page offline, so the count of what is
                    // about to stop being bookable belongs in the preview.
                    $preview['effect'] = __('The public booking page stops offering slots immediately. Existing bookings are untouched.', 'fluent-booking');
                }

                return self::reversiblePreview($action, $preview);
            }

            return self::bridged('PUT', $base, ['status' => $status]);
        }

        if ($action === 'duplicate') {
            $targetCalendar = absint(Arr::get($params, 'calendar_id')) ?: $calendarId;

            // The duplicate lands on whatever calendar_id was passed, which is
            // not necessarily the one the permission check above covered.
            if ($targetCalendar !== $calendarId && !PermissionManager::canWriteCalendar($targetCalendar)) {
                return MCPHelper::error(
                    'permission_denied',
                    __('You do not have permission to add event types to the destination calendar.', 'fluent-booking'),
                    ['calendar_id' => $targetCalendar]
                );
            }

            if ($dryRun) {
                return self::additivePreview('duplicate', [
                    'source'               => self::eventSummary($event),
                    'destination_calendar' => $targetCalendar,
                ]);
            }

            return self::bridged('POST', '/calendars/' . $calendarId . '/clone-event/' . $eventId, [
                'new_calendar_id' => $targetCalendar,
            ]);
        }

        if ($action === 'delete') {
            return self::deleteEventType($event, $params);
        }

        if ($action === 'update') {
            $section = sanitize_text_field(Arr::get($params, 'section', ''));

            $routes = [
                'details'        => $base . '/details',
                'availability'   => $base . '/availability',
                'limits'         => $base . '/limits',
                'booking_fields' => $base . '/booking-fields',
            ];

            if (!isset($routes[$section])) {
                return MCPHelper::error(
                    'missing_section',
                    /* translators: %s: accepted section names */
                    sprintf(__('update needs a section. One of: %s.', 'fluent-booking'), implode(', ', array_keys($routes)))
                );
            }

            if (!$fields) {
                return MCPHelper::error('missing_fields', __('update needs fields. Call get-event-types with event_id to see the current values.', 'fluent-booking'));
            }

            $payload = self::sectionPayload($event, $section, $fields);

            if (is_wp_error($payload)) {
                return $payload;
            }

            // These two rebuild a whole settings block, and an availability
            // write replaces the weekly grid outright — the shape
            // manage-availability update is already gated for.
            if (!in_array($section, ['availability', 'limits'], true)) {
                if ($dryRun) {
                    return self::reversiblePreview('update', [
                        'event'    => self::eventSummary($event),
                        'section'  => $section,
                        'changing' => array_keys($fields),
                    ]);
                }

                return self::bridged('POST', $routes[$section], $payload);
            }

            $tool        = 'fluent-booking/manage-event-type';
            $entityKey   = 'event:' . $eventId . ':update:' . $section;
            $fingerprint = self::eventFingerprint($event);
            $digest      = WriteGuard::paramsDigest($params);

            if ($dryRun) {
                return MCPHelper::success(WriteGuard::preview($tool, $entityKey, $fingerprint, [
                    'action'   => 'update',
                    'event'    => self::eventSummary($event),
                    'section'  => $section,
                    'changing' => array_keys($fields),
                    'writing'  => $payload,
                    'note'     => __('Keys you do not send keep their current values. weekly_schedules and date_overrides are replaced whole, so days you omit from them become unavailable.', 'fluent-booking'),
                ], $digest), [], WriteGuard::CONFIRM_NEXT_STEP);
            }

            $confirmed = WriteGuard::confirm($tool, $entityKey, $fingerprint, Arr::get($params, 'confirm_token', ''), $digest);

            if (is_wp_error($confirmed)) {
                return $confirmed;
            }

            return self::bridged('POST', $routes[$section], $payload);
        }

        return MCPHelper::error('unsupported_action', __('Unknown action.', 'fluent-booking'));
    }

    /**
     * Translate a section write from the vocabulary get-event-types PROJECTS
     * into the shape the admin controller reads, over the event's current
     * values.
     *
     * The tool tells the agent to read a section and send it back changed, but
     * the projection uses friendly names (`buffer_before_minutes`) and the
     * controllers read the admin SPA's shape (`settings.buffer_time_before`).
     * Forwarded verbatim, the controller found none of its keys and wrote its
     * own defaults over all of them — dropping the requested change and
     * resetting the rest. Seeding from stored values also keeps a partial write
     * partial, since the controllers rebuild their whole key set on every POST.
     *
     * @param CalendarSlot $event
     * @param string       $section
     * @param array        $fields
     * @return array|\WP_Error
     */
    private static function sectionPayload(CalendarSlot $event, $section, $fields)
    {
        if ($section === 'details') {
            return self::detailsPayload($event, $fields);
        }

        if ($section === 'limits') {
            return self::limitsPayload($event, $fields);
        }

        if ($section === 'availability') {
            return self::availabilityPayload($event, $fields);
        }

        return self::bookingFieldsPayload($event, $fields);
    }

    /**
     * An event type with no location cannot be booked, so neither path may
     * leave one in that state.
     *
     * @param mixed $locations
     *
     * @return true|\WP_Error
     */
    private static function validateLocations($locations)
    {
        $locations = (array) $locations;

        if (!$locations || !Arr::get($locations, '0.type')) {
            return MCPHelper::error(
                'location_required',
                __('An event type needs at least one location, e.g. location_settings: [{"type":"online_meeting"}]. Call list-reference-data with kinds:["location_providers"] for the options.', 'fluent-booking')
            );
        }

        return true;
    }

    /**
     * Every key updateEventDetails() rebuilds, seeded from what is stored.
     *
     * It validates only title, duration and status and absorbs the rest with
     * defaults, so a write sending just those three set max_book_per_slot to 0,
     * reset color_schema, emptied description and — worst — wiped
     * location_settings, leaving an event that cannot be booked at all.
     * Seeding also makes a partial write possible: the three "required" fields
     * come from storage when the caller does not send them.
     *
     * @return array|\WP_Error
     */
    private static function detailsPayload(CalendarSlot $event, $fields)
    {
        $settings = (array) $event->settings;

        $known = [
            'title', 'duration', 'status', 'color_schema', 'description',
            'max_book_per_slot', 'is_display_spots', 'location_settings',
            'multi_duration',
        ];

        if ($unknown = array_diff(array_keys($fields), $known)) {
            return self::unknownSectionFields('details', $unknown, $known);
        }

        $out = [
            'title'             => $event->title,
            'duration'          => (int) $event->duration,
            'status'            => $event->status,
            'color_schema'      => $event->color_schema ?: '#0099ff',
            'description'       => $event->getDescription(),
            'max_book_per_slot' => (int) $event->max_book_per_slot,
            'is_display_spots'  => (bool) $event->is_display_spots,
            'location_settings' => (array) $event->location_settings,
            'multi_duration'    => Arr::get($settings, 'multi_duration', [
                'enabled'             => false,
                'default_duration'    => '',
                'available_durations' => [],
            ]),
        ];

        // Only when the caller SENDS it: omitting it keeps what is stored, but
        // sending an empty list would clear the last location, and create
        // refuses that same state.
        if (array_key_exists('location_settings', $fields)
            && is_wp_error($locationError = self::validateLocations($fields['location_settings']))) {
            return $locationError;
        }

        foreach ($known as $key) {
            if (array_key_exists($key, $fields)) {
                $out[$key] = $fields[$key];
            }
        }

        return $out;
    }

    /**
     * @return array|\WP_Error
     */
    private static function limitsPayload(CalendarSlot $event, $fields)
    {
        $settings = (array) $event->settings;

        // Every key updateEventLimits() rebuilds, seeded from what is stored.
        $out = [
            'schedule_conditions' => Arr::get($settings, 'schedule_conditions', ['value' => 4, 'unit' => 'hours']),
            'buffer_time_before'  => (string) Arr::get($settings, 'buffer_time_before', '0'),
            'buffer_time_after'   => (string) Arr::get($settings, 'buffer_time_after', '0'),
            'slot_interval'       => (string) Arr::get($settings, 'slot_interval', ''),
            'booking_frequency'   => Arr::get($settings, 'booking_frequency', ['enabled' => false, 'limits' => []]),
            'booking_duration'    => Arr::get($settings, 'booking_duration', ['enabled' => false, 'limits' => []]),
            'lock_timezone'       => Arr::get($settings, 'lock_timezone', ['enabled' => false, 'timezone' => '']),
        ];

        $known = [
            'buffer_before_minutes', 'buffer_after_minutes', 'slot_interval_minutes',
            'minimum_notice_minutes', 'booking_frequency', 'booking_duration',
            'lock_timezone', 'settings',
        ];

        if ($unknown = array_diff(array_keys($fields), $known)) {
            return self::unknownSectionFields('limits', $unknown, $known);
        }

        if (array_key_exists('buffer_before_minutes', $fields)) {
            $out['buffer_time_before'] = (string) absint($fields['buffer_before_minutes']);
        }

        if (array_key_exists('buffer_after_minutes', $fields)) {
            $out['buffer_time_after'] = (string) absint($fields['buffer_after_minutes']);
        }

        if (array_key_exists('slot_interval_minutes', $fields)) {
            $out['slot_interval'] = (string) absint($fields['slot_interval_minutes']);
        }

        if (array_key_exists('minimum_notice_minutes', $fields)) {
            // Stored as a {value, unit} pair; the projection reports minutes.
            $out['schedule_conditions'] = [
                'value' => absint($fields['minimum_notice_minutes']),
                'unit'  => 'minutes',
            ];
        }

        foreach (['booking_frequency', 'booking_duration'] as $cap) {
            if (array_key_exists($cap, $fields)) {
                $out[$cap] = self::capPayload($fields[$cap]);
            }
        }

        if (array_key_exists('lock_timezone', $fields)) {
            $out['lock_timezone'] = (array) $fields['lock_timezone'];
        }

        // Escape hatch for a caller that already speaks the controller's shape.
        $out = array_merge($out, (array) Arr::get($fields, 'settings', []));

        return ['settings' => $out];
    }

    /**
     * Rebuild a cap block from the by-unit map the projection returns.
     *
     * capLimits() re-keys the stored {unit, value} LIST into a map so an agent
     * can look up `per_day`; the controller reads the list back. Without the
     * inverse, sending a read cap block back writes an empty cap.
     *
     * @param mixed $cap
     * @return array
     */
    private static function capPayload($cap)
    {
        $cap = (array) $cap;

        $limits = Arr::get($cap, 'limits', []);

        // Already a list of {unit, value}: pass it through.
        if (isset($limits[0])) {
            return ['enabled' => Arr::isTrue($cap, 'enabled'), 'limits' => $limits];
        }

        $rebuilt = [];

        foreach ((array) $limits as $unit => $value) {
            $rebuilt[] = ['unit' => sanitize_text_field($unit), 'value' => (int) $value];
        }

        // Some shapes carry the pairs on the block itself.
        if (!$rebuilt) {
            foreach ($cap as $unit => $value) {
                if ($unit !== 'enabled' && is_scalar($value)) {
                    $rebuilt[] = ['unit' => sanitize_text_field($unit), 'value' => (int) $value];
                }
            }
        }

        return ['enabled' => Arr::isTrue($cap, 'enabled'), 'limits' => $rebuilt];
    }

    /**
     * @return array|\WP_Error
     */
    private static function availabilityPayload(CalendarSlot $event, $fields)
    {
        $known = ['type', 'schedule_id', 'range_type', 'range_days', 'weekly_schedules', 'date_overrides'];

        if ($unknown = array_diff(array_keys($fields), $known)) {
            return self::unknownSectionFields('availability', $unknown, $known);
        }

        $settings = (array) $event->settings;
        $timezone = $event->calendar ? $event->calendar->author_timezone : 'UTC';

        // The controller converts the grid it receives from the author timezone
        // into UTC, and what is stored is already UTC. Send it back through the
        // inverse first, or every slot slides by the offset.
        $weekly = SanitizeService::weeklySchedules(
            (array) Arr::get($settings, 'weekly_schedules', []),
            'UTC',
            $timezone,
            true
        );

        $out = [
            'schedule_type'      => Arr::get($settings, 'schedule_type', 'weekly_schedules'),
            'weekly_schedules'   => $weekly,
            'date_overrides'     => Arr::get($settings, 'date_overrides', []),
            'range_type'         => Arr::get($settings, 'range_type', 'range_days'),
            'range_days'         => (int) Arr::get($settings, 'range_days', 60),
            'range_date_between' => Arr::get($settings, 'range_date_between', ['', '']),
            'common_schedule'    => Arr::isTrue($settings, 'common_schedule'),
            'availability_type'  => $event->availability_type,
            'availability_id'    => (int) $event->availability_id,
        ];

        if (array_key_exists('type', $fields)) {
            $out['availability_type'] = sanitize_text_field($fields['type']);
        }

        if (array_key_exists('schedule_id', $fields)) {
            $scheduleId = absint($fields['schedule_id']);

            // Bind only a schedule the caller may read. The controller assigns
            // availability_id with no ownership test and Availability has no
            // owner scope, so an unchecked id here binds another host's
            // schedule to this event — after which diagnose-availability and
            // get-available-slots read its weekly grid and timezone straight
            // back out, through a tool that would have refused the id.
            if ($scheduleId) {
                $schedule = Availability::find($scheduleId);

                if (!$schedule) {
                    return MCPHelper::error(
                        'not_found',
                        __('No availability schedule with that id.', 'fluent-booking'),
                        ['schedule_id' => $scheduleId]
                    );
                }

                if (!self::canReadSchedule($schedule)) {
                    return MCPHelper::error(
                        'permission_denied',
                        __('You do not have permission to use this availability schedule.', 'fluent-booking'),
                        ['schedule_id' => $scheduleId]
                    );
                }
            }

            $out['availability_id'] = $scheduleId;
        }

        foreach (['range_type', 'range_days', 'weekly_schedules', 'date_overrides'] as $key) {
            if (array_key_exists($key, $fields)) {
                $out[$key] = $fields[$key];
            }
        }

        return $out;
    }

    /**
     * @return array|\WP_Error
     */
    private static function bookingFieldsPayload(CalendarSlot $event, $fields)
    {
        $incoming = Arr::get($fields, 'booking_fields');

        if (!is_array($incoming) || !$incoming) {
            return MCPHelper::error(
                'missing_booking_fields',
                __('booking_fields must be a list of fields. Call get-event-types with event_id to see the current ones.', 'fluent-booking')
            );
        }

        $stored = (array) $event->getBookingFields();

        // Match on NAME and edit in place. saveEventBookingFields() replaces the
        // whole set and mints a name for any entry lacking one, so returning the
        // projection — which renames `name` to `key` — appended a second copy of
        // every question instead of updating the originals.
        $byName = [];

        foreach ($stored as $key => $field) {
            if (is_array($field)) {
                $byName[(string) Arr::get($field, 'name', $key)] = $field;
            }
        }

        foreach ($incoming as $field) {
            $field = (array) $field;
            $name  = (string) (Arr::get($field, 'name') ?: Arr::get($field, 'key', ''));

            if (!$name) {
                // A genuinely new field: let the controller name it.
                $byName[] = $field;
                continue;
            }

            unset($field['key']);
            $field['name'] = $name;

            $byName[$name] = isset($byName[$name])
                ? array_merge($byName[$name], $field)
                : $field;
        }

        return ['booking_fields' => array_values($byName)];
    }

    /**
     * @param string $section
     * @param array  $unknown
     * @param array  $known
     * @return \WP_Error
     */
    private static function unknownSectionFields($section, $unknown, $known)
    {
        return MCPHelper::error(
            'unknown_section_fields',
            sprintf(
                /* translators: 1: section name, 2: rejected keys, 3: accepted keys */
                __('The %1$s section does not accept: %2$s. It accepts: %3$s.', 'fluent-booking'),
                $section,
                implode(', ', $unknown),
                implode(', ', $known)
            )
        );
    }

    /**
     * @return array
     */
    private static function eventSummary(CalendarSlot $event)
    {
        return [
            'id'          => (int) $event->id,
            'calendar_id' => (int) $event->calendar_id,
            'title'       => $event->title,
            'status'      => $event->status,
            'event_type'  => $event->event_type,
        ];
    }

    /**
     * An event type is not three fields. The controller reads a full settings
     * block — schedule type, weekly hours, range, buffers — straight out of the
     * payload, and an agent that sent only a title would create a broken event
     * or trip an undefined-index. So start from exactly what the admin's own
     * "new event type" screen starts from, `CalendarSlot::getEventSchema()`,
     * and lay the agent's fields over it. An agent can then create a working
     * event type with a title, a duration and a location, which is what it
     * would expect to need.
     *
     * @return array|\WP_Error
     */
    private static function createPayload($calendarId, $fields)
    {
        $calendar = Calendar::find($calendarId);

        if (!$calendar) {
            return MCPHelper::error('not_found', __('No calendar with that id.', 'fluent-booking'));
        }

        $schema = (new CalendarSlot())->getEventSchema($calendar);

        // The schema embeds the calendar for the UI to render; it is not a
        // field on the event type.
        unset($schema['calendar']);

        if (is_wp_error($locationError = self::validateLocations(Arr::get($fields, 'location_settings', [])))) {
            return $locationError;
        }

        // The admin controller refuses these too, but in its own vocabulary —
        // "Event type field is required" names neither the tool's parameter nor
        // what a valid value looks like. Refuse here, in the terms the schema
        // uses, before bridging.
        $required = [
            'title'      => __('a name for the event type', 'fluent-booking'),
            'duration'   => __('its length in minutes, e.g. 30', 'fluent-booking'),
            'event_type' => sprintf(
                /* translators: %s: the accepted event types */
                __('one of: %s', 'fluent-booking'),
                implode(', ', ContextTools::eventTypes())
            ),
        ];

        foreach ($required as $field => $expected) {
            if (Arr::get($fields, $field) === null || Arr::get($fields, $field) === '') {
                return MCPHelper::error(
                    'missing_field',
                    /* translators: %1$s: the missing field name, %2$s: what it expects */
                    sprintf(__('create needs fields.%1$s — %2$s.', 'fluent-booking'), $field, $expected),
                    ['field' => $field, 'required' => array_keys($required)]
                );
            }
        }

        $payload = array_merge($schema, $fields);

        // Merge one level into settings rather than replacing it, so an agent
        // changing a buffer does not wipe the weekly hours it never saw.
        $payload['settings'] = array_merge(
            (array) Arr::get($schema, 'settings', []),
            (array) Arr::get($fields, 'settings', [])
        );

        return $payload;
    }

    /**
     * Deleting an event type takes its bookings with it. The admin has no guard
     * against that — a human doing it has the schedule on screen and knows what
     * they are throwing away. An agent does not, so it has to be told, and has
     * to say `force` to proceed anyway.
     *
     * @return array|\WP_Error
     */
    private static function deleteEventType(CalendarSlot $event, $params)
    {
        // CalenderEventCleaner deletes EVERY booking on the event — no status
        // filter, no date filter — along with their activities and, in pro,
        // their orders and transactions. Counting only the upcoming ones let an
        // event with years of completed bookings delete without force, under a
        // preview that said nothing would be affected.
        $byStatus = Booking::where('event_id', $event->id)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS total')
            ->pluck('total', 'status')
            ->toArray();

        $byStatus = array_map('intval', (array) $byStatus);
        $total    = array_sum($byStatus);

        $upcoming = Booking::where('event_id', $event->id)
            ->whereIn('status', ['scheduled', 'rescheduled', 'pending'])
            ->where('start_time', '>=', gmdate('Y-m-d H:i:s')) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            ->count();

        if ($upcoming && !Arr::isTrue($params, 'force')) {
            return MCPHelper::error(
                'has_future_bookings',
                /* translators: %1$d: upcoming bookings, %2$d: bookings in total */
                sprintf(__('%1$d upcoming bookings would be deleted with this event type, and %2$d bookings in total including past and cancelled ones. Cancel or move them first, or pass force to delete them too.', 'fluent-booking'), $upcoming, $total),
                ['upcoming_bookings' => $upcoming, 'total_bookings' => $total, 'bookings_by_status' => $byStatus]
            );
        }

        if ($total && !Arr::isTrue($params, 'force')) {
            return MCPHelper::error(
                'has_bookings',
                /* translators: %d: bookings in total */
                sprintf(__('%d past or cancelled bookings would be deleted with this event type, along with their activity history and any payment records. Nothing here is recoverable. Pass force to proceed.', 'fluent-booking'), $total),
                ['upcoming_bookings' => 0, 'total_bookings' => $total, 'bookings_by_status' => $byStatus]
            );
        }

        $tool      = 'fluent-booking/manage-event-type';
        $entityKey = 'event:' . $event->id . ':delete';
        $digest    = WriteGuard::paramsDigest($params);

        $fingerprint = self::eventFingerprint($event) . '|' . $upcoming . '|' . $total;

        if (Arr::isTrue($params, 'dry_run')) {
            return MCPHelper::success(WriteGuard::preview($tool, $entityKey, $fingerprint, [
                'action'             => 'delete',
                'event'              => self::eventSummary($event),
                'upcoming_bookings'  => $upcoming,
                'total_bookings'     => $total,
                'bookings_by_status' => $byStatus,
                'note'               => $total
                    ? __('Every booking on this event type is deleted with it — past, cancelled and upcoming alike — along with their activity history and any payment records. Attendees are not notified.', 'fluent-booking')
                    : __('This event type has no bookings, so nothing else is removed with it.', 'fluent-booking'),
            ], $digest), [], WriteGuard::CONFIRM_NEXT_STEP);
        }

        $confirmed = WriteGuard::confirm($tool, $entityKey, $fingerprint, Arr::get($params, 'confirm_token', ''), $digest);

        if (is_wp_error($confirmed)) {
            return $confirmed;
        }

        return self::bridged('DELETE', '/calendars/' . $event->calendar_id . '/events/' . $event->id);
    }

    /**
     * @param array $params
     * @return array|\WP_Error
     */
    public static function listReferenceData($params = [])
    {
        $kinds = array_values(array_filter((array) Arr::get($params, 'kinds', [])));

        $unknown = array_diff($kinds, self::REFERENCE_KINDS);

        if ($unknown) {
            return MCPHelper::error(
                'unknown_kind',
                /* translators: %1$s: rejected kinds, %2$s: accepted kinds */
                sprintf(__('Unknown kinds: %1$s. Available: %2$s.', 'fluent-booking'), implode(', ', $unknown), implode(', ', self::REFERENCE_KINDS))
            );
        }

        if (!$kinds) {
            return MCPHelper::error('missing_kinds', __('Name at least one kind.', 'fluent-booking'));
        }

        $data = [];

        foreach ($kinds as $kind) {
            $result = self::referenceKind($kind, $params);

            if (is_wp_error($result)) {
                return $result;
            }

            $data[$kind] = self::referenceEnvelope($result);
        }

        return MCPHelper::success($data, ['scope' => PermissionGate::currentScope()]);
    }

    /**
     * Give every reference list the same shape, and say when it was cut short.
     *
     * The cap used to be applied bare: a 150-calendar site got 100 rows with
     * nothing marking them as a page, so an agent concluded the other 50 did
     * not exist.
     *
     * @param array $result [$rows, $total]
     *
     * @return array
     */
    private static function referenceEnvelope($result)
    {
        list($items, $total) = $result;

        $envelope = [
            'items' => $items,
            'total' => (int) $total,
        ];

        if ($total > count($items)) {
            $envelope['truncated']       = true;
            $envelope['truncation_note'] = sprintf(
                /* translators: 1: number returned, 2: number that exist */
                __('Showing %1$d of %2$d. Narrow the request; this list is capped.', 'fluent-booking'),
                count($items),
                (int) $total
            );
        }

        return $envelope;
    }

    /**
     * @return array|\WP_Error [$rows, $total]
     */
    private static function referenceKind($kind, $params)
    {
        if ($kind === 'calendars') {
            // PermissionGate::scopeToReadableCalendars() rather than a local
            // user_id filter: the event-type tools gate on canReadCalendar(),
            // which also admits shared team calendars, and a reference list
            // that omits a calendar those tools will happily read leaves an
            // agent unable to name an id it is allowed to use.
            $query = PermissionGate::scopeToReadableCalendars(Calendar::orderBy('id', 'asc'), 'id');

            $total = (clone $query)->count();
            $rows  = [];

            foreach ($query->limit(self::LIST_LIMIT)->get() as $calendar) {
                $rows[] = [
                    'id'       => (int) $calendar->id,
                    'title'    => $calendar->title,
                    'slug'     => $calendar->slug,
                    'type'     => $calendar->type,
                    'host_id'  => (int) $calendar->user_id,
                    'timezone' => $calendar->author_timezone,
                ];
            }

            return [$rows, $total];
        }

        if ($kind === 'hosts') {
            // Hosts are whoever owns a calendar. Enumerating WP users instead
            // would leak every account on the site into an agent's context.
            $query = PermissionGate::scopeToReadableCalendars(Calendar::query(), 'id');

            // A calendar can outlive the user who owned it, and a deleted owner
            // is not a host. Excluded in SQL so the total stays honest without
            // reading every calendar into PHP to find out.
            $query->whereIn('user_id', User::select('ID'));

            $total = (clone $query)->distinct()->count('user_id');

            // Distinct owners, capped in SQL and ordered by the first calendar
            // each appears on: deriving hosts from a capped page of calendars
            // could drop one entirely and still read as a complete list, but
            // reading every calendar to find LIST_LIMIT hosts scales with the
            // table rather than the answer.
            $userIds = array_map('intval', (clone $query)
                ->groupBy('user_id')
                ->orderByRaw('min(id) asc')
                ->limit(self::LIST_LIMIT)
                ->pluck('user_id')
                ->toArray());

            // One query for the lot rather than one per host.
            if ($userIds) {
                cache_users($userIds);
            }

            $rows = [];

            foreach ($userIds as $userId) {
                $user = get_userdata($userId);

                if (!$user) {
                    continue;
                }

                $rows[] = [
                    'id'    => (int) $userId,
                    'name'  => MCPHelper::untrusted($user->display_name, 200),
                    'email' => PermissionGate::canSeeAllBookings() ? $user->user_email : MCPHelper::maskEmail($user->user_email),
                ];
            }

            return [$rows, $total];
        }

        if ($kind === 'availability_schedules') {
            $query = Availability::orderBy('id', 'desc');

            if (!PermissionManager::userCan(['manage_all_data', 'read_and_use_other_availabilities', 'manage_other_availabilities'])) {
                $query->where('object_id', get_current_user_id());
            }

            $total = (clone $query)->count();
            $rows  = [];

            foreach ($query->limit(self::LIST_LIMIT)->get() as $schedule) {
                $rows[] = [
                    'id'       => (int) $schedule->id,
                    'title'    => $schedule->key,
                    'host_id'  => (int) $schedule->object_id,
                    'default'  => Arr::isTrue($schedule, 'value.default'),
                    'timezone' => Arr::get($schedule, 'value.timezone', 'UTC'),
                ];
            }

            return [$rows, $total];
        }

        if ($kind === 'location_providers') {
            $rows = [];

            // The registry is grouped for the admin's location picker; flatten
            // it, and keep `disabled` so an agent can see which providers are
            // Pro-only rather than trying one and getting a validation error.
            foreach ((new CalendarSlot())->getLocationFields() as $group) {
                foreach ((array) Arr::get($group, 'options', []) as $type => $option) {
                    $rows[] = [
                        'type'     => $type,
                        'title'    => Arr::get($option, 'title', ''),
                        'group'    => Arr::get($group, 'label', ''),
                        'disabled' => Arr::isTrue($option, 'disabled'),
                    ];
                }
            }

            return [$rows, count($rows)];
        }

        // booking_fields
        $eventId = absint(Arr::get($params, 'event_id'));

        if (!$eventId) {
            return MCPHelper::error('missing_event_id', __('booking_fields are per event type, so event_id is required.', 'fluent-booking'));
        }

        $event = CalendarSlot::find($eventId);

        if (!$event) {
            return MCPHelper::error('not_found', __('No event type with that id.', 'fluent-booking'));
        }

        if (!PermissionManager::canReadCalendar($event->calendar_id)) {
            return MCPHelper::error('permission_denied', __('You do not have permission to read this event type.', 'fluent-booking'));
        }

        $rows = [];

        // getBookingFields(), not getMeta('booking_fields'): the accessor
        // merges the built-in fields over the stored ones, and get-event-types
        // already reads it. Two sources for one answer drift.
        foreach ((array) $event->getBookingFields() as $field) {
            if (!is_array($field)) {
                continue;
            }

            $rows[] = [
                'name'     => Arr::get($field, 'name'),
                'label'    => Arr::get($field, 'label'),
                'type'     => Arr::get($field, 'type'),
                'required' => Arr::isTrue($field, 'required'),
                'enabled'  => Arr::isTrue($field, 'enabled'),
            ];
        }

        return [$rows, count($rows)];
    }

    /**
     * Usage counts for a page of schedules in one grouped query rather than one
     * count per row: the list returns up to LIST_LIMIT of them.
     *
     * @param array $scheduleIds
     *
     * @return array schedule id => count
     */
    private static function usageCounts($scheduleIds)
    {
        if (!$scheduleIds) {
            return [];
        }

        $rows = CalendarSlot::where('availability_type', 'existing_schedule')
            ->whereIn('availability_id', $scheduleIds)
            ->selectRaw('availability_id, COUNT(*) as usage_count')
            ->groupBy('availability_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->availability_id] = (int) $row->usage_count;
        }

        return $counts;
    }

    /**
     * @return array
     */
    private static function projectSchedule(Availability $schedule, $timezone, $full, $usageCount = null)
    {
        $own = Arr::get($schedule, 'value.timezone', 'UTC');

        // Only honour a requested zone we can actually render in. Reporting a
        // timezone the hours are NOT expressed in is worse than ignoring the
        // parameter: the list used to stamp every schedule with whatever the
        // caller asked for while returning each one's own stored hours, so an
        // agent asking for Asia/Tokyo was told the whole site ran on Tokyo time.
        $requested = $timezone && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : '';

        $row = [
            'id'             => (int) $schedule->id,
            'title'          => $schedule->key,
            'host_id'        => (int) $schedule->object_id,
            'default'        => Arr::isTrue($schedule, 'value.default'),
            // The zone the hours below are in. On the summary row there are no
            // hours, so this is always the schedule's own.
            'timezone'       => $full && $requested ? $requested : $own,
            // The list hands its count in; a single schedule looks its own up.
            'usage_count'    => $usageCount === null
                ? AvailabilityService::getAvailabilityUsageCount($schedule->id)
                : (int) $usageCount,
        ];

        if (!$full) {
            return $row;
        }

        // Hours are stored in UTC; getFormattedSchedule() renders them in the
        // schedule's own zone. Rendering them in the caller's is the same
        // conversion with a different target, so do it properly rather than
        // return a mislabelled grid.
        if ($requested && $requested !== $own) {
            $row['weekly_schedules'] = SanitizeService::weeklySchedules(Arr::get($schedule, 'value.weekly_schedules', []), 'UTC', $requested);
            $row['date_overrides'] = SanitizeService::slotDateOverrides(Arr::get($schedule, 'value.date_overrides', []), 'UTC', $requested);
            $row['stored_timezone'] = $own;
            $row['note'] = sprintf(
                /* translators: 1: requested timezone, 2: the schedule's own timezone */
                __('Hours converted to %1$s. The schedule is configured in %2$s — send edits back in its own zone, or pass the same timezone again.', 'fluent-booking'),
                $requested,
                $own
            );

            return $row;
        }

        $formatted = AvailabilityService::getFormattedSchedule($schedule);

        // The admin shape carries a gravatar URL — a hundred bytes of nothing,
        // on every row, forever.
        $row['weekly_schedules'] = Arr::get($formatted, 'settings.weekly_schedules', []);
        $row['date_overrides'] = Arr::get($formatted, 'settings.date_overrides', []);

        return $row;
    }

    /**
     * Reading someone else's schedule is a lesser privilege than editing it:
     * `read_and_use_other_availabilities` exists precisely so a host can point
     * an event type at a colleague's hours without being able to change them.
     *
     * @return bool
     */
    private static function canWriteSchedule(Availability $schedule)
    {
        if ((int) $schedule->object_id === get_current_user_id()) {
            return true;
        }

        return PermissionManager::userCan(['manage_all_data', 'manage_other_availabilities']);
    }

    /**
     * @return bool
     */
    private static function canReadSchedule(Availability $schedule)
    {
        if ((int) $schedule->object_id === get_current_user_id()) {
            return true;
        }

        return PermissionManager::userCan(['manage_all_data', 'read_and_use_other_availabilities', 'manage_other_availabilities']);
    }

    /**
     * @return array|\WP_Error
     */
    private static function bridged($method, $route, $body = [])
    {
        $result = RestBridge::call($method, $route, $body);

        if (is_wp_error($result)) {
            return $result;
        }

        // Admin responses carry model dumps for the SPA to re-render. Keep the
        // message and the identity; drop the rest.
        $out = ['message' => Arr::get($result, 'message', __('Done.', 'fluent-booking'))];

        foreach (['schedule', 'slot', 'calendar_event', 'event'] as $key) {
            $record = Arr::get($result, $key);

            if (!$record) {
                continue;
            }

            // Models come back as objects, not arrays; casting one to an array
            // does not surface its attributes, so read the property first.
            $out['id'] = is_object($record) ? (int) $record->id : (int) Arr::get((array) $record, 'id', 0);
            break;
        }

        return MCPHelper::success($out);
    }
}
