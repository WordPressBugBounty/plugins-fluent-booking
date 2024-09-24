<?php

namespace FluentBooking\App\Hooks\Handlers\CleanupHandlers;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Models\Availability;
use FluentBooking\App\Models\Meta;
use FluentBooking\Framework\Support\Arr;

class CalenderCleaner
{
    public function register()
    {
        add_action('fluent_booking/before_delete_calendar', [$this, 'handleBeforeDelete']);
    }

    public function handleBeforeDelete($calendar)
    {
        if (empty($calendar)) {
            return;
        }

        Meta::query()
            ->where('object_type', 'Calendar')
            ->where('object_id', $calendar->id)->delete();

        $calendarEvents = CalendarSlot::query()
            ->where('calendar_id', $calendar->id)->get();

        if ($calendarEvents->count()) {
            foreach ($calendarEvents as $event) {
                do_action('fluent_booking/before_delete_calendar_event', $event, $calendar);
                $event->delete();
                do_action('fluent_booking/after_delete_calendar_event', $event, $calendar);
            }
        }

        $teamEvents = CalendarSlot::query()
            ->where('calendar_id', '!=', $calendar->id)
            ->where(function ($query) {
                $query->where('event_type', 'round_robin')
                    ->orWhere('event_type', 'collective');
            })
            ->get();

        if ($teamEvents->count()) {
            foreach ($teamEvents as $event) {
                $teamMembers = Arr::get($event->settings, 'team_members', []);
                if (in_array($calendar->user_id, $teamMembers)) {
                    $updatedTeamMembers = array_filter($teamMembers, function ($memberId) use ($calendar) {
                        return $memberId != $calendar->user_id;
                    });
                    $event->settings = [
                        'team_members' => array_values($updatedTeamMembers) ?: [(int)$event->user_id]
                    ];
                    $event->save();

                    $calendarUserId = $calendar->user_id;
                    Booking::query()
                        ->where('event_id', $event->id)
                        ->whereHas('hosts', function ($query) use ($calendarUserId){
                            $query->where('user_id', $calendarUserId);
                        })->delete();
                }
            }
        }

        if ($calendar->type != 'simple') {
            return;
        }

        $availabilitySchedules = Availability::where('object_id', $calendar->user_id)->get();

        if ($availabilitySchedules->isNotEmpty()) {
            foreach ($availabilitySchedules as $schedule) {
                $eventAssociated = CalendarSlot::where('availability_id', $schedule->id)
                    ->where('user_id', '!=', $schedule->object_id)
                    ->count();

                if ($eventAssociated == 0) {
                    do_action('fluent_booking/before_delete_availability_schedule', $schedule, $calendar);
                    $schedule->delete();
                    do_action('fluent_booking/after_delete_availability_schedule', $schedule, $calendar);
                }
            }
        }
    }
}