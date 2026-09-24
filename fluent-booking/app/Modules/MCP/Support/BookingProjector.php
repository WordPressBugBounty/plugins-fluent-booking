<?php

namespace FluentBooking\App\Modules\MCP\Support;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\BookingActivity;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Booking model → agent payload, at two levels of detail. Enforces the
 * response budget in docs/mcp-server-spec.md §10.
 *
 * `row()` is the ~120-token collection shape. `full()` is a single-record read;
 * form answers, guests, hosts and activity are still opt-in via `include[]`.
 *
 * `row()` masks the attendee email; `full()` returns it.
 *
 * Neither emits the booking `hash`: it is a bearer credential that lets an
 * unauthenticated request cancel the meeting
 * (FrontEndHandler::ajaxHandleCancelMeeting()). `get-booking` still accepts one.
 *
 * Attendee-typed text goes through MCPHelper::untrusted() and is grouped under
 * `attendee_supplied`, since the agent reading it also holds write tools.
 */
class BookingProjector
{
    /**
     * Relations to eager-load for row(), to avoid a query per row.
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
                // Attendee-authored, so neutralised even at the top level.
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
                // Host-authored, but still stripped: operators paste attendee
                // mail into notes.
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

        $data['attendee_supplied'] = self::attendeeSupplied(
            $booking,
            in_array('custom_fields', $include, true)
        );

        return $data;
    }

    /**
     * Everything on this booking a member of the public typed, in one labelled
     * object so the agent can tell it apart from site data. Every value has
     * been through MCPHelper::untrusted().
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

        // Keys only when set; a booking has at most one of these.
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
     * The attendee's custom-question answers as a list of {field, label, value}.
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

            // A list, not a map keyed by label: two labels can strip to the
            // same string and one answer would overwrite the other.
            $out[] = [
                'field' => (string) $key,
                // Labels are untrusted too: an agent can add fields via
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

        // Prime the user cache in one query.
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
     * The booking's activity timeline, newest 20 first.
     *
     * @param Booking $booking
     * @param string  $timezone
     * @return array
     */
    private static function activities(Booking $booking, $timezone)
    {
        $activities = [];

        $records = $booking->booking_activities()
            ->where('type', '!=', BookingActivity::TYPE_NOTE)
            ->orderBy('id', 'DESC')
            ->limit(20)
            ->get();

        foreach ($records as $activity) {
            $activities[] = array_merge(
                [
                    'type'        => $activity->type,
                    'title'       => $activity->title,
                    // Descriptions can embed attendee text, e.g. cancel reasons.
                    'description' => MCPHelper::untrusted($activity->description, 500),
                ],
                MCPHelper::timePair($activity->created_at, $timezone, 'at')
            );
        }

        return $activities;
    }

    /**
     * DateTime to a plain string; JSON-encoding the object gives three keys.
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
