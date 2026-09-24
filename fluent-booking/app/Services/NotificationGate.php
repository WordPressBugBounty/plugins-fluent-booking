<?php

namespace FluentBooking\App\Services;

/**
 * Suppresses booking notifications for the current request, and nothing else:
 * calendar sync, CRM triggers and webhooks still run, and reminders still go
 * out later. For backfills, migrations and agent writes that shouldn't email
 * the attendee.
 *
 *     $booking = NotificationGate::silently(function () use ($data) {
 *         return BookingService::createBooking($data);
 *     });
 *
 * Add-ons that send their own notifications (SMS, push) should check
 * isSuppressed() or hook `fluent_booking/suppress_notifications`.
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
     * Run $callback with notifications off, restoring the previous state even
     * if it throws.
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
