<?php

namespace FluentBooking\App\Services;

/**
 * A request-scoped switch that stops booking notifications from going out.
 *
 * Some programmatic callers legitimately need to change a booking without
 * emailing the attendee — an operator backfilling a booking that was already
 * agreed on the phone, a data migration, an AI agent acting on the host's
 * behalf. There was previously no way to express that: notifications are wired
 * to the booking lifecycle hooks, and suppressing them meant unhooking whole
 * actions, which also silenced calendar sync, CRM triggers and webhooks.
 *
 * This gate is deliberately narrow. It suppresses *notifications only*.
 * Everything else a booking triggers still runs.
 *
 * The gate is request-scoped, so it covers notifications sent during the call
 * only. Reminders belong to the booking, not to the operation that moved it:
 * a silent create or reschedule still leaves the event's reminder schedule
 * intact, and the attendee gets their reminder as normal.
 *
 * Usage:
 *
 *     $booking = NotificationGate::silently(function () use ($data) {
 *         return BookingService::createBooking($data);
 *     });
 *
 * Add-ons that send their own notifications (SMS, push) should check
 * NotificationGate::isSuppressed() at the top of their handlers, or hook the
 * `fluent_booking/suppress_notifications` filter.
 *
 * @since 2.2.6
 */
class NotificationGate
{
    /**
     * @var bool
     */
    private static $suppressed = false;

    /**
     * Run $callback with notifications turned off, then restore the previous
     * state — including when $callback throws, so one failed call cannot leave
     * the rest of the request silent.
     *
     * @param callable $callback
     *
     * @return mixed Whatever $callback returns.
     */
    public static function silently(callable $callback)
    {
        $previous = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            self::$suppressed = $previous;
        }
    }

    /**
     * @param string $context A hint about which notification is being gated,
     *                        e.g. 'booking_scheduled' or 'booking_cancelled'.
     * @param mixed  $booking The booking in play, when there is one.
     *
     * @return bool
     */
    public static function isSuppressed($context = '', $booking = null)
    {
        /**
         * Whether booking notifications should be skipped for this call.
         *
         * @since 2.2.6
         *
         * @param bool   $suppressed Current state of the request-scoped gate.
         * @param string $context    Which notification is being gated.
         * @param mixed  $booking    The booking in play, or null.
         */
        return (bool) apply_filters('fluent_booking/suppress_notifications', self::$suppressed, $context, $booking);
    }
}
