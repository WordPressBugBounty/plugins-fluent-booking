<?php

namespace FluentBooking\App\Services\Integrations\FluentCRM;

use FluentBooking\App\Models\Booking;
use FluentBooking\Framework\Support\Arr;
use FluentBooking\App\Services\DateTimeHelper;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Html\TableBuilder;

class FluentCrmInit
{
    public function __construct()
    {
        $this->registerHooks();
        $this->registerIntegrations();

        // Contextual SmartCodes
        (new CrmSmartCode())->register();

        add_filter('fluent_crm_asset_listed_slugs', function ($lists) {
            $lists[] = 'fluent-booking';
            return $lists;
        });
    }

    public function registerIntegrations()
    {
        $this->addAutomations();
    }

    public function registerHooks()
    {
        add_filter('fluentcrm_profile_sections', [$this, 'addProfileSection'], 10, 1);
        add_filter('fluencrm_profile_section_fluent_booking', [$this, 'getProfileSection'], 10, 2);
    }

    public function addAutomations()
    {
        new NewBookingTrigger();
        new CancelBookingTrigger();
        new BookingCompletedTrigger();
        new BookingRescheduledTrigger();
    }

    public function addProfileSection($sections)
    {
        $sections['booking'] = [
            'name'    => 'fluentcrm_profile_extended',
            'title'   => __('Bookings', 'fluent-booking'),
            'handler' => 'route',
            'query'   => [
                'handler' => 'fluent_booking'
            ],
        ];

        return $sections;
    }

    public function getProfileSection($sections, Subscriber $contact)
    {
        if (!current_user_can('fcrm_read_contacts') && !current_user_can('manage_options')) {
            return $sections;
        }

        $sections['heading'] = __('Bookings (Fluent Booking)', 'fluent-booking');

        $limit = (int) apply_filters('fluent_booking/crm_meetings_limit', 20);
        $limit = max(1, $limit);

        $email = strtolower(trim((string) $contact->email));

        $base = Booking::where('email', $email);

        $total = (clone $base)->count();

        $meetings = (clone $base)
            ->with(['slot', 'calendar'])
            ->orderBy('start_time', 'DESC')
            ->limit($limit)
            ->get();

        $rows = [];
        $hostCache = [];
        foreach ($meetings as $meeting) {
            if (!$meeting->calendar || !$meeting->slot) {
                continue;
            }
            $cid = $meeting->calendar->id;
            if (!isset($hostCache[$cid])) {
                $hostCache[$cid] = $meeting->calendar->getAuthorProfile();
            }

            $rows[] = apply_filters('fluent_booking/crm_meeting_data', $this->mapMeetingRow($meeting, $hostCache[$cid]), $meeting);
        }

        $response = apply_filters('fluent_booking/crm_meetings_response', [
            'total'          => $total,
            'data'           => $rows,
            'columns_config' => [
                'id'         => ['label' => __('ID', 'fluent-booking'), 'width' => '100px'],
                'title'      => ['label' => __('Event', 'fluent-booking')],
                'status'     => ['label' => __('Status', 'fluent-booking'), 'width' => '150px'],
                'meeting_at' => ['label' => __('Meeting At', 'fluent-booking'), 'width' => '200px'],
                'action'     => ['label' => __('Action', 'fluent-booking'), 'width' => '100px'],
            ],
        ], $meetings, $contact);

        if (empty($response['data'])) {
            $sections['content_html'] = '<p style="padding:0 20px;">' . esc_html__('No scheduled meetings found for this contact.', 'fluent-booking') . '</p>';
            return $sections;
        }

        $header = [];
        foreach ($response['columns_config'] as $key => $col) {
            $header[$key] = Arr::get($col, 'label', ucfirst($key));
        }

        $table = new TableBuilder();
        $table->setHeader($header);
        foreach ($response['data'] as $row) {
            $table->addRow($row);
        }

        $sections['content_html'] = $table->getHtml() . $this->getViewAllLink($contact, $total, $limit);

        return $sections;
    }

    private function mapMeetingRow($meeting, $host)
    {
        $groupRef = $meeting->group_id ?: $meeting->id;

        return [
            'id'         => '#' . $groupRef,
            'title'      => $this->getBookingTitle($meeting, $host),
            'status'     => $meeting->status,
            'meeting_at' => $this->getFormattedTime($meeting),
            'action'     => $this->getActionUrl($meeting),
        ];
    }

    private function getViewAllLink(Subscriber $contact, $total, $limit)
    {
        if ($total <= $limit) {
            return '';
        }

        $url = admin_url('admin.php?page=fluent-booking#/scheduled-events?email=' . rawurlencode($contact->email) . '&period=all&author=all');

        return '<p class="fcal_crm_view_all"><a style="color:#2271b1;padding:0 12px;text-decoration:underline" href="' . esc_url($url) . '">'
            /* translators: %d: total number of meetings */
            . sprintf(esc_html__('View all meetings (%d)', 'fluent-booking'), (int) $total)
            . '</a></p>';
    }

    private function getActionUrl($meeting)
    {
        $url = admin_url('admin.php?page=fluent-booking#/scheduled-events?booking_id=' . $meeting->id);

        return '<a target="_blank" href="' . esc_url($url) . '">' . esc_html__('View', 'fluent-booking') . '</a>';
    }

    private function getFormattedTime($meeting)
    {
        return DateTimeHelper::convertToTimeZone($meeting->start_time, 'utc', $meeting->calendar->author_timezone, 'j M Y, g:i A');
    }

    private function getBookingTitle($meeting, $host = null)
    {
        if ($host === null) {
            $host = $meeting->calendar->getAuthorProfile();
        }

        return sprintf(
            /* translators: 1: meeting title, 2: host name */
            __('%1$s with %2$s', 'fluent-booking'),
            (string) $meeting->slot->title,
            (string) $host['name']
        );
    }
}
