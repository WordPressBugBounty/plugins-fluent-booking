<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\App;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Models\Booking;
use FluentBooking\Framework\Support\Arr;
use FluentBooking\App\Services\Libs\Emogrifier\Emogrifier;

class SummaryReportService
{
    public static function maybeSendSummary()
    {
        $notificationSettings = Helper::getGlobalSettings('administration');

        $currentDay = strtolower(gmdate('D'));

        $status     = Arr::get($notificationSettings, 'summary_notification');
        $frequency  = Arr::get($notificationSettings, 'notification_frequency');
        $adminEmail = Arr::get($notificationSettings, 'admin_email');
        $sendingDay = Arr::get($notificationSettings, 'notification_day');

        if ($status != 'yes' || ($frequency == 'weekly' && $sendingDay != $currentDay)) {
            return;
        }

        $lastSend = get_option('fcom_last_summary_email_send');
        $interval = ($frequency == 'daily') ? DAY_IN_SECONDS : WEEK_IN_SECONDS;
        if ($lastSend && (current_time('timestamp') - strtotime($lastSend)) < $interval) {
            return;
        }

        $reportDays = $frequency == 'daily' ? 1 : 7;

        $reportDateFrom = gmdate('Y-m-d H:i:s', strtotime("-$reportDays days"));

        $totalBooked = Booking::where('created_at', '>=', $reportDateFrom)
            ->where('created_at', '<=', gmdate('Y-m-d H:i:s'))
            ->count();

        $totalCompleted = Booking::where('end_time', '>=', $reportDateFrom)
            ->where('end_time', '<=', gmdate('Y-m-d H:i:s'))
            ->where('status', 'completed')
            ->count();

        if (!$totalBooked && !$totalCompleted) {
            return;
        }

        $data = [
            'days'           => $reportDays,
            'frequency'      => $frequency,
            'totalBooked'    => $totalBooked,
            'totalCompleted' => $totalCompleted,
        ];

        $adminEmail = str_replace('{{wp.admin_email}}', get_option('admin_email'), $adminEmail);
        if(!$adminEmail) {
            return;
        }

        update_option('fcom_last_summary_email_send', current_time('mysql'));

        // translators: %d is replaced with the number of days
        $emailSubject = sprintf(
            esc_html(_n(
                'Email Summary of Your Bookings (Last %d Day)',
                'Email Summary of Your Bookings (Last %d Days)',
                $reportDays,
                'fluent-booking'
            )),
            $reportDays
        );

        $emailBody = (string)App::make('view')->make('emails.summary_report', $data);

        $emogrifier = new Emogrifier($emailBody);
        $emogrifier->disableInvisibleNodeRemoval();
        $emailBbody = (string)$emogrifier->emogrify();

        return Mailer::send($adminEmail, $emailSubject, $emailBbody);
    }
}
