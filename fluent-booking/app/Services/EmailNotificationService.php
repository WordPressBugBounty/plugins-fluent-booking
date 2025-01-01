<?php

namespace FluentBooking\App\Services;

use FluentBooking\App\App;
use FluentBooking\App\Models\Booking;
use FluentBooking\App\Services\Libs\Emogrifier\Emogrifier;
use FluentBooking\Framework\Support\Arr;

class EmailNotificationService
{
    /**
     * @param \FluentBooking\App\Models\Booking $booking
     * @param $email
     * @param $emailTo
     * @param $actionType
     * @param bool $resending
     * @return bool|mixed
     */
    public static function emailOnBooked(Booking $booking, $email, $emailTo, $actionType = 'scheduled', $resending = false)
    {
        $emailSubject = EditorShortCodeParser::parse($email['subject'], $booking);
        $emailBody = EditorShortCodeParser::parse($email['body'], $booking);

        $globalSettings = Helper::getGlobalSettings();
        $useHostName = Arr::get($globalSettings, 'emailing.use_host_name', 'no');
        $useHostEmailOnReply = Arr::get($globalSettings, 'emailing.use_host_email_on_reply', 'no');
        $attachIcsFile = Arr::get($globalSettings, 'emailing.attach_ics_on_confirmation', 'no');
        $settingsFromName = Arr::get($globalSettings, 'emailing.from_name', '');
        $settingsFromEmail = Arr::get($globalSettings, 'emailing.from_email', '');
        $settingsReplyToName = Arr::get($globalSettings, 'emailing.reply_to_name', '');
        $settingsReplyToEmail = Arr::get($globalSettings, 'emailing.reply_to_email', '');

        $attachments = [];
        if ($attachIcsFile == 'yes' && $actionType == 'scheduled' && !$resending) {
            $attachments = self::prepareAttachments($booking);
        }

        $guestAddress = self::getGuestAddress($booking);

        $authors = $booking->getHostsDetails(false);

        if ($emailTo == 'guest') {
            $authors = [$authors[0]];
        }

        $to = [];
        $from = '';
        $replyTo = '';
        $result = false;

        foreach ($authors as $author) {
            $hostName = $author['name'] ?? '';
            $hostAddress = $author['email'];

            $settingsFromEmail = $settingsFromEmail ?: $author['email'];
            $settingsReplyToEmail = $settingsReplyToEmail ?: $author['email'];

            if ('guest' == $emailTo) {
                $to[] = $guestAddress;
                $replyName = $useHostName == 'yes' ? $hostName : $settingsReplyToName;
                $fromName = $useHostName == 'yes' ? $hostName : $settingsFromName;

                $replyToEmail = $useHostEmailOnReply == 'yes' ? $author['email'] : $settingsReplyToEmail;
                $replyFromEmail = $useHostEmailOnReply == 'yes' ? $replyToEmail : $settingsFromEmail;

                $replyTo = sprintf('%1s <%2s>', $replyName, $replyToEmail);
                $from = sprintf('%1s <%2s>', $fromName, $replyFromEmail);
            } else {
                $to[] = $hostName ? sprintf('%1$s <%2$s>', $hostName, $hostAddress) : $hostAddress;
            }
        }

        $to = implode(', ', array_unique($to));

        if ('guest' != $emailTo) {
            $replyTo = sprintf('%1s <%2s>', $settingsReplyToName, $settingsReplyToEmail ?: $settingsFromEmail);
            $from = sprintf('%1s <%2s>', $settingsFromName, $settingsFromEmail);
        }

        $headers = self::prepareEmailHeaders($replyTo, $from, $email);

        $body = (string)App::make('view')->make('emails.template', [
            'email_body'   => $emailBody,
            'email_footer' => self::getGlobalEmailFooter(),
        ]);

        $emogrifier = new Emogrifier($body);
        $emogrifier->disableInvisibleNodeRemoval();
        $body = (string)$emogrifier->emogrify();

        $result = Mailer::send($to, $emailSubject, $body, $headers, $attachments);

        foreach ($attachments as $attachment) {
            wp_delete_file($attachment);
        }

        $status = $result ? 'sent' : 'sending failed';

        $title = sprintf(__('Booking %s email %s to %s', 'fluent-booking'), $actionType, $status, $emailTo);

        if ($result) {
            self::addLog($title, $title, $booking->id);
        } else {
            self::addLog($title, $title, $booking->id, 'error');
        }

        return $result;
    }

    /**
     * @param \FluentBooking\App\Models\Booking $booking
     * @param $email
     * @param $emailTo
     * @return bool|mixed
     */
    public static function reminderEmail(Booking $booking, $email, $emailTo)
    {
        $globalSettings = Helper::getGlobalSettings();
        $useHostName = Arr::get($globalSettings, 'emailing.use_host_name', 'no');
        $useHostEmailOnReply = Arr::get($globalSettings, 'emailing.use_host_email_on_reply', 'no');
        $settingsFromName = Arr::get($globalSettings, 'emailing.from_name', '');
        $settingsFromEmail = Arr::get($globalSettings, 'emailing.from_email', '');
        $settingsReplyToName = Arr::get($globalSettings, 'emailing.reply_to_name', '');
        $settingsReplyToEmail = Arr::get($globalSettings, 'emailing.reply_to_email', '');

        $guestAddress = self::getGuestAddress($booking);

        $authors = $booking->getHostsDetails(false);

        if ($emailTo == 'guest') {
            $authors = [$authors[0]];
        }

        $to = [];
        $from = '';
        $replyTo = '';
        $result = false;

        foreach ($authors as $author) {
            $hostAddress = $author['email'];
            $hostName = $author['name'] ?? '';

            $settingsFromEmail = $settingsFromEmail ?: $author['email'];
            $settingsReplyToEmail = $settingsReplyToEmail ?: $author['email'];

            if ('guest' == $emailTo) {
                $to[] = $guestAddress;
                $replyName = $useHostName == 'yes' ? $hostName : $settingsReplyToName;
                $fromName = $useHostName == 'yes' ? $hostName : $settingsFromName;

                $replyToEmail = $useHostEmailOnReply == 'yes' ? $author['email'] : $settingsReplyToEmail;
                $fromEmail = $useHostEmailOnReply == 'yes' ? $replyToEmail : $settingsFromEmail;

                $replyTo = sprintf('%1$s <%2$s>', $replyName, $replyToEmail);
                $from = sprintf('%1$s <%2$s>', $fromName, $fromEmail);
            } else {
                $to[] = $hostName ? sprintf('%1$s <%2$s>', $hostName, $hostAddress) : $hostAddress;
            }
        }

        $to = implode(', ', array_unique($to));

        if ('guest' != $emailTo) {
            $replyTo = sprintf('%1s <%2s>', $settingsReplyToName, $settingsReplyToEmail ?: $settingsFromEmail);
            $from = sprintf('%1s <%2s>', $settingsFromName, $settingsFromEmail);
        }

        $headers = self::prepareEmailHeaders($replyTo, $from, $email);

        $subject = EditorShortCodeParser::parse($email['subject'], $booking);
        $html = EditorShortCodeParser::parse($email['body'], $booking);

        $body = (string)App::make('view')->make('emails.template', [
            'email_body'   => $html,
            'email_footer' => self::getGlobalEmailFooter()
        ]);

        $emogrifier = new Emogrifier($body);
        $emogrifier->disableInvisibleNodeRemoval();
        $body = (string)$emogrifier->emogrify();

        $result = Mailer::send($to, $subject, $body, $headers);

        if (!$result) {
            return false;
        }

        $title = __('Reminder Email Sent', 'fluent-booking');
        $description = sprintf(__('Reminder email sent to %s.', 'fluent-booking'), $emailTo);
        self::addLog($title, $description, $booking->id);

        return true;
    }

    /**
     * @param \FluentBooking\App\Models\Booking $booking
     * @param $email
     * @param $emailTo
     * @param $actionType
     * @return bool|mixed
     */
    public static function bookingCancelOrRejectEmail(Booking $booking, $email, $emailTo, $actionType = 'cancel')
    {
        $globalSettings = Helper::getGlobalSettings();
        $useHostName = Arr::get($globalSettings, 'emailing.use_host_name', 'no');
        $useHostEmailOnReply = Arr::get($globalSettings, 'emailing.use_host_email_on_reply', 'no');
        $settingsFromName = Arr::get($globalSettings, 'emailing.from_name', '');
        $settingsFromEmail = Arr::get($globalSettings, 'emailing.from_email', '');
        $settingsReplyToName = Arr::get($globalSettings, 'emailing.reply_to_name', '');
        $settingsReplyToEmail = Arr::get($globalSettings, 'emailing.reply_to_email', '');

        $guestAddress = self::getGuestAddress($booking);

        $authors = $booking->getHostsDetails(false);

        if ($emailTo == 'guest') {
            $authors = [$authors[0]];
        }

        $to = [];
        $from = '';
        $replyTo = '';
        $result = false;

        foreach ($authors as $author) {
            $hostAddress = $author['email'];
            $hostName = $author['name'] ?? '';
            
            $settingsFromEmail = $settingsFromEmail ?: $author['email'];
            $settingsReplyToEmail = $settingsReplyToEmail ?: $author['email'];

            if ('guest' == $emailTo) {
                $to[] = $guestAddress;
                $replyName = $useHostName == 'yes' ? $hostName : $settingsReplyToName;
                $fromName = $useHostName == 'yes' ? $hostName : $settingsFromName;

                $replyToEmail = $useHostEmailOnReply == 'yes' ? $author['email'] : $settingsReplyToEmail;
                $replyFromEmail = $useHostEmailOnReply == 'yes' ? $replyToEmail : $settingsFromEmail;

                $replyTo = sprintf('%1s <%2s>', $replyName, $replyToEmail);
                $from = sprintf('%1s <%2s>', $fromName, $replyFromEmail);
            } else {
                $to[] = $hostName ? sprintf('%1s <%2s>', $hostName, $hostAddress) : $hostAddress;
            }
        }

        $to = implode(', ', array_unique($to));

        if ('guest' != $emailTo) {
            $replyTo = sprintf('%1s <%2s>', $settingsReplyToName, $settingsReplyToEmail ?: $settingsFromEmail);
            $from = sprintf('%1s <%2s>', $settingsFromName, $settingsFromEmail);
        }

        $headers = self::prepareEmailHeaders($replyTo, $from, $email);

        $subject = EditorShortCodeParser::parse($email['subject'], $booking);
        $html = EditorShortCodeParser::parse($email['body'], $booking);

        $body = (string)App::make('view')->make('emails.template', [
            'email_body'   => $html,
            'email_footer' => self::getGlobalEmailFooter()
        ]);

        $emogrifier = new Emogrifier($body);
        $emogrifier->disableInvisibleNodeRemoval();
        $body = (string)$emogrifier->emogrify();

        $result = Mailer::send($to, $subject, $body, $headers);

        $actionType = $actionType == 'reject' ? __('Rejection', 'fluent-booking') : __('Cancellation', 'fluent-booking');

        $status = $result ? 'sent' : 'sending failed';

        $title = sprintf(__('%s email %s to %s', 'fluent-booking'), $actionType, $status, $emailTo);

        if ($result) {
            self::addLog($title, $title, $booking->id);
        } else {
            self::addLog($title, $title, $booking->id, 'error');
        }

        return $result;
    }

    /**
     * @param \FluentBooking\App\Models\Booking $booking
     * @param $email
     * @param $emailTo
     * @return bool|mixed
     */
    public static function bookingRescheduledEmail(Booking $booking, $email, $emailTo)
    {
        $globalSettings = Helper::getGlobalSettings();
        $useHostName = Arr::get($globalSettings, 'emailing.use_host_name', 'no');
        $useHostEmailOnReply = Arr::get($globalSettings, 'emailing.use_host_email_on_reply', 'no');
        $attachIcsFile = Arr::get($globalSettings, 'emailing.attach_ics_on_confirmation', 'no');
        $settingsFromName = Arr::get($globalSettings, 'emailing.from_name', '');
        $settingsFromEmail = Arr::get($globalSettings, 'emailing.from_email', '');
        $settingsReplyToName = Arr::get($globalSettings, 'emailing.reply_to_name', '');
        $settingsReplyToEmail = Arr::get($globalSettings, 'emailing.reply_to_email', '');

        $attachments = [];
        if ($attachIcsFile == 'yes') {
            $attachments = self::prepareAttachments($booking);
        }

        $guestAddress = self::getGuestAddress($booking);

        $authors = $booking->getHostsDetails(false);

        if ($emailTo == 'guest') {
            $authors = [$authors[0]];
        }

        $to = [];
        $from = '';
        $replyTo = '';
        $result = false;

        foreach ($authors as $author) {
            $hostAddress = $author['email'];
            $hostName = $author['name'] ?? '';

            $settingsFromEmail = $settingsFromEmail ?: $author['email'];
            $settingsReplyToEmail = $settingsReplyToEmail ?: $author['email'];

            if ('guest' == $emailTo) {
                $to[] = $guestAddress;
                $replyName = $useHostName == 'yes' ? $hostName : $settingsReplyToName;
                $fromName = $useHostName == 'yes' ? $hostName : $settingsFromName;
    
                $replyToEmail = $useHostEmailOnReply == 'yes' ? $author['email'] : $settingsReplyToEmail;
                $replyFromEmail = $useHostEmailOnReply == 'yes' ? $replyToEmail : $settingsFromEmail;
    
                $replyTo = sprintf('%1s <%2s>', $replyName, $replyToEmail);
                $from = sprintf('%1s <%2s>', $fromName, $replyFromEmail);
            } else {
                $to[] = $hostName ? sprintf('%1$s <%2$s>', $hostName, $hostAddress) : $hostAddress;
            }
        }

        $to = implode(', ', array_unique($to));

        if ('guest' != $emailTo) {
            $replyTo = sprintf('%1s <%2s>', $settingsReplyToName, $settingsReplyToEmail ?: $settingsFromEmail);
            $from = sprintf('%1s <%2s>', $settingsFromName, $settingsFromEmail);
        }

        $headers = self::prepareEmailHeaders($replyTo, $from, $email);

        $subject = EditorShortCodeParser::parse($email['subject'], $booking);
        $html = EditorShortCodeParser::parse($email['body'], $booking);

        $body = (string)App::make('view')->make('emails.template', [
            'email_body'   => $html,
            'email_footer' => self::getGlobalEmailFooter()
        ]);

        $emogrifier = new Emogrifier($body);
        $emogrifier->disableInvisibleNodeRemoval();
        $body = (string)$emogrifier->emogrify();

        $result = Mailer::send($to, $subject, $body, $headers, $attachments);

        if ($attachments) {
            wp_delete_file($attachments[0]);
        }

        $status = $result ? 'sent' : 'sending failed';

        $title = sprintf(__('Rescheduled booking email %s to %s', 'fluent-booking'), $status, $emailTo);

        $description = sprintf(__('Rescheduling email %s to %s', 'fluent-booking'), $status, $emailTo);

        if ($result) {
            self::addLog($title, $description, $booking->id);
        } else {
            self::addLog($title, $description, $booking->id, 'error');
        }

        return $result;
    }

    private static function getGuestAddress($booking)
    {
        $guestAddress = $booking->email;
        if ($booking->first_name && $booking->last_name) {
            $guestAddress = sprintf('%1s %2s <%3s>', $booking->first_name, $booking->last_name, $booking->email);
        } else if ($booking->first_name) {
            $guestAddress = sprintf('%1s <%2s>', $booking->first_name, $booking->email);
        }

        if ($additionalGuests = $booking->getAdditionalGuests()) {
            $guestAddress .= ', ' . implode(', ', $additionalGuests);
        }
        return $guestAddress;
    }

    private static function prepareEmailHeaders($replyTo, $from, $email)
    {
        $headers = [
            'Reply-To: ' . $replyTo
        ];

        if ($from) {
            $headers[] = 'From: ' . $from;
        }

        if (isset($email['recipients'])) {
            $headers[] = 'bcc: ' . implode(', ', $email['recipients']);
        }

        return $headers;
    }

    private static function prepareAttachments($booking)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        if (!WP_Filesystem()) {
            return [];
        }

        $icsContent = BookingService::generateBookingICS($booking);
    
        $filePath = wp_tempnam(null, 'event') . '.ics';
        
        global $wp_filesystem;
        $wp_filesystem->put_contents($filePath, $icsContent);

        return [$filePath];
    }

    protected static function addLog($title, $description, $bookingId, $type = 'activity')
    {
        do_action('fluent_booking/log_booking_note', [
            'title'       => $title,
            'description' => $description,
            'booking_id'  => $bookingId,
            'type'        => $type,
        ]);
    }

    public static function getGlobalEmailFooter()
    {
        $globalSettings = Helper::getGlobalSettings();

        return Arr::get($globalSettings, 'emailing.email_footer', '');
    }

}
