<?php

namespace FluentBooking\App\Hooks\Handlers;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Meta;
use FluentBooking\Framework\Support\Arr;
use FluentBooking\App\Services\EditorShortCodeParser;
use FluentBooking\App\Services\Integrations\GlobalNotificationService;

class GlobalNotificationHandler
{
    /**
     * @var GlobalNotificationService
     */
    private $globalNotificationService;

    public function register()
    {
        add_action('fluent_booking/handle_global_notification_background', function ($bookingId, $feedId) {
            $booking = Booking::with(['calendar_event'])->find($bookingId);
            if (!$booking) {
                return;
            }

            $item = Meta::where('id', $feedId)->where('object_id', $booking->event_id)->first();

            if (!$item) {
                return;
            }

            // Prepare the data
            $feed = [
                'id'       => $item->id,
                'key'      => $item->key,
                'settings' => $item->value,
            ];

            $processedValues = $feed['settings'];
            $processedValues = EditorShortCodeParser::parse($processedValues, $booking);
            $feed['processedValues'] = $processedValues;

            do_action('fluent_booking/integration_notify_' . $feed['key'], $feed, $booking, $booking->calendar_event);
            return true;
        }, 10, 2);

        add_action('fluent_booking/after_booking_scheduled', [$this, 'maybeHandleGlobalIntegration'], 10, 2);
        add_action('fluent_booking/booking_schedule_cancelled', [$this, 'maybeHandleGlobalIntegration'], 10, 2);
        add_action('fluent_booking/booking_schedule_completed', [$this, 'maybeHandleGlobalIntegration'], 10, 2);
        add_action('fluent_booking/booking_schedule_rejected', [$this, 'maybeHandleGlobalIntegration'], 10, 2);
        add_action('fluent_booking/after_booking_rescheduled', [$this, 'checkGlobalIntegration'], 10, 3);
    }

    public function maybeHandleGlobalIntegration($booking, $calendarSlot, $status = null)
    {
        $status = $status ?: $booking->status;

        $maps = [
            'scheduled'   => 'after_booking_scheduled',
            'cancelled'   => 'booking_schedule_cancelled',
            'completed'   => 'booking_schedule_completed',
            'rescheduled' => 'after_booking_rescheduled',
            'rejected'    => 'booking_schedule_rejected'
        ];

        if (!isset($maps[$status])) {
            return false;
        }

        $currentHook = $maps[$status];

        return $this->globalNotify($booking, $calendarSlot, $currentHook);
    }


    private function globalNotify($booking, $calendarEvent, $targetHook = null)
    {
        $this->globalNotificationService = new GlobalNotificationService();

        // Let's find the feeds that are available for this form
        $feedKeys = apply_filters('fluent_booking/global_notification_active_types', [], $calendarEvent->id);

        if (!$feedKeys) {
            return false;
        }

        $feedMetaKeys = array_keys($feedKeys);
        $feeds = $this->globalNotificationService->getNotificationFeeds($calendarEvent->id, $feedMetaKeys);

        if (!$feeds) {
            return false;
        }

        // Now we have to filter the feeds which are enabled
        $enabledFeeds = $this->globalNotificationService->getEnabledFeeds($feeds, $booking);
        if (!$enabledFeeds) {
            return false;
        }

        foreach ($enabledFeeds as $feed) {

            $enabledTriggers = Arr::get($feed, 'settings.event_trigger', []);

            if (!$enabledTriggers || !in_array($targetHook, $enabledTriggers)) {
                continue;
            }

            // We will decide if this feed will run on async or sync
            $integrationKey = Arr::get($feedKeys, $feed['key']);

            if (apply_filters('fluent_booking/notifying_async_' . $integrationKey, false, $calendarEvent->id)) {
                as_enqueue_async_action('fluent_booking/handle_global_notification_background', [
                    $booking->id,
                    $feed['id']
                ]);
            } else {
                // It's sync
                $processedValues = $feed['settings'];
                $processedValues = EditorShortCodeParser::parse($processedValues, $booking);

                $feed['processedValues'] = $processedValues;
                do_action('fluent_booking/integration_notify_' . $feed['key'], $feed, $booking, $calendarEvent);
            }
        }

        return true;
    }

    public function checkGlobalIntegration($booking, $oldBooking, $calendarEvent)
    {
        return $this->maybeHandleGlobalIntegration($booking, $calendarEvent, 'rescheduled');
    }
}
