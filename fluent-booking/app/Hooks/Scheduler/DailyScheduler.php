<?php

namespace FluentBooking\App\Hooks\Scheduler;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Modules\MCP\Support\WriteGuard;
use FluentBooking\App\Services\Helper;
use FluentBooking\App\Services\SummaryReportService;
use FluentBooking\Framework\Support\Arr;

class DailyScheduler
{
    public function register()
    {
        add_action('fluent_booking/daily_tasks', [$this, 'handleDailyTasks']);
    }

    public function handleDailyTasks()
    {
        // First and in its own try: MCP guard records live in options, so
        // nothing else expires them, and a failing summary must not block this.
        try {
            WriteGuard::purgeExpired();
        } catch (\Throwable $e) {
            if (defined('FLUENT_BOOKING_DEBUG') && FLUENT_BOOKING_DEBUG) {
                error_log('FluentBooking MCP purge failed: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }

        SummaryReportService::maybeSendSummary();

        // Last, so a slow remote cannot hold up the summary email.
        try {
            $this->maybeSendUsageData();
        } catch (\Throwable $e) {
            if (defined('FLUENT_BOOKING_DEBUG') && FLUENT_BOOKING_DEBUG) {
                error_log('FluentBooking stats sharing failed: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }
    }

    /**
     * Weekly usage report (counts only), sent only after an admin opted in.
     *
     * The maintenance/environment report rides along when due. `_fluent_last_m_run` is
     * shared by the Fluent plugins, so whichever reports first covers the rest for six days.
     */
    private function maybeSendUsageData()
    {
        $optin = (array) Helper::getMeta('option', 0, 'optin');

        /**
         * Whether FluentBooking may send usage data.
         *
         * @since 2.4.1
         *
         * @param bool $allowed True once an admin has opted in.
         */
        if (!apply_filters('fluent_booking/allow_share_stats', Arr::get($optin, 'share_stats') === 'yes')) {
            return;
        }

        $lastSent = (int) Arr::get($optin, 'last_sent_at', 0);
        if ($lastSent && (time() - $lastSent) < 6 * DAY_IN_SECONDS) {
            return;
        }

        $report = [
            'product'         => 'fluent-booking',
            'product_version' => FLUENT_BOOKING_VERSION,
            // home_url(), not site_url(): the same site key licensing records under.
            'site_url'        => home_url(),
            'metrics'         => $this->getUsageMetrics()
        ];

        $lastMaintenance = (int) get_option('_fluent_last_m_run');
        if (!$lastMaintenance || (time() - $lastMaintenance) > 6 * DAY_IN_SECONDS) {
            $report['maintenance'] = $this->getMaintenanceData(Arr::get($optin, 'email', ''));
        }

        /**
         * The usage report sent to WPManageNinja once an admin has opted in.
         *
         * @since 2.4.1
         *
         * @param array $report product, product_version, site_url, metrics and, when due, maintenance
         */
        $report = apply_filters('fluent_booking/share_stats_data', $report);

        $response = wp_remote_post('https://fluentapi.wpmanageninja.com/stats', [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($report),
            'cookies' => []
        ]);

        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return;
        }

        $optin['last_sent_at'] = time();
        Helper::updateMeta('option', 0, 'optin', $optin);

        // Any 2xx counts, even `"maintenance": false`: a block the Worker rejected would be
        // rejected again next week, and it shows in the panel's errors.
        if (isset($report['maintenance'])) {
            update_option('_fluent_last_m_run', time());
        }
    }

    /**
     * Counts only, never a person. Keys are snake_case with the window in the name.
     */
    private function getUsageMetrics()
    {
        $metrics = [
            'calendars'    => Calendar::count(),
            'events'       => CalendarSlot::count(),
            'bookings'     => Booking::count(),
            'bookings_30d' => Booking::where('created_at', '>=', gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS))->count(),
            'has_pro'      => defined('FLUENT_BOOKING_PRO_DIR_FILE')
        ];

        $eventTypes = CalendarSlot::groupBy('event_type')
            ->selectRaw('event_type, COUNT(*) as total')
            ->pluck('total', 'event_type')
            ->toArray();

        foreach ($eventTypes as $type => $total) {
            $type = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $type)), '_');
            if ($type) {
                $metrics['events_' . $type] = (int) $total;
            }
        }

        return $metrics;
    }

    /**
     * The same fields FluentCRM sends to /plugin-maintenance. The email is only the one the admin
     * gave when opting in to emails; the stats consent alone never sends an address.
     */
    private function getMaintenanceData($optinEmail)
    {
        global $wp_version;

        return [
            'plugin_version' => FLUENT_BOOKING_VERSION,
            'php_version'    => PHP_VERSION,
            'wp_version'     => $wp_version,
            'plugins'        => (array) get_option('active_plugins'),
            'site_lang'      => get_bloginfo('language'),
            'site_url'       => home_url(),
            'theme'          => wp_get_theme()->get('Name'),
            'site_title'     => get_bloginfo('name'),
            'admin_email'    => (string) $optinEmail
        ];
    }
}
