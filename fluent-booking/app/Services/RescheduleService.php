<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\CalendarSlot;

/**
 * Moves an existing booking to a new time.
 *
 * This logic used to live inline in FrontEndHandler::handleRescheduling(), where
 * it was reachable only through the public booking form and signalled every
 * failure with wp_send_json() — which made it impossible to call from anywhere
 * else without terminating the request. It is extracted here so the public form
 * and programmatic callers (the MCP server among them) run the exact same steps
 * and emit the exact same hooks. Failures come back as WP_Error; the caller
 * decides how to render them.
 *
 * Behaviour is intentionally identical to the original inline version: a
 * reschedule changes the time and leaves `status` alone.
 *
 * @since 2.2.6
 */
class RescheduleService
{
    /**
     * @param Booking      $booking       The booking being moved.
     * @param CalendarSlot $calendarEvent The event the booking belongs to.
     * @param string       $startTime     New start time, UTC 'Y-m-d H:i:s'.
     * @param string       $timezone      The attendee's IANA timezone.
     * @param array        $args {
     *     @type string $reason       Rescheduling reason, stored as booking meta.
     *     @type int    $host_user_id Resolved host for round-robin events.
     *     @type string $source       Where the request came from, used in the
     *                                activity log. Defaults to 'Web UI'.
     *     @type string $rescheduled_by Force 'host' or 'guest' instead of
     *                                deriving it from the current user.
     * }
     *
     * @return Booking|\WP_Error The updated booking, or the reason it was refused.
     */
    public static function reschedule(Booking $booking, CalendarSlot $calendarEvent, $startTime, $timezone, $args = [])
    {
        // The booking must be rescheduled against its own event. Reject mixed-object
        // requests where the posted event_id differs from the booking's event so the
        // availability validation cannot be performed under a different event than the
        // one actually being modified.
        if ((int) $booking->event_id !== (int) $calendarEvent->id) {
            return new \WP_Error('invalid_reschedule_request', __('Invalid rescheduling request', 'fluent-booking'), ['status' => 422]);
        }

        $rescheduleBy = isset($args['rescheduled_by']) ? $args['rescheduled_by'] : self::resolveRescheduledBy($booking);

        if ($rescheduleBy == 'guest' && !$booking->canReschedule()) {
            return new \WP_Error('reschedule_not_allowed', $booking->getRescheduleMessage(), ['status' => 422]);
        }

        if ($startTime == $booking->start_time) {
            return new \WP_Error('same_time', __('Sorry! you can not reschedule to the same time.', 'fluent-booking'), ['status' => 422]);
        }

        $endDateTime = gmdate('Y-m-d H:i:s', strtotime($startTime) + ($booking->slot_minutes * 60)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $previousBooking = clone $booking;

        $reason = isset($args['reason']) ? sanitize_textarea_field($args['reason']) : '';

        // The pivot is synced before the row is saved, so a failure between them
        // left hosts() naming the new host while the row said the old one.
        try {
            Helper::dbTransaction(function () use ($booking, $rescheduleBy, $startTime, $timezone, $endDateTime, $previousBooking, $reason, $args) {
                // Below the guards: both return, so writing above them stamped a
                // reschedule that never happened, and NotificationHandler reads
                // this to pick the host- or attendee-worded email.
                $booking->updateMeta('rescheduled_by_type', $rescheduleBy);

                if ($booking->isMultiGuestBooking()) {
                    // Need to handle group booking type here
                    // check for existing group
                    $parent = Booking::where('status', 'scheduled')
                        ->where('event_id', $booking->event_id)
                        ->where('start_time', $startTime)
                        ->orderBy('id', 'ASC')
                        ->first();

                    if ($parent) {
                        $booking->group_id = $parent->group_id;
                    } else {
                        $booking->group_id = Helper::getNextBookingGroup();
                    }
                }

                if ($booking->isRoundRobinBooking() && !empty($args['host_user_id'])) {
                    $hostId = (int) $args['host_user_id'];
                    $booking->host_user_id = $hostId;
                    $booking->hosts()->sync([$hostId]);
                }

                $booking->start_time = $startTime;
                $booking->person_time_zone = $timezone;
                $booking->end_time = $endDateTime;
                $booking->save();

                $booking->updateMeta('previous_meeting_time', $previousBooking->start_time);

                if ($reason) {
                    $booking->updateMeta('reschedule_reason', $reason);
                }
            });
        } catch (\Throwable $e) {
            return new \WP_Error('reschedule_failed', __('The booking could not be moved and was left where it was.', 'fluent-booking'), ['status' => 500]);
        }

        $source = isset($args['source']) ? $args['source'] : __('Web UI', 'fluent-booking');

        do_action('fluent_booking/log_booking_activity', [
            'booking_id'  => $booking->id,
            'type'        => 'info',
            'status'      => 'closed',
            'title'       => __('Meeting Rescheduled', 'fluent-booking'),
            /* translators: %1$s is the user who rescheduled the meeting, %2$s is where the request came from, %3$s is the previous date and time in UTC. */
            'description' => sprintf(__('Meeting has been rescheduled by %1$s from %2$s. Previous date time: %3$s (UTC)', 'fluent-booking'), $rescheduleBy, $source, $previousBooking->start_time)
        ]);

        do_action('fluent_booking/after_booking_rescheduled', $booking, $previousBooking, $calendarEvent);

        return $booking;
    }

    /**
     * A reschedule is "by host" when the current user is one of the booking's
     * hosts or holds site-wide booking permissions; otherwise it is the guest
     * acting on their own booking link.
     *
     * @return string 'host'|'guest'
     */
    public static function resolveRescheduledBy(Booking $booking)
    {
        $hostIds = $booking->getHostIds();

        if (in_array(get_current_user_id(), $hostIds) || PermissionManager::userCan(['manage_all_data', 'manage_all_bookings'])) {
            return 'host';
        }

        return 'guest';
    }
}
