<?php

namespace FluentBooking\App\Modules\MCP\Support;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\BookingFieldService;
use FluentBooking\App\Services\BookingService;
use FluentBooking\App\Services\EmailNotificationService;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Services\NotificationGate;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\App\Services\RescheduleService;
use FluentBooking\App\Hooks\Handlers\TimeSlotServiceHandler;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Every mutation the MCP server performs on a booking, in one place.
 *
 * The rule this class exists to enforce: an agent's write must be
 * indistinguishable from the same write done by a human in wp-admin. Same
 * validation, same status transitions, same hooks — so remote calendars sync,
 * CRM triggers fire, webhooks deliver, and payment side effects happen exactly
 * as they would otherwise. Where the plugin already has a service for the job
 * (BookingService::createBooking, RescheduleService::reschedule,
 * Booking::cancelMeeting) we call it rather than reimplementing it; the drift
 * risk of a second implementation is not worth the convenience.
 *
 * The one thing MCP writes do that admin writes do not: every one of them lands
 * an activity row tagged with the acting user and `via MCP`, so an operator
 * reading a booking's timeline can always tell an agent's action from a
 * human's.
 *
 * @since 2.2.6
 */
class BookingWriter
{
    /**
     * Columns manage-booking's `update_details` may write, mirroring
     * SchedulesController::patchBooking()'s whitelist minus the two that have
     * their own actions (status, payment_status).
     */
    const EDITABLE_FIELDS = ['first_name', 'last_name', 'email', 'phone', 'internal_note'];

    /**
     * @var array guests the current create() was asked for and did not book
     */
    private static $guestsDropped = [];

    /**
     * Statuses a booking can move to, and what each one is allowed to move from.
     * Mirrors Booking::cancelMeeting()/rejectMeeting() and the admin's own
     * transitions so an agent cannot reach a state the UI would refuse.
     */
    public static function statusTransitions()
    {
        return [
            'cancel'   => ['to' => 'cancelled', 'from' => ['scheduled', 'pending']],
            'reject'   => ['to' => 'rejected', 'from' => ['pending']],
            'confirm'  => ['to' => 'scheduled', 'from' => ['pending']],
            'complete' => ['to' => 'completed', 'from' => ['scheduled', 'rescheduled']],
            'no_show'  => ['to' => 'no_show', 'from' => ['scheduled', 'rescheduled', 'completed']],
        ];
    }

    /**
     * Create a booking on an attendee's behalf.
     *
     * Deliberately mirrors BookingController::createBooking(): resolve the
     * duration, convert the requested wall-clock time to UTC, resolve the
     * location from the event's own configured locations, assign a round-robin
     * host, re-check availability against the live slot engine, then hand off to
     * BookingService so every downstream integration behaves normally.
     *
     * @param CalendarSlot $event
     * @param array        $params
     *
     * @return Booking|\WP_Error
     */
    public static function create(CalendarSlot $event, $params)
    {
        if ($event->status !== 'active') {
            return MCPHelper::error(
                'event_not_bookable',
                /* translators: %s: the event type's current status */
                sprintf(__('This event type is "%s", not active, so it is not accepting bookings.', 'fluent-booking'), $event->status),
                ['event_status' => $event->status]
            );
        }

        $email = sanitize_email(Arr::get($params, 'email', ''));

        if (!$email || !is_email($email)) {
            return MCPHelper::error('invalid_email', __('A valid attendee email is required.', 'fluent-booking'));
        }

        $name = sanitize_text_field(Arr::get($params, 'name', ''));

        if (!$name) {
            return MCPHelper::error('missing_name', __('The attendee name is required.', 'fluent-booking'));
        }

        $timezone = MCPHelper::resolveTimezone(Arr::get($params, 'timezone', ''));
        $duration = $event->getDuration(Arr::get($params, 'duration'));

        $startTime = MCPHelper::toUtc(Arr::get($params, 'start_time', ''), $timezone);

        if (is_wp_error($startTime)) {
            return $startTime;
        }

        $endTime = gmdate('Y-m-d H:i:s', strtotime($startTime) + ($duration * 60)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $hostUserId = self::resolveHostId($event, $params);

        if (is_wp_error($hostUserId)) {
            return $hostUserId;
        }

        $bookingData = [
            'person_time_zone' => $timezone,
            'start_time'       => $startTime,
            'end_time'         => $endTime,
            'name'             => $name,
            'email'            => $email,
            'message'          => sanitize_textarea_field(Arr::get($params, 'message', '')),
            'phone'            => sanitize_text_field(Arr::get($params, 'phone', '')),
            'status'           => $event->isConfirmationEnabled() ? 'pending' : 'scheduled',
            // Not 'admin': the admin source drives UI affordances that assume a
            // human filled the form. An agent-created booking is its own thing
            // and reporting should be able to tell them apart.
            'source'           => 'mcp',
            'event_type'       => $event->event_type,
            'slot_minutes'     => $duration,
        ];

        if ($internalNote = Arr::get($params, 'internal_note')) {
            $bookingData['internal_note'] = sanitize_textarea_field($internalNote);
        }

        $location = self::resolveLocation($event, $params);

        if (is_wp_error($location)) {
            return $location;
        }

        $bookingData['location_details'] = $location;

        if (Arr::get($location, 'type') === 'phone_guest' && empty($bookingData['phone'])) {
            $bookingData['phone'] = Arr::get($location, 'description', '');
        }

        $customFieldsData = BookingFieldService::getCustomFieldsData(
            (array) Arr::get($params, 'custom_fields', []),
            $event
        );

        if (is_wp_error($customFieldsData)) {
            return MCPHelper::error(
                'invalid_custom_fields',
                $customFieldsData->get_error_message(),
                ['errors' => $customFieldsData->get_error_data()]
            );
        }

        // Round robin picks the host the public page would have picked, so the
        // agent's booking lands on the same person a self-service booking would.
        if ($event->isRoundRobin() && !$hostUserId) {
            $sortedHostIds = $event->getHostIdsSortedByBookings($startTime);
            $bookingData['host_user_id'] = $sortedHostIds[0];
        } elseif ($hostUserId) {
            $bookingData['host_user_id'] = $hostUserId;
        }

        $service = TimeSlotServiceHandler::initService($event->calendar, $event);

        if (is_wp_error($service)) {
            return MCPHelper::error('slot_service_unavailable', $service->get_error_message());
        }

        // Hold the slot for the duration of the check-then-write. Availability
        // is computed by a query and the booking is a separate INSERT, so
        // without this two agents that both pass isSpotAvailable() before either
        // writes will both write — the "re-checked at execute time" guarantee
        // narrows the race, it does not remove it. An agent can fire these far
        // faster than a human clicking through a booking page, and MCP hands the
        // same slot to whoever asks first.
        // Every host the booking would occupy, so two event types sharing an
        // owner cannot both write. getHostIds() is the event's own answer: one
        // id for a single or group event, all of them for a collective.
        // Round robin is the exception — its host is chosen inside
        // isSpotAvailable(), so it locks the event first and the host below.
        $lock     = self::lockSlot($event, $startTime, $endTime, $hostUserId);
        $hostLock = false;

        if (!$lock) {
            return MCPHelper::error(
                'slot_locked',
                __('Another booking for this slot is being created right now. Wait a moment and check get-available-slots before retrying.', 'fluent-booking'),
                ['requested_start' => $startTime]
            );
        }

        try {
            $availableSpot = $service->isSpotAvailable($startTime, $endTime, $duration, $hostUserId);

            if (!$availableSpot) {
                return MCPHelper::error(
                    'slot_unavailable',
                    __('That time is not available. Call get-available-slots for the current openings, or diagnose-availability to find out why the calendar is closed.', 'fluent-booking'),
                    [
                        'requested_start' => $startTime,
                        'next_step'       => 'call fluent-booking/get-available-slots',
                    ]
                );
            }

            // isSpotAvailable() can outrun the lease on a team event with a
            // cold calendar cache, and an expired lease is stolen without
            // question — so re-assert it before writing rather than trusting
            // that the lock taken above is still ours.
            if (!SlotLock::renewAll($lock)) {
                return MCPHelper::error(
                    'slot_locked',
                    __('Another booking for this slot was created while this one was being checked. Call get-available-slots before retrying.', 'fluent-booking'),
                    ['requested_start' => $startTime]
                );
            }

            if ($event->isRoundRobin() && !$hostUserId && !empty($service->hostUserId)) {
                $hostUserId = (int) $service->hostUserId;

                $bookingData['host_user_id'] = $hostUserId;

                // The first lock could only name the event: round robin has no
                // host until the line above settles one. Claim that host now.
                $hostLock = SlotLock::acquireInterval($event->id, $startTime, $endTime, [$hostUserId]);

                if (!$hostLock) {
                    return MCPHelper::error(
                        'slot_locked',
                        __('That host was booked for this slot while this request was being checked. Call get-available-slots before retrying.', 'fluent-booking'),
                        ['requested_start' => $startTime]
                    );
                }

                // The check above settled this host while only the event was
                // locked, so another event type could have taken the person in
                // between. Re-check under the host lock.
                $availableSpot = $service->isSpotAvailable($startTime, $endTime, $duration, $hostUserId);

                if (!$availableSpot) {
                    return MCPHelper::error(
                        'slot_unavailable',
                        __('That host was booked for this slot while this request was being checked. Call get-available-slots for the current openings.', 'fluent-booking'),
                        [
                            'requested_start' => $startTime,
                            'next_step'       => 'call fluent-booking/get-available-slots',
                        ]
                    );
                }

                if (!SlotLock::renewAll($lock) || !SlotLock::renewAll($hostLock)) {
                    return MCPHelper::error(
                        'slot_locked',
                        __('Another booking for this slot was created while this one was being checked. Call get-available-slots before retrying.', 'fluent-booking'),
                        ['requested_start' => $startTime]
                    );
                }
            }

            self::$guestsDropped = [];

            if ($guests = self::sanitizeGuests($params, $event, $availableSpot)) {
                $bookingData['additional_guests'] = $guests;
            }

            $notify = self::wantsNotifications($params);

            $create = function () use ($bookingData, $event, $customFieldsData) {
                return BookingService::createBooking($bookingData, $event, $customFieldsData);
            };

            $booking = $notify ? $create() : NotificationGate::silently($create);
        } catch (\Throwable $e) {
            // Not $e->getMessage(): an ORM or PDO failure carries table names,
            // SQL fragments and absolute paths, and returning it here would walk
            // straight past the scrubbing AbilitiesRegistrar does for exactly
            // this reason. Catching Throwable rather than Exception also means a
            // TypeError from a downstream service is handled the same way.
            self::logException('create-booking', $e);

            return MCPHelper::error(
                'booking_failed',
                __('The booking could not be created. The site logged the details.', 'fluent-booking')
            );
        } finally {
            // Every exit from the block above releases the slot, the early
            // returns included — a lock left behind would block the slot for its
            // whole TTL after a failure that changed nothing.
            SlotLock::releaseAll($lock);
            SlotLock::releaseAll($hostLock);
        }

        if (is_wp_error($booking)) {
            return MCPHelper::error('booking_failed', $booking->get_error_message());
        }

        if (!$booking instanceof Booking) {
            return MCPHelper::error('booking_failed', __('The booking could not be created.', 'fluent-booking'));
        }

        self::logActivity(
            $booking,
            __('Booking Created via MCP', 'fluent-booking'),
            /* translators: %1$s: acting user, %2$s: booking start time in UTC */
            sprintf(__('Booking created by %1$s through the MCP server for %2$s (UTC).', 'fluent-booking'), self::actorName(), $booking->start_time),
            $notify
        );

        return $booking;
    }

    /**
     * Move a booking to a new time. Delegates to the same RescheduleService the
     * public booking form uses, so the two can never disagree about group
     * re-assignment, round-robin hosts or which emails go out.
     *
     * @param Booking $booking
     * @param array   $params
     *
     * @return Booking|\WP_Error
     */
    public static function reschedule(Booking $booking, $params)
    {
        $event = $booking->calendar_event;

        if (!$event) {
            return MCPHelper::error('event_missing', __('This booking has no event type, so it cannot be rescheduled.', 'fluent-booking'));
        }

        if (!in_array($booking->status, ['scheduled', 'pending', 'rescheduled'], true)) {
            return MCPHelper::error(
                'not_reschedulable',
                /* translators: %s: the booking's current status */
                sprintf(__('This booking is "%s" and cannot be rescheduled. Create a new booking instead.', 'fluent-booking'), $booking->status),
                ['status' => $booking->status]
            );
        }

        // Fall back to the ATTENDEE's zone, not the site's. This used to read
        // `resolveTimezone(...) ?: $booking->person_time_zone`, and
        // resolveTimezone() never returns anything falsy — its last line is
        // `return 'UTC'` — so the fallback was unreachable and an omitted
        // timezone silently meant "site time". For an attendee in Tokyo that
        // moved the meeting and then overwrote their stored zone with the
        // site's on the way out.
        $requested = trim((string) Arr::get($params, 'timezone', ''));

        $timezone = $requested
            ? MCPHelper::resolveTimezone($requested)
            : MCPHelper::resolveTimezone($booking->person_time_zone);

        $startTime = MCPHelper::toUtc(Arr::get($params, 'start_time', ''), $timezone);

        if (is_wp_error($startTime)) {
            return $startTime;
        }

        $duration = $booking->slot_minutes;
        $endTime  = gmdate('Y-m-d H:i:s', strtotime($startTime) + ($duration * 60)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $hostUserId = self::resolveHostId($event, $params);

        if (is_wp_error($hostUserId)) {
            return $hostUserId;
        }

        $service = TimeSlotServiceHandler::initService($event->calendar, $event);

        if (is_wp_error($service)) {
            return MCPHelper::error('slot_service_unavailable', $service->get_error_message());
        }

        // Same check-then-write race as create(), and the same hold over it,
        // round-robin key included.
        $lock     = self::lockSlot($event, $startTime, $endTime, $hostUserId);
        $hostLock = false;

        if (!$lock) {
            return MCPHelper::error(
                'slot_locked',
                __('Another booking for the target slot is being written right now. Wait a moment and check get-available-slots before retrying.', 'fluent-booking'),
                ['requested_start' => $startTime]
            );
        }

        try {
            // Re-check at execute time, not just at preview time — the slot may
            // have been taken during the confirm round-trip.
            if (!$service->isSpotAvailable($startTime, $endTime, $duration, $hostUserId)) {
                return MCPHelper::error(
                    'slot_unavailable',
                    __('That time is no longer available. Call get-available-slots for the current openings.', 'fluent-booking'),
                    [
                        'requested_start' => $startTime,
                        'next_step'       => 'call fluent-booking/get-available-slots',
                    ]
                );
            }

            // Same reason as create(): isSpotAvailable() can outrun the lease,
            // and an expired one is stolen without question.
            if (!SlotLock::renewAll($lock)) {
                return MCPHelper::error(
                    'slot_locked',
                    __('Another booking for the target slot was written while this one was being checked. Call get-available-slots before retrying.', 'fluent-booking'),
                    ['requested_start' => $startTime]
                );
            }

            if ($event->isRoundRobin() && !$hostUserId && !empty($service->hostUserId)) {
                $hostUserId = (int) $service->hostUserId;

                $hostLock = SlotLock::acquireInterval($event->id, $startTime, $endTime, [$hostUserId]);

                if (!$hostLock) {
                    return MCPHelper::error(
                        'slot_locked',
                        __('That host was booked for the target slot while this request was being checked. Call get-available-slots before retrying.', 'fluent-booking'),
                        ['requested_start' => $startTime]
                    );
                }

                // Same race as create(): re-check under the host lock.
                if (!$service->isSpotAvailable($startTime, $endTime, $duration, $hostUserId)) {
                    return MCPHelper::error(
                        'slot_unavailable',
                        __('That host was booked for the target slot while this request was being checked. Call get-available-slots for the current openings.', 'fluent-booking'),
                        [
                            'requested_start' => $startTime,
                            'next_step'       => 'call fluent-booking/get-available-slots',
                        ]
                    );
                }

                if (!SlotLock::renewAll($lock) || !SlotLock::renewAll($hostLock)) {
                    return MCPHelper::error(
                        'slot_locked',
                        __('Another booking for the target slot was written while this one was being checked. Call get-available-slots before retrying.', 'fluent-booking'),
                        ['requested_start' => $startTime]
                    );
                }
            }

            $notify = self::wantsNotifications($params);

            $move = function () use ($booking, $event, $startTime, $timezone, $params, $hostUserId) {
                return RescheduleService::reschedule($booking, $event, $startTime, $timezone, [
                    'reason'         => Arr::get($params, 'reason', ''),
                    'host_user_id'   => $hostUserId,
                    'source'         => __('the MCP server', 'fluent-booking'),
                    // An agent always acts for the host; it holds host
                    // credentials, not the attendee's booking link, so the
                    // guest-side reschedule window must not apply to it.
                    'rescheduled_by' => 'host',
                ]);
            };

            $result = $notify ? $move() : NotificationGate::silently($move);
        } catch (\Throwable $e) {
            self::logException('reschedule', $e);

            return MCPHelper::error(
                'reschedule_failed',
                __('The booking could not be moved. The site logged the details.', 'fluent-booking')
            );
        } finally {
            SlotLock::releaseAll($lock);
            SlotLock::releaseAll($hostLock);
        }

        if (is_wp_error($result)) {
            return MCPHelper::error('reschedule_failed', $result->get_error_message());
        }

        // RescheduleService writes its own row, but it records the ROLE ("by
        // host") rather than the person. Every other MCP write names the
        // operator, and reschedule is the one most worth attributing.
        self::logActivity(
            $result,
            __('Booking Rescheduled via MCP', 'fluent-booking'),
            /* translators: %1$s: acting user, %2$s: the new start time in UTC */
            sprintf(__('%1$s moved this booking to %2$s (UTC) through the MCP server.', 'fluent-booking'), self::actorName(), $startTime),
            $notify
        );

        return $result;
    }

    /**
     * Apply a status transition, reproducing SchedulesController::patchBooking()
     * including its payment side effects and its hook sequence.
     *
     * @param Booking $booking
     * @param string  $action
     * @param array   $params
     *
     * @return Booking|\WP_Error
     */
    public static function applyStatus(Booking $booking, $action, $params)
    {
        $transitions = self::statusTransitions();

        if (!isset($transitions[$action])) {
            return MCPHelper::error('unsupported_action', __('That action is not a status change.', 'fluent-booking'));
        }

        $target = $transitions[$action]['to'];
        $from   = $transitions[$action]['from'];

        if ($booking->status === $target) {
            return MCPHelper::error(
                'no_change',
                /* translators: %s: the booking's current status */
                sprintf(__('This booking is already "%s".', 'fluent-booking'), $target),
                ['status' => $booking->status]
            );
        }

        // Both say a meeting already happened. Marking one three weeks out as
        // completed reads to every report as a meeting that took place.
        if (in_array($action, ['complete', 'no_show'], true) && $booking->end_time > gmdate('Y-m-d H:i:s')) { // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            return MCPHelper::error(
                'not_yet_occurred',
                /* translators: %s: the requested status */
                sprintf(__('This booking has not happened yet, so it cannot be marked "%s". Cancel it instead, or wait until it has ended.', 'fluent-booking'), $target),
                ['status' => $booking->status, 'ends_at' => $booking->end_time]
            );
        }

        if (!in_array($booking->status, $from, true)) {
            return MCPHelper::error(
                'invalid_transition',
                sprintf(
                    /* translators: %1$s: current status, %2$s: requested status, %3$s: allowed statuses */
                    __('A booking that is "%1$s" cannot become "%2$s". Only %3$s bookings can.', 'fluent-booking'),
                    $booking->status,
                    $target,
                    implode(', ', $from)
                ),
                ['status' => $booking->status, 'allowed_from' => $from]
            );
        }

        $reason = sanitize_text_field(Arr::get($params, 'reason', ''));
        $notify = self::wantsNotifications($params);

        $apply = function () use ($booking, $action, $target, $reason, $params) {
            return self::transition($booking, $action, $target, $reason, $params);
        };

        $result = $notify ? $apply() : NotificationGate::silently($apply);

        if (is_wp_error($result)) {
            return $result;
        }

        self::logActivity(
            $booking,
            /* translators: %s: the new booking status */
            sprintf(__('Status changed to %s via MCP', 'fluent-booking'), $target),
            /* translators: %1$s: acting user, %2$s: new status */
            sprintf(__('%1$s set this booking to "%2$s" through the MCP server.', 'fluent-booking'), self::actorName(), $target),
            $notify
        );

        return Booking::with(['calendar_event'])->find($booking->id);
    }

    /**
     * The transition itself. Cancel and reject go through the model methods so
     * their reason handling, activity rows and hooks stay in one place; the
     * others mirror patchBooking()'s own sequence.
     *
     * @return true|\WP_Error
     */
    private static function transition(Booking $booking, $action, $target, $reason, $params)
    {
        $from = self::statusTransitions()[$action]['from'];

        if ($action === 'cancel' || $action === 'reject') {
            // Re-read immediately before mutating so the model's own status
            // guard runs against current data rather than whatever was loaded
            // when the request started. An agent can fire these far faster than
            // a human clicking in wp-admin, so the read-to-write window matters
            // here in a way it does not there.
            $fresh = Booking::find($booking->id);

            if (!$fresh || !in_array($fresh->status, $from, true)) {
                return MCPHelper::error(
                    'state_changed',
                    __('This booking changed while the request was in flight and is no longer in a state that allows this action.', 'fluent-booking'),
                    ['status' => $fresh ? $fresh->status : null]
                );
            }

            $priorStatus = $fresh->status;

            // The re-read above narrows the window; it does not close it. Two
            // hosts cancelling the same collective booking both see `scheduled`
            // and both proceed — and with refund_payment set, both fire the
            // gateway's refund hook. So claim the transition atomically first,
            // exactly as the non-cancel branch below does, and only let the
            // winner run the side effects. cancelMeeting()/rejectMeeting() then
            // do their own work on a row we already own.
            $claimed = Booking::where('id', $fresh->id)
                ->whereIn('status', $from)
                ->update(['status' => $target]);

            if (!$claimed) {
                return MCPHelper::error(
                    'state_changed',
                    __('This booking changed while the request was in flight and is no longer in a state that allows this action.', 'fluent-booking'),
                    ['status' => Booking::where('id', $fresh->id)->value('status')]
                );
            }

            // Hand the model back the status it actually held — not $from[0] —
            // so cancelMeeting() runs its normal transition instead of
            // short-circuiting on "already cancelled", and so anything keyed on
            // the prior status (pending vs scheduled) still sees the truth.
            $fresh->status = $priorStatus;

            // The claim above moved the persisted status ahead of the work that
            // gives it meaning — the reason, the activity row, the hooks, the
            // notification, the refund. A failure before any of it must put the
            // row back. A failure after must not: the hook cancelMeeting() and
            // rejectMeeting() fire mails the attendee and deletes the remote
            // calendar event, so reverting there leaves a live booking whose
            // attendee holds a cancellation. This marks which side it fell on.
            $notified = false;

            $marker = function () use (&$notified) {
                $notified = true;
            };

            $hook = $action === 'cancel'
                ? 'fluent_booking/booking_schedule_cancelled'
                : 'fluent_booking/booking_schedule_rejected';

            add_action($hook, $marker, PHP_INT_MIN);

            try {
                if ($action === 'cancel') {
                    $result = $fresh->cancelMeeting($reason, 'host', get_current_user_id());

                    if (is_wp_error($result)) {
                        self::rollbackStatus($fresh->id, $priorStatus, $target);

                        return $result;
                    }
                } else {
                    $fresh->rejectMeeting($reason, get_current_user_id());
                }
            } catch (\Throwable $e) {
                self::logException('manage-booking:' . $action, $e);

                if (!$notified) {
                    self::rollbackStatus($fresh->id, $priorStatus, $target);

                    return MCPHelper::error(
                        'transition_failed',
                        __('The change could not be completed and the booking was left as it was. The site logged the details.', 'fluent-booking')
                    );
                }

                return self::partiallyCompleted($fresh->id, $target);
            } finally {
                remove_action($hook, $marker, PHP_INT_MIN);
            }

            // The refund runs after the cancellation is already out. Failing
            // to move the money is not a reason to un-cancel.
            try {
                self::maybeRefund($fresh, $params);
            } catch (\Throwable $e) {
                self::logException('manage-booking:' . $action . ':refund', $e);

                return MCPHelper::error(
                    'refund_failed',
                    __('The booking is cancelled but the refund did not go through. Issue it in the payment gateway.', 'fluent-booking'),
                    ['booking_id' => $fresh->id, 'status' => $target]
                );
            }

            return true;
        }

        // Same read-then-claim as the cancel branch: the claim reports success,
        // not which of the allowed statuses the row actually held, and a
        // rollback needs the real one.
        $priorStatus = Booking::where('id', $booking->id)->value('status');

        // Compare-and-set. Two agents racing the same transition both pass the
        // in-memory status check; only the one whose UPDATE matches a row still
        // in an allowed status gets to fire the side effects.
        $claimed = Booking::where('id', $booking->id)
            ->whereIn('status', $from)
            ->update(['status' => $target]);

        if (!$claimed) {
            return MCPHelper::error(
                'state_changed',
                __('This booking changed while the request was in flight and is no longer in a state that allows this action.', 'fluent-booking'),
                ['status' => Booking::where('id', $booking->id)->value('status')]
            );
        }

        $booking->status = $target;

        $notified = false;

        // The claim moved the persisted status ahead of the work that gives it
        // meaning — the order, the payment status, the activity row, the hooks.
        // Cancel and reject already put the row back when that work fails; this
        // branch makes the same claim, so it owes the same guarantee.
        try {
            // Confirming a booking that was paid for settles its order, exactly
            // as the admin's confirm does.
            if ($action === 'confirm' && $booking->payment_method && $booking->payment_order) {
                $settled = self::settlePayment($booking, $booking->payment_order);

                if (is_wp_error($settled)) {
                    self::rollbackStatus($booking->id, $priorStatus, $target);

                    return $settled;
                }

                // The admin's confirm writes this row too. Payment reporting
                // reads the activity trail, so skipping it would make an
                // agent-confirmed payment look like it never settled.
                do_action('fluent_booking/log_booking_activity', [
                    'booking_id'  => $booking->id,
                    'status'      => 'closed',
                    'type'        => 'success',
                    'title'       => __('Payment Successfully Completed', 'fluent-booking'),
                    /* translators: %s: the user who confirmed the booking */
                    'description' => sprintf(__('Payment marked as paid by %s through the MCP server.', 'fluent-booking'), self::actorName()),
                ]);

                do_action('fluent_booking/payment/update_payment_status_paid', $booking);
            }

            // Same commit point as the cancel branch.
            $notified = true;

            do_action('fluent_booking/booking_schedule_' . $target, $booking, $booking->calendar_event);

            do_action('fluent_booking/pre_after_booking_' . $target, $booking, $booking->calendar_event);

            $fresh = Booking::with(['calendar_event', 'calendar'])->find($booking->id);

            do_action('fluent_booking/after_booking_' . $target, $fresh, $fresh->calendar_event, $fresh);
        } catch (\Throwable $e) {
            self::logException('manage-booking:' . $action, $e);

            if ($notified) {
                return self::partiallyCompleted($booking->id, $target);
            }

            // Payment state is left as it is. A booking that is paid for but
            // still awaiting approval is a normal state here, not a broken one:
            // it is exactly where a gateway leaves a booking on an event that
            // requires confirmation. Rolling the order back would invent a
            // refund that never happened. Only the status claim is undone.
            self::rollbackStatus($booking->id, $priorStatus, $target);

            return MCPHelper::error(
                'transition_failed',
                __('The change could not be completed and the booking was left as it was. The site logged the details.', 'fluent-booking')
            );
        }

        return true;
    }

    /**
     * Mark an order paid and the booking with it, as one commit.
     *
     * The two rows state the same fact, and they were written in sequence: a
     * failure between them left the order settled while the booking still read
     * unpaid, which is a divergence no later call reconciles.
     *
     * Only the two writes are inside the transaction. The hooks stay outside
     * deliberately — a listener that makes an outbound request would otherwise
     * hold both row locks for the length of someone else's HTTP call.
     *
     * @param Booking $booking
     * @param object  $order
     *
     * @return true|\WP_Error
     */
    private static function settlePayment(Booking $booking, $order)
    {
        try {
            Helper::dbTransaction(function () use ($booking, $order) {
                $order->total_paid   = $order->total_amount;
                $order->completed_at = gmdate('Y-m-d H:i:s'); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                $order->status       = 'paid';
                $order->save();

                $booking->payment_status = 'paid';
                $booking->save();
            });
        } catch (\Throwable $e) {
            self::logException('manage-booking:confirm:payment', $e);

            return MCPHelper::error(
                'payment_settlement_failed',
                __('The booking was left as it was: its payment could not be settled. The site logged the details.', 'fluent-booking')
            );
        }

        return true;
    }

    /**
     * Claim the slot for every host this booking would occupy.
     *
     * @param CalendarSlot $event
     * @param string       $startTime
     * @param string       $endTime
     * @param int|null     $hostUserId a host the caller pinned, if any
     *
     * @return array|false
     */
    private static function lockSlot(CalendarSlot $event, $startTime, $endTime, $hostUserId)
    {
        if ($event->isRoundRobin()) {
            $lock = SlotLock::acquire($event->id, $startTime, null);

            return $lock ? [$lock] : false;
        }

        $hosts = $hostUserId ? [$hostUserId] : (array) $event->getHostIds();

        if (!$hosts) {
            $lock = SlotLock::acquire($event->id, $startTime, null);

            return $lock ? [$lock] : false;
        }

        return SlotLock::acquireInterval($event->id, $startTime, $endTime, $hosts);
    }

    /**
     * Undo a claimed status transition whose side effects did not complete.
     *
     * Conditional on the row still holding the status we claimed: if something
     * downstream already moved it on, that later state is the current truth and
     * stamping the old one back over it would be its own corruption.
     *
     * @param int    $bookingId
     * @param string $priorStatus
     * @param string $claimedStatus
     */
    private static function rollbackStatus($bookingId, $priorStatus, $claimedStatus)
    {
        Booking::where('id', $bookingId)
            ->where('status', $claimedStatus)
            ->update(['status' => $priorStatus]);
    }

    /**
     * A failure after the attendee was notified. Distinct from
     * `transition_failed`, which tells the agent nothing happened.
     *
     * @param int    $bookingId
     * @param string $status
     *
     * @return \WP_Error
     */
    private static function partiallyCompleted($bookingId, $status)
    {
        return MCPHelper::error(
            'partially_completed',
            /* translators: %s: the booking's new status */
            sprintf(__('The booking is now "%s" and the attendee has been notified, but part of the follow-up did not finish. Read the booking before acting on it again. The site logged the details.', 'fluent-booking'), $status),
            ['booking_id' => $bookingId, 'status' => $status]
        );
    }

    /**
     * Cancelling or rejecting a paid booking can refund it, but only when the
     * caller asks explicitly — an agent must never move money as a side effect
     * of a status change.
     */
    private static function maybeRefund(Booking $booking, $params)
    {
        if (!$booking->payment_method || !Arr::isTrue($params, 'refund_payment')) {
            return;
        }

        do_action('fluent_booking/refund_payment_' . $booking->payment_method, $booking, $booking->calendar_event);
    }

    /**
     * Edit an attendee's details on an existing booking. Reversible, so it is
     * not gated behind a confirm token — but it still writes an activity row.
     *
     * @param Booking $booking
     * @param array   $fields
     * @param array   $params
     *
     * @return Booking|\WP_Error
     */
    public static function updateDetails(Booking $booking, $fields, $params)
    {
        $fields = (array) $fields;

        $unknown = array_diff(array_keys($fields), self::EDITABLE_FIELDS);

        if ($unknown) {
            return MCPHelper::error(
                'unknown_field',
                sprintf(
                    /* translators: %1$s: rejected field names, %2$s: accepted field names */
                    __('These fields cannot be updated: %1$s. Editable fields are: %2$s. Use the status actions to change a booking\'s status.', 'fluent-booking'),
                    implode(', ', $unknown),
                    implode(', ', self::EDITABLE_FIELDS)
                )
            );
        }

        $updates = [];
        $changed = [];

        foreach ($fields as $key => $value) {
            if ($key === 'email') {
                $value = sanitize_email($value);

                if (!$value || !is_email($value)) {
                    return MCPHelper::error('invalid_email', __('That is not a valid email address.', 'fluent-booking'));
                }
            } elseif ($key === 'internal_note') {
                $value = sanitize_textarea_field($value);
            } else {
                $value = sanitize_text_field($value);
            }

            if ((string) $booking->{$key} === (string) $value) {
                continue;
            }

            $updates[$key] = $value;
            $changed[]     = $key;
        }

        if (!$updates) {
            return MCPHelper::error('no_change', __('None of the supplied values differ from what is already stored.', 'fluent-booking'));
        }

        $notify = self::wantsNotifications($params);

        $save = function () use ($booking, $updates) {
            $before = [];

            foreach ($updates as $key => $value) {
                $before[$key] = $booking->{$key};
            }

            $booking->fill($updates);
            $booking->save();

            foreach ($updates as $key => $value) {
                // patchBooking fires one hook per column; keeping that shape
                // means existing listeners (the changed-email notification
                // among them) behave identically.
                do_action('fluent_booking/after_patch_booking_' . $key, $booking, $booking->calendar_event, $before[$key]);
            }

            return true;
        };

        $notify ? $save() : NotificationGate::silently($save);

        self::logActivity(
            $booking,
            __('Booking Updated via MCP', 'fluent-booking'),
            /* translators: %1$s: acting user, %2$s: the updated field names */
            sprintf(__('%1$s updated %2$s through the MCP server.', 'fluent-booking'), self::actorName(), implode(', ', $changed)),
            $notify
        );

        return Booking::with(['calendar_event'])->find($booking->id);
    }

    /**
     * Re-send the confirmation email. Mirrors
     * SchedulesController::sendConfirmationEmail().
     *
     * @param Booking $booking
     * @param string  $emailTo 'guest' or 'host'
     *
     * @return array|\WP_Error
     */
    public static function resendEmail(Booking $booking, $emailTo, $params = [])
    {
        // The whole action is "send an email". Silently sending one after the
        // caller asked for silence — and after the dry run reported
        // notifications_requested:false — is worse than refusing.
        if (!self::wantsNotifications($params)) {
            return MCPHelper::error(
                'notifications_disabled',
                __('resend_email exists to send an email, so it cannot run with send_notifications:false. Drop that parameter, or use a different action.', 'fluent-booking')
            );
        }

        // The template says the booking is going ahead, so sending it for a
        // cancelled or rejected one tells the attendee the opposite of the truth.
        if (!in_array($booking->status, ['scheduled', 'rescheduled', 'pending'], true)) {
            return MCPHelper::error(
                'not_resendable',
                /* translators: %s: the booking's current status */
                sprintf(__('This booking is "%s", so resending its confirmation would tell the attendee it is going ahead.', 'fluent-booking'), $booking->status),
                ['status' => $booking->status]
            );
        }

        $emailTo = in_array($emailTo, ['guest', 'host'], true) ? $emailTo : 'guest';

        if (!WriteGuard::cooldown('resend:' . $booking->id . ':' . $emailTo, 60)) {
            return MCPHelper::error(
                'resend_too_soon',
                __('That confirmation was resent within the last minute. Wait before sending another.', 'fluent-booking'),
                ['next_step' => 'wait 60 seconds']
            );
        }

        $event = $booking->calendar_event;

        if (!$event) {
            return MCPHelper::error('event_missing', __('This booking has no event type, so its notification templates cannot be resolved.', 'fluent-booking'));
        }

        $notifications = $event->getNotifications();

        $key   = $emailTo === 'host' ? 'booking_conf_host' : 'booking_conf_attendee';
        $email = Arr::get($notifications, $key . '.email', []);

        if (!$email) {
            return MCPHelper::error(
                'no_template',
                __('This event type has no confirmation email configured for that recipient.', 'fluent-booking'),
                ['recipient' => $emailTo]
            );
        }

        $result = EmailNotificationService::emailOnBooked($booking, $email, $emailTo, 'scheduled', true);

        if (!$result) {
            return MCPHelper::error('send_failed', __('The notification could not be sent.', 'fluent-booking'));
        }

        self::logActivity(
            $booking,
            __('Confirmation Resent via MCP', 'fluent-booking'),
            /* translators: %1$s: acting user, %2$s: the recipient, guest or host */
            sprintf(__('%1$s re-sent the confirmation email to the %2$s through the MCP server.', 'fluent-booking'), self::actorName(), $emailTo),
            true
        );

        return ['recipient' => $emailTo, 'sent' => true];
    }

    /**
     * Whether the caller may act on this booking. Read access is not enough:
     * writing requires being one of the booking's hosts, or holding write
     * access to its calendar, or site-wide booking management.
     *
     * @param Booking $booking
     *
     * @return bool
     */
    public static function canWriteBooking(Booking $booking)
    {
        if (current_user_can('manage_options') || PermissionManager::userCan(['manage_all_data', 'manage_all_bookings'])) {
            return true;
        }

        $userId = get_current_user_id();

        if ((int) $booking->host_user_id === (int) $userId) {
            return true;
        }

        if (in_array((int) $userId, array_map('intval', (array) $booking->getHostIds()), true)) {
            return true;
        }

        // Match MeetingPolicy::hasBookingAccess(): a host may act on a booking of
        // an event they host, not on every booking on a calendar they can write.
        if (!PermissionManager::userCan('manage_own_calendar')) {
            return false;
        }

        $event = $booking->calendar_event ?: CalendarSlot::find($booking->event_id);

        return $event && in_array((int) $userId, array_map('intval', (array) $event->getHostIds()), true);
    }

    /**
     * Notifications are on unless the caller turns them off — a booking the
     * attendee never hears about is a strange default, and matches what the
     * same action in wp-admin would do.
     *
     * @param array $params
     *
     * @return bool
     */
    /**
     * Whether the caller asked for notifications on this change.
     *
     * Reported as `notifications_requested`, not `notifications_sent`: nothing
     * here waits on SMTP, calendar sync or Twilio, so it cannot claim delivery.
     * Failures land in the booking's activity log.
     *
     * @param array $params
     *
     * @return bool
     */
    public static function wantsNotifications($params)
    {
        if (!array_key_exists('send_notifications', (array) $params)) {
            return true;
        }

        return Arr::isTrue($params, 'send_notifications');
    }

    /**
     * Everything create() checks before it touches the slot engine, so a dry run
     * can run the same gauntlet.
     *
     * A preview that succeeds and an execute that then fails on `location_required`
     * is worse than no preview: the agent reports "ready to book" to a human,
     * gets approval, and only then discovers the call was never valid. The
     * preview is a promise about the execute, so it has to be checked against
     * the same rules.
     *
     * Availability is deliberately NOT part of this — it is re-checked at
     * execute time by design, and the preview says so.
     *
     * @param CalendarSlot $event
     * @param array        $params
     *
     * @return true|\WP_Error
     */
    public static function validateCreate(CalendarSlot $event, $params)
    {
        if ($event->status !== 'active') {
            return MCPHelper::error(
                'event_not_bookable',
                /* translators: %s: the event type's current status */
                sprintf(__('This event type is "%s", not active, so it is not accepting bookings.', 'fluent-booking'), $event->status),
                ['event_status' => $event->status]
            );
        }

        $email = sanitize_email(Arr::get($params, 'email', ''));

        if (!$email || !is_email($email)) {
            return MCPHelper::error('invalid_email', __('A valid attendee email is required.', 'fluent-booking'));
        }

        if (!sanitize_text_field(Arr::get($params, 'name', ''))) {
            return MCPHelper::error('missing_name', __('The attendee name is required.', 'fluent-booking'));
        }

        $timezone = MCPHelper::resolveTimezone(Arr::get($params, 'timezone', ''));

        $startTime = MCPHelper::toUtc(Arr::get($params, 'start_time', ''), $timezone);

        if (is_wp_error($startTime)) {
            return $startTime;
        }

        $hostUserId = self::resolveHostId($event, $params);

        if (is_wp_error($hostUserId)) {
            return $hostUserId;
        }

        $location = self::resolveLocation($event, $params);

        if (is_wp_error($location)) {
            return $location;
        }

        $customFieldsData = BookingFieldService::getCustomFieldsData(
            (array) Arr::get($params, 'custom_fields', []),
            $event
        );

        if (is_wp_error($customFieldsData)) {
            return MCPHelper::error(
                'invalid_custom_fields',
                $customFieldsData->get_error_message(),
                ['errors' => $customFieldsData->get_error_data()]
            );
        }

        return true;
    }

    /**
     * @param CalendarSlot $event
     * @param array        $params
     *
     * @return int|null|\WP_Error
     */
    private static function resolveHostId(CalendarSlot $event, $params)
    {
        return MCPHelper::resolveEventHost($event, Arr::get($params, 'host_id'));
    }

    /**
     * Log an unexpected failure without letting its text reach the client.
     *
     * @param string     $context
     * @param \Throwable $e
     */
    private static function logException($context, $e)
    {
        if (defined('FLUENT_BOOKING_DEBUG') && FLUENT_BOOKING_DEBUG) {
            error_log('FluentBooking MCP ' . $context . ' failed: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        /**
         * Fires when an MCP write fails with an unexpected exception.
         *
         * @since 2.3.0
         *
         * @param string     $context which write failed
         * @param \Throwable $e       the failure
         */
        do_action('fluent_booking/mcp_write_exception', $context, $e);
    }

    /**
     * Resolve the booking's location from the event's configured locations. An
     * agent may name a location type; when it does not, and the event offers
     * exactly one, we use it — asking a model to choose between one option is
     * a round-trip for nothing.
     *
     * @param CalendarSlot $event
     * @param array        $params
     *
     * @return array|\WP_Error
     */
    private static function resolveLocation(CalendarSlot $event, $params)
    {
        $locations = [];

        foreach ((array) $event->location_settings as $location) {
            if (!empty($location['type'])) {
                $locations[$location['type']] = $location;
            }
        }

        if (!$locations) {
            return MCPHelper::error('no_location', __('This event type has no location configured, so a booking cannot be created for it.', 'fluent-booking'));
        }

        $requested = sanitize_text_field(Arr::get($params, 'location_type', ''));

        if (!$requested) {
            if (count($locations) > 1) {
                return MCPHelper::error(
                    'location_required',
                    __('This event type offers more than one location. Pass location_type to choose one.', 'fluent-booking'),
                    ['available' => array_keys($locations)]
                );
            }

            $requested = key($locations);
        }

        if (!isset($locations[$requested])) {
            return MCPHelper::error(
                'invalid_location',
                /* translators: %s: the requested location type */
                sprintf(__('"%s" is not one of this event type\'s locations.', 'fluent-booking'), $requested),
                ['available' => array_keys($locations)]
            );
        }

        $config  = $locations[$requested];
        $details = ['type' => $requested];

        $supplied = sanitize_textarea_field(Arr::get($params, 'location_description', ''));

        // Attendee-supplied locations need a value from the caller; host-supplied
        // ones come from the event's own configuration.
        if (in_array($requested, ['phone_guest', 'in_person_guest'], true)) {
            if (!$supplied) {
                return MCPHelper::error(
                    'location_description_required',
                    /* translators: %s: the location type */
                    sprintf(__('The "%s" location needs location_description — the attendee\'s phone number or address.', 'fluent-booking'), $requested)
                );
            }

            $details['description'] = $supplied;
        } elseif ($requested === 'phone_organizer') {
            $details['description'] = Arr::get($config, 'host_phone_number', '');
        } elseif (in_array($requested, ['custom', 'in_person_organizer'], true)) {
            $details['description'] = Arr::get($config, 'description', '');
        } elseif (in_array($requested, ['google_meet', 'online_meeting', 'zoom_meeting', 'ms_teams'], true)) {
            $details['description']          = Arr::get($config, 'meeting_link', '');
            $details['online_platform_link'] = $details['description'];
        }

        return $details;
    }

    /**
     * Additional guests, clamped to whatever the event's guest field allows and,
     * for group events, to the seats actually left in the slot.
     *
     * @param array        $params
     * @param CalendarSlot $event
     * @param array|bool   $availableSpot
     *
     * @return array
     */
    private static function sanitizeGuests($params, CalendarSlot $event, $availableSpot)
    {
        $isMultiGuest = $event->isMultiGuestEvent();

        $guests   = [];
        $rejected = [];

        foreach ((array) Arr::get($params, 'guests', []) as $guest) {
            // Accept either shape. An agent naturally sends addresses; a group
            // event needs a name per seat, so an object is allowed too.
            if (is_array($guest)) {
                $email = sanitize_email((string) Arr::get($guest, 'email', ''));
                $name  = sanitize_text_field((string) Arr::get($guest, 'name', ''));
            } else {
                $email = sanitize_email((string) $guest);
                $name  = '';
            }

            if (!$email || !is_email($email)) {
                $rejected[] = [
                    'value'  => is_array($guest) ? (string) Arr::get($guest, 'email', '') : (string) $guest,
                    'reason' => 'invalid_email',
                ];
                continue;
            }

            if (!$isMultiGuest) {
                $guests[] = $email;
                continue;
            }

            // A multi-guest event seats each guest as their own attendee, and
            // BookingService::prepareBookingData() reads $guest['name'] and
            // $guest['email'] off every entry. Handing it bare strings raised
            // "Cannot access offset of type string on string" on PHP 8 and
            // produced nameless attendees on 7.4 — so the group-event path, the
            // one the seat arithmetic below exists for, could never work.
            $guests[] = [
                'name'  => $name ?: self::nameFromEmail($email),
                'email' => $email,
            ];
        }

        $guestField = BookingFieldService::getBookingFieldByName($event, 'guests');
        $limit      = (int) Arr::get($guestField, 'limit', 10);
        $reason     = 'over_field_limit';

        if ($isMultiGuest && is_array($availableSpot)) {
            $remaining = (int) Arr::get($availableSpot, 'remaining', $event->getMaxBookingPerSlot());

            // Minus one: the attendee themself takes a seat. On a group event
            // with a single seat left this is 0, which drops every guest.
            if (min($remaining, $limit) - 1 < $limit) {
                $reason = 'no_seats_left';
            }

            $limit = min($remaining, $limit) - 1;
        }

        $kept = $limit > 0 ? array_slice(array_values($guests), 0, $limit) : [];

        foreach (array_slice(array_values($guests), count($kept)) as $dropped) {
            $rejected[] = [
                'value'  => is_array($dropped) ? Arr::get($dropped, 'email', '') : $dropped,
                'reason' => $reason,
            ];
        }

        // Every one of these three drops used to be silent, so an agent asked
        // to book four people was told `created: true` for a booking with one.
        self::$guestsDropped = $rejected;

        return $kept;
    }

    /**
     * How many requested guests a create could actually seat, for the preview.
     *
     * Addresses and the guest field's own limit only — a group event's free
     * seats are re-read at execute time, so this is a ceiling, not a promise.
     *
     * @param CalendarSlot $event
     * @param array        $params
     *
     * @return int
     */
    public static function previewGuestCount(CalendarSlot $event, $params)
    {
        $valid = 0;

        foreach ((array) Arr::get($params, 'guests', []) as $guest) {
            $email = sanitize_email(is_array($guest) ? (string) Arr::get($guest, 'email', '') : (string) $guest);

            if ($email && is_email($email)) {
                $valid++;
            }
        }

        $guestField = BookingFieldService::getBookingFieldByName($event, 'guests');

        return min($valid, (int) Arr::get($guestField, 'limit', 10));
    }

    /**
     * Guests the last create() was asked for and did not book.
     *
     * Request-scoped: set by sanitizeGuests() during the create, read once by
     * the tool building the response. One MCP call creates one booking.
     *
     * @return array
     */
    public static function droppedGuests()
    {
        return self::$guestsDropped;
    }

    /**
     * A display name for a guest who was given as a bare address.
     *
     * @param string $email
     * @return string
     */
    private static function nameFromEmail($email)
    {
        $local = strstr((string) $email, '@', true);

        $name = trim(str_replace(['.', '_', '-', '+'], ' ', (string) $local));

        return $name ? ucwords($name) : (string) $email;
    }

    /**
     * Every MCP write leaves a trail naming the operator and the channel, so a
     * booking's timeline distinguishes an agent's action from a human's.
     *
     * @param Booking $booking
     * @param string  $title
     * @param string  $description
     * @param bool    $notified
     */
    private static function logActivity(Booking $booking, $title, $description, $notified)
    {
        if (!$notified) {
            $description .= ' ' . __('Notifications were suppressed for this action.', 'fluent-booking');
        }

        do_action('fluent_booking/log_booking_activity', [
            'booking_id'  => $booking->id,
            'type'        => 'info',
            'status'      => 'closed',
            'title'       => $title,
            'description' => $description,
        ]);
    }

    /**
     * @return string
     */
    private static function actorName()
    {
        $user = wp_get_current_user();

        if ($user && $user->exists()) {
            return $user->display_name ? $user->display_name : $user->user_login;
        }

        return __('An MCP client', 'fluent-booking');
    }
}
