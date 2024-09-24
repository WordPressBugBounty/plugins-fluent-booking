<?php

namespace FluentBooking\App\Http\Controllers;

use FluentBooking\App\Models\Availability;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\Helper;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\App\Services\SanitizeService;
use FluentBooking\App\Services\AvailabilityService;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\Framework\Support\Arr;

class AvailabilityController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->get('filters', []);

        $query = Availability::orderBy('id', 'desc');

        $host = Arr::get($filters, 'author');

        if ($host == 'me') {
            $host = get_current_user_id();
        } else if ($host !== 'all') {
            $host = (int)$host;
        }

        if (!PermissionManager::userCan(['read_and_use_other_availabilities','manage_other_availabilities'])) {
            $host = get_current_user_id();
        }

        if ($host && $host !== 'all') {
            $query->where('object_id', $host);
        }

        do_action_ref_array('fluent_booking/availability_schedules_query', [&$query]);

        $schedules = $query->paginate();

        do_action('fluent_booking/availability_schedules', $schedules);

        $formattedSchedules = [];
        foreach ($schedules as $schedule) {

            $timezone = Arr::get($schedule, 'value.timezone', 'UTC');

            $author = $schedule->getAuthor();

            $formattedSchedules[] = [
                'id'          => $schedule->id,
                'host_name'   => $author['name'],
                'host_avatar' => $author['avatar'],
                'title'       => $schedule->key,
                'usage_count' => AvailabilityService::getAvailabilityUsageCount($schedule->id),
                'created_at'  => $schedule->created_at->format('Y-m-d H:i:s'),
                'settings'    => [
                    'default'          => Arr::isTrue($schedule, 'value.default'),
                    'timezone'         => $timezone,
                    'date_overrides'   => SanitizeService::slotDateOverrides(Arr::get($schedule, 'value.date_overrides', []), 'UTC', $timezone),
                    'weekly_schedules' => SanitizeService::weeklySchedules(Arr::get($schedule, 'value.weekly_schedules', []), 'UTC', $timezone)
                ]
            ];
        }

        return [
            'availabilities' => [
                'data'     => $formattedSchedules,
                'total'    => $schedules->total(),
                'per_page' => $schedules->perPage(),
            ],
        ];
    }

    public function getSchedule(Request $request, $scheduleId)
    {
        $schedule = Availability::findOrFail($scheduleId);

        $formattedSchedule = AvailabilityService::getFormattedSchedule($schedule);

        return [
            'schedule' => $formattedSchedule,
        ];
    }

    public function getAvailabilityUsages(Request $request, $scheduleId)
    {
        $availabilityUsages = CalendarSlot::with(['calendar'])
            ->where('availability_type', 'existing_schedule')
            ->where('availability_id', $scheduleId)
            ->latest()
            ->paginate();

        return [
            'usages' => $availabilityUsages
        ];
    }

    public function createSchedule(Request $request)
    {
        $userId = get_current_user_id();

        $data = $request->all();

        $this->validate($data, [
            'title' => 'required',
        ]);

        $isTitleExist = AvailabilityService::isTitleAlreadyExist($data['title'], $userId);

        if ($isTitleExist) {
            /* translators: %s is the existing availability title */
            $message = sprintf(__('%s is already exist', 'fluent-booking'), $data['title']);
            return $this->sendError([
                'message' => $message,
            ], 422);
        }

        $timezone = $request->get('timezone');

        if (!$timezone) {
            $calendar = Calendar::where('user_id', $userId)->where('type', 'simple')->first();
            if ($calendar) {
                $timezone = $calendar->author_timezone;
            } else {
                $timezone = 'UTC';
            }
        }

        // Check if the author has existing schedule
        $existingSchedule = Availability::where('object_id', $userId)->first();

        $scheduleData = AvailabilityService::createScheduleSchema($userId, $data['title'], !$existingSchedule, $timezone);

        $createdSchedule = Availability::create($scheduleData);

        do_action('fluent_booking/availability_schedule_created', $createdSchedule);

        return [
            'schedule' => $createdSchedule,
            'message'  => __('Schedule has been created successfully', 'fluent-booking'),
        ];
    }

    public function cloneSchedule(Request $request, $scheduleId)
    {
        $originalSchedule = Availability::findOrFail($scheduleId);

        $clonedSchedule = $originalSchedule->replicate();

        $clonedSchedule->object_id = get_current_user_id();

        $clonedSchedule->key = $clonedSchedule->key . ' (Clone)';

        $clonedScheduleValue = $clonedSchedule->value;

        $clonedScheduleValue['default'] = false;

        $clonedSchedule->value = $clonedScheduleValue;

        $clonedSchedule->save();

        do_action('fluent_booking/availability_schedule_cloned', $clonedSchedule);

        return [
            'schedule' => $clonedSchedule,
            'message'  => __('Schedule has been cloned successfully', 'fluent-booking'),
        ];
    }

    public function updateSchedule(Request $request, $scheduleId)
    {
        $schedule = Availability::findOrFail($scheduleId);

        $scheduleTimezone = Arr::get($schedule, 'value.timezone');

        $data = $request->all();

        $timezone = Arr::get($data, 'schedule.settings.timezone', $scheduleTimezone);

        $scheduleData = [
            'default'          => Arr::isTrue($schedule, 'value.default'),
            'timezone'         => $timezone,
            'date_overrides'   => SanitizeService::slotDateOverrides(Arr::get($data, 'schedule.settings.date_overrides', []), $timezone, 'UTC'),
            'weekly_schedules' => SanitizeService::weeklySchedules(Arr::get($data, 'schedule.settings.weekly_schedules', []), $timezone, 'UTC'),
        ];

        $schedule->value = $scheduleData;
        $schedule->save();

        do_action('fluent_booking/avaibility_schedule_updated', $schedule, $scheduleData);

        return [
            'message'  => __('Schedule has been updated successfully', 'fluent-booking'),
            'schedule' => $schedule
        ];
    }

    public function updateScheduleTitle(Request $request, $scheduleId)
    {
        $title = $request->get('title');

        $schedule = Availability::findOrFail($scheduleId);

        $isTitleExist = AvailabilityService::isTitleAlreadyExist($title, $schedule->object_id, $schedule->key);

        if ($isTitleExist) {
            /* translators: %s is the existing availability title */
            $message = sprintf(__('%s is already exist', 'fluent-booking'), $title);
            return $this->sendError([
                'message' => $message,
            ], 422);
        }

        $schedule->key = $title;
        $schedule->save();

        return [
            'message' => __('Schedule title has been updated successfully', 'fluent-booking'),
            'title'   => $schedule->key
        ];
    }

    public function updateDefaultStatus(Request $request, $scheduleId)
    {
        $schedule = Availability::findOrFail($scheduleId);

        $updatedSettings = [
            'default'          => true,
            'timezone'         => Arr::get($schedule, 'value.timezone', 'UTC'),
            'date_overrides'   => Arr::get($schedule, 'value.data_overrides', []),
            'weekly_schedules' => Arr::get($schedule, 'value.weekly_schedules'),
        ];

        $schedule->value = $updatedSettings;
        $schedule->save();

        AvailabilityService::updateOtherDefaultStatus($schedule, $scheduleId);

        return [
            'message' => __('Status has been updated successfully', 'fluent-booking')
        ];
    }

    public function deleteSchedule(Request $request, $scheduleId)
    {
        $schedule = Availability::findOrFail($scheduleId);

        if (!$schedule) {
            return;
        }

        $isDefault = Arr::isTrue($schedule, 'value.default');

        if ($isDefault) {
            $calendar = Calendar::where('user_id', $schedule->object_id)->first();
            if ($calendar) {
                return $this->sendError([
                    'message' => __('Default Schedule can not be deleted', 'fluent-booking')
                ], 422);
            }
        }

        $usageCount = AvailabilityService::getAvailabilityUsageCount($scheduleId);

        if ($usageCount) {
            /* translators: Number of events dependent on the schedule */
            $message = sprintf(__('Can\'t delete: %s events depend on this schedule', 'fluent-booking'), $usageCount);
            return $this->sendError([
                'message' => $message
            ], 422);
        }

        do_action('fluent_booking/before_delete_availability_schedule', $schedule);

        $schedule->delete();

        do_action('fluent_booking/after_delete_availability_schedule', $scheduleId);

        return [
            'message' => __('Schedule Availability has been deleted successfully', 'fluent-booking')
        ];
    }
}
