<?php

namespace FluentBooking\App\Http\Controllers;

use FluentBooking\App\Models\Calendar;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\App\Services\Helper;

class AdminController extends Controller
{
    public function getRemainingHosts(Request $request)
    {
        if (!current_user_can('list_users')) {
            $user = get_user_by('ID', get_current_user_id());
            return [
                'hosts' => [
                    [
                        'is_own' => true,
                        'id'     => $user->ID,
                        'label'  => $user->display_name . ' (' . $user->user_email . ')'
                    ]
                ]
            ];
        }

        $search_term = sanitize_text_field($request->get('search'));

        $queryArgs = [
            'role__not_in' => ['subscriber'],
            'number'       => 50,
            'fields'       => ['ID', 'user_email', 'display_name'],
            'search'       => '*' . $search_term . '*'
        ];

        $metaQueryArgs = [
            'role__not_in' => ['subscriber'],
            'number'       => 50,
            'fields'       => ['ID', 'user_email', 'display_name'],
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key'     => 'first_name',
                    'value'   => $search_term,
                    'compare' => 'LIKE'
                ],
                [
                    'key'     => 'last_name',
                    'value'   => $search_term,
                    'compare' => 'LIKE'
                ]
            ],
        ];

        $metaQueryArgs = apply_filters('fluent_booking/user_search_meta_query_arguments', $metaQueryArgs, $search_term);

        $queryResult = get_users($queryArgs);
        $metaQueryResult = get_users($metaQueryArgs);

        $users = array_unique(array_merge($queryResult, $metaQueryResult), SORT_REGULAR);
        
        $hosts = [];
        $pushedIds = [];
        $calendarUserIds = Calendar::where('type', 'simple')->pluck('user_id')->toArray();
        foreach ($users as $user) {
            $pushedIds[] = $user->ID;
            $hosts[] = [
                'id'       => $user->ID,
                'label'    => $user->display_name . ' (' . $user->user_email . ')',
                'avatar'   => Helper::fluentBookingUserAvatar($user->user_email, $user),
                'disabled' => in_array($user->ID, $calendarUserIds)
            ];
        }

        if ($selectedId = $request->get('selected_id')) {
            if (!in_array($selectedId, $pushedIds)) {
                $user = get_user_by('ID', $selectedId);
                if ($user) {
                    $hosts[] = [
                        'id'       => $user->ID,
                        'label'    => $user->display_name . ' (' . $user->user_email . ')',
                        'avatar'   => Helper::fluentBookingUserAvatar($user->user_email, $user),
                        'disabled' => in_array($user->ID, $calendarUserIds)
                    ];
                }
            }
        }

        return [
            'hosts' => $hosts
        ];
    }

    public function getOtherHosts(Request $request)
    {
        if (!current_user_can('list_users')) {
            return [
                'hosts' => []
            ];
        }

        $currentUserId = get_current_user_id();

        $calendars = Calendar::with(['user'])
            ->where('user_id', '!=', $currentUserId)
            ->where('type', 'simple')
            ->get();

        $allHosts = [];

        foreach ($calendars as $calendar) {
            $userName = __('Deleted User', 'fluent-booking');
            if ($calendar->user) {
                $userName = $calendar->user->full_name;
            }

            if ($currentUserId == $calendar->user_id) {
                $userName = __('My Meetings', 'fluent-booking');
            }

            $allHosts[] = [
                'id'    => (string)$calendar->user_id,
                'label' => $userName
            ];
        }

        return [
            'hosts' => $allHosts
        ];
    }

    public function getAllHosts(Request $request)
    {
        $allHosts = Calendar::getAllHosts();

        return [
            'hosts' => $allHosts
        ];
    }
}
