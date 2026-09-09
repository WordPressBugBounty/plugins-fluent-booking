<?php

namespace FluentBooking\App\Modules\MCP\Support;

use FluentBooking\App\Models\Booking;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Booking model → agent-facing payload, at two levels of detail.
 *
 * This class is where the response budget in docs/mcp-server-spec.md §10 is
 * actually enforced, so both shapes are deliberate rather than "whatever the
 * model has".
 *
 * `row()` is what a collection returns: about 120 tokens, enough to identify a
 * booking, sort it, and decide whether to open it. `full()` is a single-record
 * read and can afford everything — but even there the expensive pieces (form
 * answers, guests, hosts, activity) are opt-in through `include[]`, because most
 * questions about a booking do not need any of them.
 *
 * PII rule: `row()` masks the attendee email. A collection of twenty bookings
 * has no business emitting twenty live addresses — that is both a disclosure
 * surface and a pointless token cost. The real address is available from
 * `full()`, which is a deliberate single-record read the caller had to ask for.
 *
 * Neither shape emits the booking `hash`. That value is a bearer credential:
 * FrontEndHandler::ajaxHandleCancelMeeting() accepts it from an unauthenticated
 * request as sufficient authority to cancel the meeting. Putting twenty of them
 * in a list response would push twenty cancel-anything tokens into a model
 * provider's context and whatever transcript the client keeps — while masking
 * the email beside them. Agents address bookings by `id`; `get-booking` still
 * ACCEPTS a hash so an operator can paste one, it just never hands one out.
 *
 * Trust rule: every string an attendee typed goes through MCPHelper::untrusted()
 * and is grouped under one `attendee_supplied` object carrying an explicit
 * warning, rather than being scattered among fields the site itself wrote. The
 * agent reading this response also holds create-booking, manage-booking and the
 * scheduling write tools, so "who wrote this text" is a security property here,
 * not a presentation detail.
 */
class BookingProjector
{
    /**
     * Relations a collection query needs eager-loaded. Without this a
     * twenty-row list fires twenty extra queries for the event title alone.
     *
     * @return array
     */
    public static function rowRelations()
    {
        return ['calendar_event'];
    }

    /**
     * Compact projection for collections.
     *
     * @param Booking $booking
     * @param string  $timezone  resolved IANA identifier
     * @param bool    $includePii unmask the attendee email
     * @return array
     */
    public static function row(Booking $booking, $timezone, $includePii = false)
    {
        $event = $booking->calendar_event;

        $email = (string) $booking->email;

        return array_merge(
            [
                'id'          => (int) $booking->id,
                'event_id'    => (int) $booking->event_id,
                'event_title' => $event ? MCPHelper::untrusted($event->title, 200) : '',
                'event_type'  => $booking->event_type,
                'calendar_id' => (int) $booking->calendar_id,
                'host_user_id' => (int) $booking->host_user_id,
                'group_id'    => $booking->group_id === null ? null : (int) $booking->group_id,
                'status'      => $booking->status,
                'duration'    => (int) $booking->slot_minutes,
                // Attendee-authored, so neutralised even though it sits at the
                // top level: a name is needed for display on every row, and a
                // display name is a poor place to hide an instruction.
                'attendee'    => MCPHelper::untrusted(trim($booking->first_name . ' ' . $booking->last_name), 200),
                'email'       => $includePii ? $email : MCPHelper::maskEmail($email),
            ],
            MCPHelper::timePair($booking->start_time, $timezone, 'start'),
            MCPHelper::timePair($booking->end_time, $timezone, 'end')
        );
    }

    /**
     * Full projection for a single-record read.
     *
     * @param Booking $booking
     * @param string  $timezone
     * @param array   $include  any of: custom_fields, attendees, hosts, activities
     * @return array
     */
    public static function full(Booking $booking, $timezone, $include = [])
    {
        $include = (array) $include;

        $event = $booking->calendar_event;

        $data = array_merge(
            self::row($booking, $timezone, true),
            [
                'attendee_timezone' => in_array($booking->person_time_zone, timezone_identifiers_list(), true)
                    ? $booking->person_time_zone
                    : null,
                'phone'             => MCPHelper::untrusted($booking->phone, 60),
                'country'           => $booking->country,
                // Host-authored, so it stays out of the untrusted envelope —
                // but still stripped, because operators paste attendee mail
                // into these.
                'internal_note'     => MCPHelper::untrusted($booking->internal_note),
                'location'          => MCPHelper::untrusted($booking->getLocationAsText(), 500),
                'source'            => $booking->source,
                'payment_status'    => $booking->payment_status,
                'payment_method'    => $booking->payment_method,
                'created_at'        => self::asString($booking->created_at),
                'event_duration'    => $event ? (int) $event->duration : null,
            ]
        );

        if (in_array('attendees', $include, true)) {
            $data['additional_guests'] = array_values((array) $booking->getAdditionalGuests());
            $data['total_guests']      = (int) $booking->getTotalGuestCount();
        }

        if (in_array('hosts', $include, true)) {
            $data['hosts'] = self::hosts($booking);
        }

        if (in_array('activities', $include, true)) {
            $data['activities'] = self::activities($booking, $timezone);
        }

        // Last key in the object, and the only one that carries the warning.
        $data['attendee_supplied'] = self::attendeeSupplied(
            $booking,
            in_array('custom_fields', $include, true)
        );

        return $data;
    }

    /**
     * Everything on this booking that a member of the public typed.
     *
     * Kept as one labelled object rather than spread through the response, for
     * the same reason a query parameter is bound rather than concatenated: the
     * consumer needs to be able to tell, structurally, where its own data ends
     * and someone else's input begins. The consumer here is a model that also
     * holds the write tools, and the input arrives through an unauthenticated
     * booking form.
     *
     * Every value has already been through MCPHelper::untrusted().
     *
     * @param Booking $booking
     * @param bool    $withCustomFields
     * @return array
     */
    private static function attendeeSupplied(Booking $booking, $withCustomFields)
    {
        $supplied = ['_trust' => MCPHelper::TRUST_NOTICE];

        if ($message = MCPHelper::untrusted($booking->getMessage())) {
            $supplied['message'] = $message;
        }

        // A booking carries at most one of these, so always-present nulls would
        // be three wasted keys on every read.
        if ($cancel = MCPHelper::untrusted($booking->getCancelReason(true))) {
            $supplied['cancel_reason'] = $cancel;
            $supplied['cancelled_by']  = $booking->cancelled_by;
        }

        if ($reject = MCPHelper::untrusted($booking->getRejectReason(true))) {
            $supplied['reject_reason'] = $reject;
        }

        if ($reschedule = MCPHelper::untrusted($booking->getRescheduleReason())) {
            $supplied['reschedule_reason'] = $reschedule;
        }

        if ($withCustomFields) {
            $supplied['custom_fields'] = self::customFields($booking);
        }

        return $supplied;
    }

    /**
     * The attendee's answers to the event's custom questions, as a list of
     * {field, label, value}. The formatted form carries render metadata the
     * agent has no use for.
     *
     * @param Booking $booking
     * @return array
     */
    private static function customFields(Booking $booking)
    {
        $fields = $booking->getCustomFormData();

        if (!is_array($fields)) {
            return [];
        }

        $out = [];

        foreach ($fields as $key => $field) {
            if (is_array($field)) {
                $label = Arr::get($field, 'label', $key);
                $value = Arr::get($field, 'value', '');
            } else {
                $label = $key;
                $value = $field;
            }

            // A LIST keyed by the stable field name, not a map keyed by the
            // display label. Labels are attendee-visible text that has just been
            // stripped and truncated, so two distinct fields ("<b>Phone</b>" and
            // "Phone") can normalise to the same string — and as array keys the
            // second would silently overwrite the first, losing an answer with
            // no trace.
            $out[] = [
                'field' => (string) $key,
                // Both halves are attendee-reachable: the answer obviously, and
                // the label on any field an agent was allowed to add through
                // manage-event-type.
                'label' => MCPHelper::untrusted($label, 200),
                'value' => MCPHelper::untrusted($value),
            ];
        }

        return $out;
    }

    /**
     * Hosts on the booking. Team events (round-robin, collective) put every
     * assigned host on the pivot table, so host_user_id alone under-reports.
     *
     * @param Booking $booking
     * @return array
     */
    private static function hosts(Booking $booking)
    {
        $hosts     = [];
        $hostRows  = $booking->bookingHosts;
        $userIds   = [];

        foreach ($hostRows as $bookingHost) {
            $userIds[] = (int) $bookingHost->user_id;
        }

        // One query for the lot rather than one per host.
        if ($userIds) {
            cache_users(array_unique($userIds));
        }

        foreach ($hostRows as $bookingHost) {
            $user = get_user_by('ID', $bookingHost->user_id);

            $hosts[] = [
                'user_id' => (int) $bookingHost->user_id,
                'name'    => $user ? MCPHelper::untrusted($user->display_name, 200) : '',
                'status'  => $bookingHost->status,
            ];
        }

        return $hosts;
    }

    /**
     * The booking's activity timeline, newest first and capped: an old booking
     * can carry dozens of entries and the recent ones are what explain its
     * current state.
     *
     * @param Booking $booking
     * @param string  $timezone
     * @return array
     */
    private static function activities(Booking $booking, $timezone)
    {
        $activities = [];

        $records = $booking->booking_activities()
            ->orderBy('id', 'DESC')
            ->limit(20)
            ->get();

        foreach ($records as $activity) {
            $activities[] = array_merge(
                [
                    'type'        => $activity->type,
                    'title'       => $activity->title,
                    // Activity descriptions embed cancellation reasons and
                    // other attendee text, so they are untrusted too.
                    'description' => MCPHelper::untrusted($activity->description, 500),
                ],
                MCPHelper::timePair($activity->created_at, $timezone, 'at')
            );
        }

        return $activities;
    }

    /**
     * The ORM returns DateTime objects for timestamp columns; JSON-encoding one
     * produces three keys where a single string will do.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function asString($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value === null ? null : (string) $value;
    }
}
