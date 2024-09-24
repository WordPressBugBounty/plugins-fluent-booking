<?php

namespace FluentBooking\App\Services\Integrations\FluentBoards;

use FluentBooking\App\App;
use FluentBoards\App\Models\Label;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\NotificationService;
use FluentBoards\App\Services\TaskService;
use FluentBooking\Framework\Support\Arr;
use FluentBooking\App\Http\Controllers\IntegrationManagerController;

class Bootstrap extends IntegrationManagerController
{
    public $hasGlobalMenu = false;

    public $disableGlobalSettings = 'yes';

    public function __construct()
    {
        parent::__construct(
            __('FluentBoards', 'fluent-booking'),
            'fluentboards',
            'fluent_booking_fluentboards_configurations',
            'fluentboards_feeds',
            10
        );

        $this->logo = App::getInstance('url.assets') . 'images/fluentboards.png';

        $this->description = __('Connect FluentBoards with Fluent Booking and create tasks with booking fields.', 'fluent-booking');

        $this->registerAdminHooks();
    }

    public function pushIntegration($integrations, $calendarEventId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => $this->title . ' ' . __('Integration', 'fluent-booking'),
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!', 'fluent-booking'),
            'global_configure_url'  => '#',
            'configure_message'     => __('FluentBoards is not configured yet! Please configure your FluentBoards api first', 'fluent-booking'),
            'configure_button_text' => __('Set FluentBoards', 'fluent-booking'),
        ];

        return $integrations;
    }

    public function isConfigured()
    {
        return true;
    }

    public function isEnabled()
    {
        return true;
    }

    public function getIntegrationDefaults($settings, $calendarEventId)
    {
        return [
            'name'         => '',
            'board_config' => [
                'board_id'   => '',
                'stage_id'   => '',
                'label_ids'  => '',
                'member_ids' => [],
                'priority'   => 'low'
            ],
            'task_title'   => '',
            'author_name'  => '',
            'email'        => '',
            'description'  => '',
            'position'     => 'bottom',
            'due_at_type'  => 'booking',
            'due_at_days'  => 0,
            'enabled'      => true
        ];
    }

    public function getSettingsFields($settings, $calendarEventId)
    {
        $fields = [
            [
                'key'         => 'name',
                'label'       => __('Feed Name', 'fluent-booking'),
                'required'    => true,
                'placeholder' => __('Your Feed Name', 'fluent-booking'),
                'component'   => 'text',
            ],
            [
                'key'            => 'board_config',
                'label'          => __('Fluent Boards Configuration', 'fluent-booking'),
                'required'       => true,
                'component'      => 'chained_select',
                'primary_key'    => 'board_id',
                'fields_options' => [
                    'board_id'   => [],
                    'stage_id'   => [],
                    'label_ids'  => [],
                    'member_ids' => [],
                    'priority'   => []
                ],
                'options_labels' => [
                    'board_id'   => [
                        'label'       => __('Select Board', 'fluent-booking'),
                        'type'        => 'select',
                        'placeholder' => __('Select Board', 'fluent-booking')
                    ],
                    'stage_id'   => [
                        'label'       => __('Select Stage', 'fluent-booking'),
                        'type'        => 'select',
                        'placeholder' => __('Select Stage', 'fluent-booking')
                    ],
                    'label_ids'  => [
                        'label'       => __('Select Labels', 'fluent-booking'),
                        'type'        => 'multi-select',
                        'placeholder' => __('Select Labels', 'fluent-booking')
                    ],
                    'member_ids' => [
                        'label'       => __('Select Assignees', 'fluent-booking'),
                        'type'        => 'multi-select',
                        'placeholder' => __('Select Assignees', 'fluent-booking')
                    ],
                    'priority'   => [
                        'label'       => __('Select Priority', 'fluent-booking'),
                        'type'        => 'select',
                        'placeholder' => __('Priority', 'fluent-booking')
                    ]
                ]
            ],
            [
                'key'         => 'task_title',
                'label'       => __('Task Title', 'fluent-booking'),
                'required'    => true,
                'placeholder' => __('Task Title', 'fluent-booking'),
                'component'   => 'value_text'
            ],
            [
                'key'         => 'description',
                'label'       => __('Description', 'fluent-booking'),
                'required'    => false,
                'placeholder' => __('Describe your task', 'fluent-booking'),
                'component'   => 'wp_editor',
            ],
            [
                'key'         => 'author_name',
                'label'       => __('Submitter Name', 'fluent-booking'),
                'required'    => true,
                'placeholder' => __('Submitter Name', 'fluent-booking'),
                'component'   => 'value_text'
            ],
            [
                'key'         => 'email',
                'label'       => __('Submitter Email', 'fluent-booking'),
                'required'    => true,
                'placeholder' => __('Submitter Email', 'fluent-booking'),
                'component'   => 'value_text'
            ],
            [
                'key'       => 'due_at_type',
                'label'     => __('Due Type', 'fluent-booking'),
                'tips'      => __('Choose “Booking Date” to set the due date relative to when the booking was made or “Meeting Date” to set it relative to the scheduled meeting date.', 'fluent-booking'),
                'component' => 'radio_choice',
                'options'   => [
                    'booking' => __('Booking Date', 'fluent-booking'),
                    'meeting' => __('Meeting Date', 'fluent-booking')
                ]
            ],
            [
                'key'       => 'due_at_days',
                'label'     => __('Due Date', 'fluent-booking'),
                'tips'      => __('Set the due date by entering a number relative to the booking or meeting date. Positive for days after and negative for days before the booking or meeting date.', 'fluent-booking'),
                'component' => 'number'
            ],
            [
                'key'         => 'position',
                'label'       => __('Task Position', 'fluent-booking'),
                'placeholder' => __('Position', 'fluent-booking'),
                'component'   => 'radio_choice',
                'options'     => [
                    'bottom' => __('Bottom', 'fluent-booking'),
                    'top'    => __('Top', 'fluent-booking')
                ]
            ],
            [
                'require_list'   => false,
                'required'       => true,
                'key'            => 'event_trigger',
                'options'        => $this->getEventTriggerOptions(),
                'tips'           => __('Select in which booking stage you want to trigger this feed', 'fluent-booking'),
                'label'          => __('Event Trigger', 'fluent-booking'),
                'component'      => 'checkbox-multiple-text',
                'checkbox_label' => __('Event Trigger For This Feed', 'fluent-booking'),
            ],
            [
                'require_list'   => false,
                'key'            => 'enabled',
                'label'          => __('Status', 'fluent-booking'),
                'component'      => 'checkbox-single',
                'checkbox_label' => __('Enable This feed', 'fluent-booking'),
            ]
        ];

        return [
            'fields'              => $fields,
            'button_require_list' => false,
            'integration_title'   => $this->title,
        ];
    }

    public function getMergeFields($list, $listId, $slotId)
    {
        return [];
    }

    public function getEventTriggerOptions()
    {
        return [
            'after_booking_scheduled'    => __('Booking Confirmed', 'fluent-booking'),
            'booking_schedule_completed' => __('Booking Completed', 'fluent-booking'),
            'booking_schedule_cancelled' => __('Booking Cancelled', 'fluent-booking'),
        ];
    }

    public function getConfigFieldOptions($settings, $calendarEventId)
    {
        $boardId = Arr::get($settings, 'board_config.board_id');

        $data = [
            'board_id'   => $this->getBoards(),
            'stage_id'   => $boardId ? $this->getStages($boardId) : [],
            'label_ids'  => $boardId ? $this->getBoardLabels($boardId) : [],
            'member_ids' => $boardId ? $this->getBoardMembers($boardId) : [],
            'priority'   => $this->getBoardPriorities()
        ];

        $data = apply_filters('fluent_booking/fluent_board_config_field_options', $data, $boardId);

        return $data;
    }

    private function getBoards()
    {
        $boards = Board::whereNull('archived_at')
            ->select('id', 'title')
            ->get();

        $formattedBoards = $boards->mapWithKeys(function ($board) {
            return [$board->id => $board->title];
        })->toArray();

        return $formattedBoards;
    }

    private function getStages($boardId)
    {
        $stages = Stage::where('board_id', $boardId)
            ->whereNull('archived_at')
            ->select('id', 'title')
            ->get();

        $formattedStages = $stages->mapWithKeys(function ($stage) {
            return [$stage->id => $stage->title];
        })->toArray();

        return $formattedStages;
    }

    private function getBoardLabels($boardId)
    {
        $labels = Label::where('board_id', $boardId)
            ->whereNull('archived_at')
            ->orderBy('position', 'asc')
            ->get(['id', 'title', 'slug']);

        $formattedLabels = $labels->mapWithKeys(function ($label) {
            return [$label->id => $label->title ?? $label->slug];
        })->toArray();

        return $formattedLabels;
    }

    private function getBoardMembers($boardId)
    {
        $board = Board::with(['users'])->findOrFail($boardId);

        $formattedBoardUsers = $board->users->mapWithKeys(function ($user) {
            return [$user->ID => "{$user->user_login} ({$user->user_email})"];
        })->toArray();

        return $formattedBoardUsers;
    }

    private function getBoardPriorities()
    {
        return [
            'low'    => __('Low', 'fluent-booking'),
            'medium' => __('Medium', 'fluent-booking'),
            'high'   => __('High', 'fluent-booking')
        ];
    }

    private function getLastPositionOfStageTask($boardId, $stageId)
    {
        $lastPosition = Task::query()
            ->where('board_id', $boardId)
            ->where('parent_id', null)
            ->where('stage_id', $stageId)
            ->whereNull('archived_at')
            ->orderBy('position', 'desc')
            ->pluck('position')
            ->first();

        return $lastPosition + 1;
    }

    private function convertDueDate($dueTime, $dueType, $bookingStartTime)
    {
        $timeStamp = strtotime($bookingStartTime);
        $currentTime = current_time('timestamp');

        if ($dueType != 'meeting') {
            $timeStamp = $currentTime;
            $dueTime = max(0, $dueTime);
        }

        $adjustSign = $dueTime < 0 ? '-' : '+';
        $dateAdjustment = $adjustSign . abs($dueTime) . ' day';
        $dueDate = gmdate('Y-m-d H:i:s', strtotime($dateAdjustment, $timeStamp)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        if (strtotime($dueDate) < $currentTime) {
            $dueDate = gmdate('Y-m-d H:i:s', $currentTime); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        }

        $wpTimestamp = current_time('timestamp');
        $utcTimeStamp = time();

        $diff = $wpTimestamp - $utcTimeStamp;

        if (!$diff) {
            return $dueDate;
        }

        return gmdate('Y-m-d H:i:s', strtotime($dueDate) + $diff); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
    }

    public function notify($feed, $booking, $calendarEvent)
    {
        $validData = ['task_title', 'description', 'board_config', 'author_name', 'email', 'position', 'due_at_days', 'due_at_type'];
        $data = Arr::only($feed['processedValues'], $validData);

        $boardId = intval(Arr::get($data, 'board_config.board_id'));
        $stageId = intval(Arr::get($data, 'board_config.stage_id'));
        $priority = sanitize_text_field(Arr::get($data, 'board_config.priority'));
        $assignees = array_map('intval', Arr::get($data, 'board_config.member_ids', []));
        $boardLabels = array_map('intval', Arr::get($data, 'board_config.label_ids', []));
        $taskTitle = sanitize_text_field(Arr::get($data, 'task_title'));
        $description = wp_kses_post(Arr::get($data, 'description'));
        $position = sanitize_text_field(Arr::get($data, 'position'));
        $dueAtDays = intval(Arr::get($data, 'due_at_days'));
        $dueAtType = sanitize_text_field(Arr::get($data, 'due_at_type'));
        $authorName = sanitize_text_field(Arr::get($data, 'author_name'));
        $authorEmail = sanitize_email(Arr::get($data, 'email'));

        if (!$booking->id || !$boardId || !$stageId || !$taskTitle) {
            return false;
        }

        $board = Board::find($boardId);
        if (!$board) {
            return false;
        }

        $data = [
            'title'       => $taskTitle,
            'board_id'    => $boardId,
            'stage_id'    => $stageId,
            'priority'    => $priority,
            'description' => $description,
            'position'    => $this->getLastPositionOfStageTask($boardId, $stageId),
            'due_at'      => $this->convertDueDate($dueAtDays, $dueAtType, $booking->start_time),
            'source'      => 'FluentBooking'
        ];

        $data['started_at'] = $data['due_at'] ? gmdate('Y-m-d H:i:s', strtotime(current_time('mysql'))) : null; // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $existingUser = User::where('user_email', $authorEmail)->first();
        if ($existingUser) {
            $data['created_by'] = $existingUser->ID;
        }

        if (!$existingUser) {
            $data['settings']['author'] = [
                'name'          => $authorName,
                'email'         => $authorEmail,
                'subtask_count' => 0,
                'cover'         => [
                    'backgroundColor' => ''
                ]
            ];
        }

        if (defined('FLUENTCRM')) {
            $crmConatct = \FluentCrm\App\Models\Subscriber::where('email', $authorEmail)->first();
            if ($crmConatct) {
                $data['crm_contact_id'] = $crmConatct->id;
            }
        }

        $task = (new Task())->createTask($data);
        if (!$task) {
            return false;
        }

        do_action('fluent_boards/task_added_from_fluent_booking', $task, $booking, $calendarEvent, $feed);

        if ($position == 'top') {
            $task->moveToNewPosition(1);
        }

        foreach ($assignees as $assignee) {
            $task->addOrRemoveAssignee($assignee);
            $task->load('assignees');
            $task->updated_at = current_time('mysql');
            $task->save();

            $isEmailEnabled = (new NotificationService())->checkIfEmailEnable($assignee, Constant::BOARD_EMAIL_TASK_ASSIGN, $task->board_id);
            if ($isEmailEnabled) {
                (new TaskService())->sendMailAfterTaskModify('add_assignee', $assignee, $task->id);
            }

            do_action('fluent_boards/task_assignee_changed', $task, $assignee, 'added');
        }

        foreach ($boardLabels as $label) {
            $task->labels()->syncWithoutDetaching([$label => ['object_type' => Constant::OBJECT_TYPE_TASK_LABEL]]);
        }

        $taskUrl = admin_url("admin.php?page=fluent-boards#/boards/$boardId/tasks/{$task->id}");

        $this->addLog(
            $feed['settings']['name'],
            sprintf(__('Task has been created in FluentBoards. You can %s to view the task.', 'fluent-booking'), '<a target="_blank" href="' . $taskUrl . '">' . __('click here', 'fluent-booking') . '</a>'),
            $booking->id,
            'success'
        );
        return true;
    }
}
