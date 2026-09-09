<?php

namespace FluentBooking\App\Modules\MCP\Tools;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Modules\MCP\Support\BookingProjector;
use FluentBooking\App\Modules\MCP\Support\BookingWriter;
use FluentBooking\App\Modules\MCP\Support\MCPHelper;
use FluentBooking\App\Modules\MCP\Support\PermissionGate;
use FluentBooking\App\Modules\MCP\Support\SlotResolver;
use FluentBooking\App\Modules\MCP\Support\WriteGuard;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * The two tools that change something.
 *
 * `create-booking` stands alone because its parameter shape — attendee details,
 * custom fields, guests, location — shares nothing with the others. Everything
 * that acts on a booking that already exists goes through `manage-booking`
 * behind an `action` enum, which is where Cal.com spends six separate tools.
 *
 * Destructive actions will not execute without a confirm_token minted by a dry
 * run. The token is bound to BOTH a fingerprint of the booking's current state
 * — so a booking that moved while the agent was thinking cannot be acted on
 * with stale numbers — AND a digest of the parameters that were previewed, so
 * the change that executes is the change a human approved. Reversible actions
 * (complete, no_show, resend_email, and update_details on anything but the
 * email address) execute directly.
 *
 * "Destructive" is decided per call rather than per action name, because two of
 * them are only destructive sometimes. See needsConfirmation().
 *
 * @see \FluentBooking\App\Modules\MCP\Support\WriteGuard for the contract.
 */
class BookingWriteTools
{
    /**
     * Actions that must be previewed before they can execute. These either
     * cannot be undone from the agent's side (cancel, reject) or move a real
     * person's calendar entry (create, reschedule).
     */
    const DESTRUCTIVE_ACTIONS = ['reschedule', 'cancel', 'reject'];

    /**
     * Ceiling on `guests`. Enforced in the schema so an oversized payload is
     * refused before WordPress decodes it and the sanitizer walks every entry —
     * the per-event seat limit further down only applies after all that work.
     */
    const MAX_GUESTS = 50;

    public static function definitions()
    {
        return [
            'fluent-booking/create-booking' => [
                'label'               => __('Create booking', 'fluent-booking'),
                'description'         => __('Book a slot on an attendee\'s behalf. Availability is re-checked at execute time and everyone is emailed as they would be for a self-service booking. Call with dry_run first, then pass back the confirm_token AND the same parameters.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'event_id'             => [
                            'type'        => 'integer',
                            'description' => __('The event type to book. Required.', 'fluent-booking'),
                        ],
                        'start_time'           => [
                            'type'        => 'string',
                            'description' => __('Local wall-clock start, Y-m-d H:i:s, read in the timezone parameter. No offset or Z suffix. Required.', 'fluent-booking'),
                        ],
                        'timezone'             => [
                            'type'        => 'string',
                            'description' => __('IANA timezone the attendee is booking in. Defaults to the site timezone.', 'fluent-booking'),
                        ],
                        'name'                 => [
                            'type'        => 'string',
                            'description' => __('Attendee full name. Required.', 'fluent-booking'),
                            'maxLength'   => 200,
                        ],
                        'email'                => [
                            'type'        => 'string',
                            'description' => __('Attendee email. Required.', 'fluent-booking'),
                            'maxLength'   => 254,
                        ],
                        'phone'                => ['type' => 'string', 'maxLength' => 40],
                        'message'              => [
                            'type'        => 'string',
                            'description' => __('The attendee\'s note, shown to the host.', 'fluent-booking'),
                            'maxLength'   => 5000,
                        ],
                        'internal_note'        => [
                            'type'        => 'string',
                            'description' => __('Host-only note. Never shown to the attendee.', 'fluent-booking'),
                            'maxLength'   => 5000,
                        ],
                        'duration'             => [
                            'type'        => 'integer',
                            'description' => __('Minutes, when the event type offers a choice. Defaults to its default duration.', 'fluent-booking'),
                        ],
                        'host_id'              => [
                            'type'        => 'integer',
                            'description' => __('Pin a specific host. Round-robin events pick one automatically when this is omitted.', 'fluent-booking'),
                        ],
                        'location_type'        => [
                            'type'        => 'string',
                            'description' => __('Only when the event type offers several. get-event-types lists them.', 'fluent-booking'),
                        ],
                        'location_description' => [
                            'type'        => 'string',
                            'description' => __('Required for phone_guest and in_person_guest: the attendee\'s number or address.', 'fluent-booking'),
                        ],
                        'custom_fields'        => [
                            'type'        => 'object',
                            'description' => __('Answers to the event type\'s booking fields, keyed by field name.', 'fluent-booking'),
                        ],
                        'guests'               => [
                            'type'        => 'array',
                            'description' => __('Additional guests. Email strings, or {name, email} objects — group events seat each guest separately and need a name.', 'fluent-booking'),
                            'items'       => ['type' => ['string', 'object']],
                            'maxItems'    => self::MAX_GUESTS,
                        ],
                        'send_notifications'   => [
                            'type'        => 'boolean',
                            'description' => __('Default true. Set false to create the booking without notifying anyone — email or SMS; reminders are still scheduled.', 'fluent-booking'),
                        ],
                        'dry_run'              => [
                            'type'        => 'boolean',
                            'description' => __('Preview the booking and get a confirm_token without creating anything.', 'fluent-booking'),
                        ],
                        'confirm_token'        => [
                            'type'        => 'string',
                            'description' => __('The token from the dry run. Required to actually create the booking.', 'fluent-booking'),
                        ],
                        'idempotency_key'      => [
                            'type'        => 'string',
                            'description' => __('A unique key, so a retry after a timeout returns the first result rather than booking twice.', 'fluent-booking'),
                        ],
                    ],
                    'required'   => ['event_id', 'start_time', 'name', 'email'],
                ],
                'annotations'         => [
                    'title'       => __('Create booking', 'fluent-booking'),
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => false,
                ],
                'permission_callback' => [PermissionGate::class, 'bookingWriteGate'],
                'execute_callback'    => [self::class, 'createBooking'],
            ],

            'fluent-booking/manage-booking' => [
                'label'               => __('Manage booking', 'fluent-booking'),
                'description'         => __('Act on an existing booking: reschedule, cancel, confirm, reject, complete, mark no-show, edit attendee details, or resend the confirmation email. reschedule, cancel and reject are destructive — call with dry_run first, then pass the returned confirm_token.', 'fluent-booking'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'booking_id'         => [
                            'type'        => 'integer',
                            'description' => __('The booking to act on. Required.', 'fluent-booking'),
                        ],
                        'action'             => [
                            'type'        => 'string',
                            'description' => __('reschedule needs start_time (and timezone). cancel and reject take an optional reason shown to the attendee. update_details needs fields. resend_email takes recipient. Required.', 'fluent-booking'),
                            'enum'        => ['reschedule', 'cancel', 'confirm', 'reject', 'complete', 'no_show', 'update_details', 'resend_email'],
                        ],
                        'start_time'         => [
                            'type'        => 'string',
                            'description' => __('reschedule only. Local wall-clock start, Y-m-d H:i:s, read in the timezone parameter.', 'fluent-booking'),
                        ],
                        'timezone'           => [
                            'type'        => 'string',
                            'description' => __('IANA timezone for start_time and for the *_local times in the response.', 'fluent-booking'),
                        ],
                        'host_id'            => [
                            'type'        => 'integer',
                            'description' => __('reschedule only. Pin a host on a round-robin event.', 'fluent-booking'),
                        ],
                        'reason'             => [
                            'type'        => 'string',
                            'description' => __('Why. Stored on the booking and included in the cancellation, rejection or reschedule email.', 'fluent-booking'),
                            'maxLength'   => 2000,
                        ],
                        'fields'             => [
                            'type'        => 'object',
                            'description' => __('update_details only. Any of: first_name, last_name, email, phone, internal_note.', 'fluent-booking'),
                        ],
                        'recipient'          => [
                            'type'        => 'string',
                            'description' => __('resend_email only. Who to send the confirmation to. Defaults to guest.', 'fluent-booking'),
                            'enum'        => ['guest', 'host'],
                        ],
                        'refund_payment'     => [
                            'type'        => 'boolean',
                            'description' => __('cancel and reject only. Refund through the original gateway. Default false — money never moves as a side effect.', 'fluent-booking'),
                        ],
                        'send_notifications' => [
                            'type'        => 'boolean',
                            'description' => __('Default true. Set false to make the change without notifying anyone — email or SMS; reminders are still scheduled.', 'fluent-booking'),
                        ],
                        'dry_run'            => [
                            'type'        => 'boolean',
                            'description' => __('Preview the change and get a confirm_token without applying it.', 'fluent-booking'),
                        ],
                        'confirm_token'      => [
                            'type'        => 'string',
                            'description' => __('The token from the dry run. Required for reschedule, cancel and reject.', 'fluent-booking'),
                        ],
                        'idempotency_key'    => [
                            'type'        => 'string',
                            'description' => __('Pass a unique key so a retry after a timeout returns the first result instead of acting twice.', 'fluent-booking'),
                        ],
                    ],
                    'required'   => ['booking_id', 'action'],
                ],
                'annotations'         => [
                    'title'       => __('Manage booking', 'fluent-booking'),
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => false,
                ],
                'permission_callback' => [PermissionGate::class, 'bookingWriteGate'],
                'execute_callback'    => [self::class, 'manageBooking'],
            ],
        ];
    }

    /**
     * @param array $params
     * @return array|\WP_Error
     */
    public static function createBooking($params = [])
    {
        $eventId = absint(Arr::get($params, 'event_id'));

        if (!$eventId) {
            return MCPHelper::error('missing_event_id', __('event_id is required. Call get-event-types to find one.', 'fluent-booking'));
        }

        $event = CalendarSlot::with('calendar')->find($eventId);

        if (!$event) {
            return MCPHelper::error('event_not_found', __('No event type with that id.', 'fluent-booking'));
        }

        if (!PermissionManager::canWriteCalendar($event->calendar_id)) {
            return MCPHelper::error(
                'permission_denied',
                __('You do not have permission to create bookings on this calendar.', 'fluent-booking'),
                ['event_id' => $eventId]
            );
        }

        $tool      = 'fluent-booking/create-booking';
        $timezone  = MCPHelper::resolveTimezone(Arr::get($params, 'timezone', ''));
        // A create has no existing entity, so the token is bound to the exact
        // slot being claimed. Two agents previewing the same slot mint separate
        // user-scoped tokens; the availability re-check at execute time is what
        // stops the second one from double-booking.
        $entityKey = 'event:' . $eventId . ':' . Arr::get($params, 'start_time', '') . ':' . strtolower((string) Arr::get($params, 'email', ''));

        $digest = WriteGuard::paramsDigest($params);

        if (Arr::isTrue($params, 'dry_run')) {
            $preview = self::previewCreate($event, $params, $timezone);

            if (is_wp_error($preview)) {
                return $preview;
            }

            return MCPHelper::success(
                WriteGuard::preview($tool, $entityKey, self::createFingerprint($event), $preview, $digest),
                ['timezone' => $timezone],
                WriteGuard::CONFIRM_NEXT_STEP
            );
        }

        // idempotent() OUTSIDE confirm(), not the other way round. confirm()
        // consumes the token, so with the old ordering the retry-after-timeout
        // this key exists to absorb was rejected as `confirmation_expired`
        // before the recorded result was ever consulted — and the agent's
        // recovery path was a fresh dry_run and a second booking.
        return WriteGuard::idempotent($tool, $entityKey, Arr::get($params, 'idempotency_key', ''), function () use ($tool, $entityKey, $event, $params, $timezone, $digest) {
            $confirmed = WriteGuard::confirm($tool, $entityKey, self::createFingerprint($event), Arr::get($params, 'confirm_token', ''), $digest);

            if (is_wp_error($confirmed)) {
                return $confirmed;
            }

            $booking = BookingWriter::create($event, $params);

            if (is_wp_error($booking)) {
                return $booking;
            }

            $data = [
                'created' => true,
                'booking' => BookingProjector::full($booking, $timezone),
            ];

            // A guest the caller asked for and did not get has to be named. An
            // agent told `created: true` for a four-person booking that seated
            // one otherwise reports a wrong number as a right one.
            if ($dropped = BookingWriter::droppedGuests()) {
                $data['guests_dropped'] = $dropped;
            }

            return MCPHelper::success(
                $data,
                [
                    'timezone'           => $timezone,
                    'notifications_requested' => BookingWriter::wantsNotifications($params),
                ],
                'Call get-booking with this booking_id to see the full record, or list-bookings to confirm it appears in the schedule.'
            );
        }, $digest, function ($ref) use ($timezone) {
            return self::replayBooking($ref, $timezone, ['created' => true]);
        });
    }

    /**
     * @param array $params
     * @return array|\WP_Error
     */
    public static function manageBooking($params = [])
    {
        $action = sanitize_text_field(Arr::get($params, 'action', ''));

        if (!$action) {
            return MCPHelper::error('missing_action', __('action is required.', 'fluent-booking'));
        }

        $bookingId = absint(Arr::get($params, 'booking_id'));

        if (!$bookingId) {
            return MCPHelper::error('missing_booking_id', __('booking_id is required. Call list-bookings to find one.', 'fluent-booking'));
        }

        $booking = Booking::with(['calendar_event'])->find($bookingId);

        if (!$booking) {
            return MCPHelper::error('booking_not_found', __('No booking with that id.', 'fluent-booking'));
        }

        if (!BookingWriter::canWriteBooking($booking)) {
            return MCPHelper::error(
                'permission_denied',
                __('You do not have permission to change this booking.', 'fluent-booking'),
                ['booking_id' => $bookingId]
            );
        }

        $timezone  = MCPHelper::resolveTimezone(Arr::get($params, 'timezone', ''));
        $tool      = 'fluent-booking/manage-booking';
        $entityKey = 'booking:' . $bookingId . ':' . $action;
        $digest    = WriteGuard::paramsDigest($params);

        $needsConfirmation = self::needsConfirmation($booking, $action, $params);

        if (Arr::isTrue($params, 'dry_run')) {
            $preview = self::previewAction($booking, $action, $params, $timezone);

            if (is_wp_error($preview)) {
                return $preview;
            }

            if (!$needsConfirmation) {
                // Reversible actions still honour dry_run, because an agent that
                // previews everything by habit should not be punished for it —
                // it just does not need a token to follow up.
                return MCPHelper::success(
                    ['dry_run' => true, 'preview' => $preview],
                    ['timezone' => $timezone],
                    'Nothing was changed. This action is reversible — call again without dry_run to apply it; no confirm_token is needed.'
                );
            }

            return MCPHelper::success(
                WriteGuard::preview($tool, $entityKey, WriteGuard::bookingFingerprint($booking), $preview, $digest),
                ['timezone' => $timezone],
                WriteGuard::CONFIRM_NEXT_STEP
            );
        }

        // See createBooking(): the idempotency wrapper has to sit OUTSIDE the
        // confirm-token check, because the check is one-shot.
        return WriteGuard::idempotent($tool, $entityKey, Arr::get($params, 'idempotency_key', ''), function () use ($tool, $entityKey, $booking, $action, $params, $timezone, $digest, $needsConfirmation) {
            if ($needsConfirmation) {
                $confirmed = WriteGuard::confirm(
                    $tool,
                    $entityKey,
                    WriteGuard::bookingFingerprint($booking),
                    Arr::get($params, 'confirm_token', ''),
                    $digest
                );

                if (is_wp_error($confirmed)) {
                    return $confirmed;
                }
            }

            return self::execute($booking, $action, $params, $timezone);
        }, $digest, function ($ref) use ($timezone, $action) {
            return self::replayBooking($ref, $timezone, ['action' => $action]);
        });
    }

    /**
     * Rebuild a write's response from the reference the idempotency record
     * keeps, reading the booking as it stands now.
     *
     * The record itself holds ids only — see WriteGuard::idempotent() — so a
     * replay re-projects rather than handing back a day-old copy of the
     * attendee's details.
     *
     * @param array  $ref
     * @param string $timezone
     * @param array  $extra
     *
     * @return array|null
     */
    private static function replayBooking($ref, $timezone, $extra = [])
    {
        $bookingId = (int) Arr::get($ref, 'booking_id');

        if (!$bookingId) {
            return null;
        }

        $booking = Booking::with(['calendar_event'])->find($bookingId);

        if (!$booking) {
            return null;
        }

        return MCPHelper::success(
            $extra + ['booking' => BookingProjector::full($booking, $timezone)],
            ['timezone' => $timezone]
        );
    }

    /**
     * Whether this particular call has to be previewed and confirmed first.
     *
     * Most of the answer is the action name, but two cases are only destructive
     * depending on what is being asked, and both were previously waved through
     * as "reversible":
     *
     *  - `update_details` changing `email`. Reversible in the database and not
     *    reversible anywhere else: the address becomes the delivery target for
     *    `resend_email`, which carries the meeting join link and lands at the
     *    new address without the real attendee hearing about it. Rewriting a
     *    booking's contact address is not an edit, it is a redirection.
     *  - `confirm` on a booking with an unsettled payment order. It marks the
     *    order paid and fires the payment-completed hooks. Nothing about a
     *    booking's money state should move without the operator seeing it
     *    first.
     *
     * @param Booking $booking
     * @param string  $action
     * @param array   $params
     * @return bool
     */
    private static function needsConfirmation(Booking $booking, $action, $params)
    {
        if (in_array($action, self::DESTRUCTIVE_ACTIONS, true)) {
            return true;
        }

        if ($action === 'update_details') {
            $fields = (array) Arr::get($params, 'fields', []);

            return array_key_exists('email', $fields);
        }

        if ($action === 'confirm') {
            return $booking->payment_method
                && $booking->payment_status !== 'paid'
                && $booking->payment_order;
        }

        return false;
    }

    /**
     * @return array|\WP_Error
     */
    private static function execute(Booking $booking, $action, $params, $timezone)
    {
        if ($action === 'resend_email') {
            $result = BookingWriter::resendEmail($booking, sanitize_text_field(Arr::get($params, 'recipient', 'guest')), $params);

            if (is_wp_error($result)) {
                return $result;
            }

            return MCPHelper::success(['action' => $action] + $result, ['timezone' => $timezone]);
        }

        if ($action === 'update_details') {
            $result = BookingWriter::updateDetails($booking, Arr::get($params, 'fields', []), $params);
        } elseif ($action === 'reschedule') {
            $result = BookingWriter::reschedule($booking, $params);
        } else {
            $result = BookingWriter::applyStatus($booking, $action, $params);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return MCPHelper::success(
            [
                'action'  => $action,
                'booking' => BookingProjector::full($result, $timezone),
            ],
            [
                'timezone'           => $timezone,
                'notifications_requested' => BookingWriter::wantsNotifications($params),
            ]
        );
    }

    /**
     * What a create would do, without doing it. Deliberately names every
     * recipient: the operator reading the agent's transcript should be able to
     * see who is about to be emailed before approving.
     *
     * @return array|\WP_Error
     */
    private static function previewCreate(CalendarSlot $event, $params, $timezone)
    {
        // The same checks the execute runs, so "the dry run worked" means something.
        $valid = BookingWriter::validateCreate($event, $params);

        if (is_wp_error($valid)) {
            return $valid;
        }

        $notify = BookingWriter::wantsNotifications($params);

        $preview = [
            'action'             => 'create',
            'event'              => [
                'id'       => (int) $event->id,
                'title'    => $event->title,
                'type'     => $event->event_type,
                'duration' => (int) $event->getDuration(Arr::get($params, 'duration')),
                'status'   => $event->status,
            ],
            'attendee'           => [
                'name'  => sanitize_text_field(Arr::get($params, 'name', '')),
                'email' => MCPHelper::maskEmail(Arr::get($params, 'email', '')),
            ],
            'requested_start'    => sanitize_text_field(Arr::get($params, 'start_time', '')),
            'timezone'           => $timezone,
            'slot_available'     => self::previewSlotAvailability($event, $params, $timezone),
            'guests'             => count((array) Arr::get($params, 'guests', [])),
            'guests_bookable'    => BookingWriter::previewGuestCount($event, $params),
            'will_notify'        => $notify ? self::recipientSummary($event) : [],
            'notifications_requested' => $notify,
            'note'               => __('Availability is re-checked when you execute, so a slot taken in the meantime is refused rather than double-booked.', 'fluent-booking'),
        ];

        if ($preview['slot_available'] === false) {
            $preview['note'] = __('That time is not currently free, so executing this would be refused. Call get-available-slots for the current openings.', 'fluent-booking');
        }

        if ($ambiguity = MCPHelper::ambiguityNote(Arr::get($params, 'start_time', ''), $timezone)) {
            $preview['timezone_warning'] = $ambiguity;
        }

        return $preview;
    }

    /**
     * Whether the requested slot is free, for the preview only.
     *
     * Advisory: the slot is claimed and re-checked under a lock at execute
     * time, so true means "free a moment ago", never a reservation. null when
     * the engine could not answer.
     *
     * @return bool|null
     */
    private static function previewSlotAvailability(CalendarSlot $event, $params, $timezone)
    {
        $startTime = sanitize_text_field(Arr::get($params, 'start_time', ''));

        if (!$startTime) {
            return null;
        }

        try {
            $startUtc = MCPHelper::toUtc($startTime, $timezone);

            $check = SlotResolver::checkSlot(
                $event,
                $startUtc,
                $timezone,
                Arr::get($params, 'duration'),
                absint(Arr::get($params, 'host_id')) ?: null
            );
        } catch (\Throwable $e) {
            return null;
        }

        if (is_wp_error($check) || !isset($check['available'])) {
            return null;
        }

        return (bool) $check['available'];
    }

    /**
     * @return array|\WP_Error
     */
    private static function previewAction(Booking $booking, $action, $params, $timezone)
    {
        // The same state-machine check the execute runs. Without it a dry run
        // previewed `no_show -> cancelled` and minted a confirm_token for a
        // call the execute would refuse.
        $transitions = BookingWriter::statusTransitions();

        if (isset($transitions[$action])) {
            $target = $transitions[$action]['to'];

            if ($booking->status === $target) {
                return MCPHelper::error(
                    'no_change',
                    /* translators: %s: the booking's current status */
                    sprintf(__('This booking is already "%s".', 'fluent-booking'), $target),
                    ['status' => $booking->status]
                );
            }

            if (in_array($action, ['complete', 'no_show'], true) && $booking->end_time > gmdate('Y-m-d H:i:s')) { // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                return MCPHelper::error(
                    'not_yet_occurred',
                    /* translators: %s: the requested status */
                    sprintf(__('This booking has not happened yet, so it cannot be marked "%s". Cancel it instead, or wait until it has ended.', 'fluent-booking'), $target),
                    ['status' => $booking->status, 'ends_at' => $booking->end_time]
                );
            }

            if (!in_array($booking->status, $transitions[$action]['from'], true)) {
                return MCPHelper::error(
                    'invalid_transition',
                    sprintf(
                        /* translators: %1$s: current status, %2$s: requested status, %3$s: allowed statuses */
                        __('A booking that is "%1$s" cannot become "%2$s". Only %3$s bookings can.', 'fluent-booking'),
                        $booking->status,
                        $target,
                        implode(', ', $transitions[$action]['from'])
                    ),
                    ['status' => $booking->status, 'allowed_from' => $transitions[$action]['from']]
                );
            }
        }

        $notify = BookingWriter::wantsNotifications($params);

        $preview = [
            'action'             => $action,
            'booking'            => BookingProjector::row($booking, $timezone),
            'notifications_requested' => $notify,
        ];

        if ($action === 'reschedule') {
            $preview['from'] = MCPHelper::timePair($booking->start_time, $timezone, 'current_start');
            $preview['to']   = sanitize_text_field(Arr::get($params, 'start_time', ''));

            if (!$preview['to']) {
                return MCPHelper::error('missing_start_time', __('reschedule needs start_time.', 'fluent-booking'));
            }

            $preview['note'] = __('Availability is re-checked when you execute.', 'fluent-booking');

            if ($ambiguity = MCPHelper::ambiguityNote($preview['to'], $timezone)) {
                $preview['timezone_warning'] = $ambiguity;
            }
        }

        if (in_array($action, ['cancel', 'reject'], true)) {
            $preview['reason']         = sanitize_text_field(Arr::get($params, 'reason', ''));
            $preview['refund_payment'] = Arr::isTrue($params, 'refund_payment');

            if ($booking->payment_method && !$preview['refund_payment']) {
                $preview['payment_note'] = __('This booking was paid for. No refund will be issued unless you pass refund_payment.', 'fluent-booking');
            }
        }

        if ($action === 'update_details') {
            $fields = (array) Arr::get($params, 'fields', []);

            if (!$fields) {
                return MCPHelper::error('missing_fields', __('update_details needs fields.', 'fluent-booking'));
            }

            $preview['changing'] = array_keys($fields);

            if (array_key_exists('email', $fields)) {
                $preview['email_change'] = [
                    'from' => MCPHelper::maskEmail($booking->email),
                    'to'   => MCPHelper::maskEmail(Arr::get($fields, 'email')),
                ];
                $preview['warning'] = __('Changing the address redirects every future email for this booking, the join link included. The current attendee is not notified that it moved.', 'fluent-booking');
            }
        }

        if ($action === 'confirm' && $booking->payment_method && $booking->payment_status !== 'paid') {
            $preview['payment_effect'] = __('This settles the booking\'s order: it is marked fully paid and the payment-completed hooks fire. No money is captured — the record is simply treated as settled.', 'fluent-booking');
        }

        $transitions = BookingWriter::statusTransitions();

        if (isset($transitions[$action])) {
            $preview['status_change'] = [
                'from' => $booking->status,
                'to'   => $transitions[$action]['to'],
            ];
        }

        if ($notify && $booking->calendar_event) {
            $preview['will_notify'] = self::recipientSummary($booking->calendar_event, $booking);
        }

        return $preview;
    }

    /**
     * Who an action would email, described rather than enumerated — the exact
     * template that fires depends on the event type's notification settings,
     * and listing every address would leak contact details into a preview an
     * agent may echo back verbatim.
     *
     * @return array
     */
    private static function recipientSummary(CalendarSlot $event, $booking = null)
    {
        $recipients = [];

        $notifications = $event->getNotifications();

        if (Arr::isTrue($notifications, 'booking_conf_attendee.enabled') || Arr::isTrue($notifications, 'booking_request_attendee.enabled')) {
            $recipients[] = $booking
                ? sprintf('attendee (%s)', MCPHelper::maskEmail($booking->email))
                : 'attendee';
        }

        if (Arr::isTrue($notifications, 'booking_conf_host.enabled') || Arr::isTrue($notifications, 'booking_request_host.enabled')) {
            $recipients[] = 'host';
        }

        return $recipients;
    }

    /**
     * A create has no prior state to go stale, but the event type's own
     * configuration does — an event deactivated or re-timed between preview and
     * execute should invalidate the token rather than silently book against the
     * old shape.
     *
     * @return string
     */
    private static function createFingerprint(CalendarSlot $event)
    {
        return implode('|', [$event->id, $event->status, $event->duration, $event->updated_at]);
    }
}
