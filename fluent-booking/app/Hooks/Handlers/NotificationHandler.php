<?php

namespace FluentBooking\App\Hooks\Handlers;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Services\EmailNotificationService;
use FluentBooking\Framework\Support\Arr;

class NotificationHandler
{
    public function register()
    {
        add_action('fluent_booking/after_booking_scheduled', [$this, 'pushBookingScheduledToQueue'], 10, 2);
        add_action('fluent_booking/after_booking_scheduled_async', [$this, 'bookingScheduledEmails'], 10, 1);
        add_action('fluent_booking/after_booking_pending', [$this, 'pushBookingPendingToQueue'], 10, 2);
        add_action('fluent_booking/after_booking_pending_async', [$this, 'bookingRequestEmails'], 10, 2);
        add_action('fluent_booking/booking_schedule_reminder', [$this, 'bookingReminderEmails'], 10, 2);
        add_action('fluent_booking/after_booking_rescheduled', [$this, 'emailOnBookingRescheduled'], 10, 3);
        add_action('fluent_booking/booking_schedule_cancelled', [$this, 'emailOnBookingCancelled'], 10, 2);
        add_action('fluent_booking/booking_schedule_rejected', [$this, 'emailOnBookingRejected'], 10, 2);
        add_action('fluent_booking/after_patch_booking_email', [$this, 'emailToUpdatedEmail'], 10, 2);
    }

    private function getReminderTime($time)
    {
        $timestamp = $time['value'] * 60;

        if ($time['unit'] == 'hours') {
            $timestamp = $timestamp * 60;
        } elseif ($time['unit'] == 'days') {
            $timestamp = $timestamp * 60 * 24;
        }

        return $timestamp;
    }

    private function pushRemindersToQueue($booking, $reminderTimes, $emailTo)
    {
        foreach ($reminderTimes as $time) {
            $reminderTimestamp = $this->getReminderTime($time);

            $happeningTimestamp = strtotime($booking->start_time);
            $startingTo = $happeningTimestamp - time();

            $bufferTime = 2 * 60; // 2 Minute Buffer Time
            if ($startingTo > ($reminderTimestamp + $bufferTime)) {
                as_schedule_single_action(($happeningTimestamp - $reminderTimestamp), 'fluent_booking/booking_schedule_reminder', [
                    $booking->id,
                    $emailTo
                ], 'fluent-booking');
            }
        }
    }

    public function pushBookingScheduledToQueue($booking, $bookingEvent)
    {
        $notifications = $bookingEvent->getNotifications();

        if (Arr::isTrue($notifications, 'booking_conf_attendee.enabled') || (Arr::isTrue($notifications, 'booking_conf_host.enabled'))) {
            as_enqueue_async_action('fluent_booking/after_booking_scheduled_async', [
                $booking->id,
                $bookingEvent->id
            ], 'fluent-booking');
        }

        if (Arr::isTrue($notifications, 'reminder_to_attendee.enabled')) {
            $reminderTimes = Arr::get($notifications, 'reminder_to_attendee.email.times', []);
            $this->pushRemindersToQueue($booking, $reminderTimes, 'guest');
        }

        if (Arr::isTrue($notifications, 'reminder_to_host.enabled')) {
            $reminderTimes = Arr::get($notifications, 'reminder_to_host.email.times', []);
            $this->pushRemindersToQueue($booking, $reminderTimes, 'host');
        }
    }

    public function pushBookingPendingToQueue($booking, $bookingEvent)
    {
        if (!$bookingEvent->isConfirmationEnabled() || $bookingEvent->isMultiGuestEvent()) {
            return;
        }

        if ($booking->payment_method && $booking->payment_status != 'paid') {
            return;
        }

        $notifications = $bookingEvent->getNotifications();

        if (Arr::isTrue($notifications, 'booking_request_host.enabled') || (Arr::isTrue($notifications, 'booking_request_attendee.enabled'))) {
            as_enqueue_async_action('fluent_booking/after_booking_pending_async', [
                $booking->id,
                $bookingEvent->id
            ], 'fluent-booking');
        }
    }

    public function emailToUpdatedEmail($booking, $calendarEvent)
    {
        $notifications = $calendarEvent->getNotifications();

        if (Arr::isTrue($notifications, 'booking_conf_attendee.enabled') || (Arr::isTrue($notifications, 'booking_conf_host.enabled'))) {
            as_enqueue_async_action('fluent_booking/after_booking_scheduled_async', [
                $booking->id,
                $calendarEvent->id
            ], 'fluent-booking');
        }
    }

    public function bookingScheduledEmails($bookingId)
    {
        $booking = Booking::with(['calendar', 'calendar_event'])->find($bookingId);

        if (!$booking || !$booking->calendar_event) {
            return '';
        }

        $notifications = $booking->calendar_event->getNotifications();

        if (Arr::isTrue($notifications, 'booking_conf_attendee.enabled')) {
            $email = Arr::get($notifications, 'booking_conf_attendee.email', []);
            EmailNotificationService::emailOnBooked($booking, $email, 'guest');
        }

        if (Arr::isTrue($notifications, 'booking_conf_host.enabled')) {
            $email = Arr::get($notifications, 'booking_conf_host.email', []);
            $additionalRecipients = Arr::get($email, 'additional_recipients', false);
            if ($additionalRecipients) {
                $email['recipients'] = $this->getAdditionalRecipients($additionalRecipients);
            }
            EmailNotificationService::emailOnBooked($booking, $email, 'host');
        }

        return true;
    }

    /**
     * @param $booking Booking
     * @return void
     */
    public function bookingReminderEmails($bookingId, $emailTo)
    {
        $booking = Booking::with(['calendar_event', 'calendar'])->find($bookingId);

        if (!$booking || $booking->status != 'scheduled') {
            return false;
        }

        $notifications = $booking->calendar_event->getNotifications();

        if (!$notifications) {
            return;
        }

        if ('guest' == $emailTo && Arr::isTrue($notifications, 'reminder_to_attendee.enabled')) {
            $email = Arr::get($notifications, 'reminder_to_attendee.email', []);
            EmailNotificationService::reminderEmail($booking, $email, $emailTo);
        }

        if ('host' == $emailTo && Arr::isTrue($notifications, 'reminder_to_host.enabled')) {
            $email = Arr::get($notifications, 'reminder_to_host.email', []);
            $additionalRecipients = Arr::get($email, 'additional_recipients', false);
            if ($additionalRecipients) {
                $email['recipients'] = $this->getAdditionalRecipients($additionalRecipients);
            }
            EmailNotificationService::reminderEmail($booking, $email, $emailTo);
        }
    }

    public function bookingRequestEmails($bookingId, $calendarEventId)
    {
        $booking = Booking::with(['calendar', 'calendar_event'])->find($bookingId);

        if (!$booking || !$booking->calendar_event) {
            return '';
        }

        $notifications = $booking->calendar_event->getNotifications();

        if (Arr::isTrue($notifications, 'booking_request_attendee.enabled')) {
            $email = Arr::get($notifications, 'booking_request_attendee.email', []);
            EmailNotificationService::emailOnBooked($booking, $email, 'guest', 'request');
        }

        if (Arr::isTrue($notifications, 'booking_request_host.enabled')) {
            $email = Arr::get($notifications, 'booking_request_host.email', []);
            $additionalRecipients = Arr::get($email, 'additional_recipients', false);
            if ($additionalRecipients) {
                $email['recipients'] = $this->getAdditionalRecipients($additionalRecipients);
            }
            EmailNotificationService::emailOnBooked($booking, $email, 'host', 'request');
        }

        return true;
    }

    public function getAdditionalRecipients($additionalRecipients)
    {
        if ($additionalRecipients) {
            $recipients = explode(',', $additionalRecipients);
            $recipients = array_map('trim', $recipients);
            return array_unique($recipients);
        }
        return [];
    }

    public function emailOnBookingCancelled(Booking $booking, $calendarEvent)
    {
        if (!$calendarEvent) {
            return;
        }

        $notifications = $calendarEvent->getNotifications();
        if (!$notifications) {
            return;
        }

        $cancelledBy = $booking->getMeta('cancelled_by_type', 'host');

        if ($cancelledBy == 'host') {
            if (Arr::isTrue($notifications, 'cancelled_by_host.enabled')) {
                // This from the host
                $email = Arr::get($notifications, 'cancelled_by_host.email', []);
                EmailNotificationService::bookingCancelOrRejectEmail($booking, $email, 'guest');
            }
            return;
        }

        if (Arr::isTrue($notifications, 'cancelled_by_attendee.enabled')) {
            $email = Arr::get($notifications, 'cancelled_by_attendee.email', []);
            $additionalRecipients = Arr::get($email, 'additional_recipients', false);
            if ($additionalRecipients) {
                $email['recipients'] = $this->getAdditionalRecipients($additionalRecipients);
            }
            EmailNotificationService::bookingCancelOrRejectEmail($booking, $email, 'host');
        }
    }

    public function emailOnBookingRescheduled(Booking $booking, $oldBooking, $calendarEvent)
    {
        if (!$calendarEvent) {
            return;
        }

        $notifications = $calendarEvent->getNotifications();
        if (!$notifications) {
            return;
        }

        // Remove all reminders
        as_unschedule_all_actions('fluent_booking/booking_schedule_reminder', [$oldBooking->id, 'host'], 'fluent-booking');
        as_unschedule_all_actions('fluent_booking/booking_schedule_reminder', [$oldBooking->id, 'guest'], 'fluent-booking');

        if (Arr::isTrue($notifications, 'reminder_to_attendee.enabled')) {
            $reminderTimes = Arr::get($notifications, 'reminder_to_attendee.email.times', []);
            $this->pushRemindersToQueue($booking, $reminderTimes, 'guest');
        }

        if (Arr::isTrue($notifications, 'reminder_to_host.enabled')) {
            $reminderTimes = Arr::get($notifications, 'reminder_to_host.email.times', []);
            $this->pushRemindersToQueue($booking, $reminderTimes, 'host');
        }

        $rescheduledBy = $booking->getMeta('rescheduled_by_type', 'host');

        if ($rescheduledBy == 'host') {
            if (Arr::isTrue($notifications, 'rescheduled_by_host.enabled')) {
                // This from the host
                $email = Arr::get($notifications, 'rescheduled_by_host.email', []);
                EmailNotificationService::bookingRescheduledEmail($booking, $email, 'guest');
            }
            return;
        }

        if (Arr::isTrue($notifications, 'rescheduled_by_attendee.enabled')) {
            $email = Arr::get($notifications, 'rescheduled_by_attendee.email', []);
            $additionalRecipients = Arr::get($email, 'additional_recipients', false);
            if ($additionalRecipients) {
                $email['recipients'] = $this->getAdditionalRecipients($additionalRecipients);
            }
            EmailNotificationService::bookingRescheduledEmail($booking, $email, 'host');
        }
    }

    public function emailOnBookingRejected(Booking $booking, $calendarEvent)
    {
        if (!$calendarEvent) {
            return;
        }

        $notifications = $calendarEvent->getNotifications();
        if (!$notifications) {
            return;
        }

        if (Arr::isTrue($notifications, 'declined_by_host.enabled')) {
            $email = Arr::get($notifications, 'declined_by_host.email', []);
            EmailNotificationService::bookingCancelOrRejectEmail($booking, $email, 'guest', 'reject');
        }
    }
}
