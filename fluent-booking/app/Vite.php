<?php

namespace FluentBooking\App;

use FluentBooking\App\Services\Helper;

class Vite
{
    protected static $entries = [
        'admin_app'         => [
            'src'    => 'resources/admin/app.js',
            'build'  => 'admin/app.js',
            'module' => false,
        ],
        'global_admin'      => [
            'src'    => 'resources/admin/global_admin.js',
            'build'  => 'admin/global_admin.js',
            'module' => false,
        ],
        'fb_index'          => [
            'src'    => 'resources/Blocks/fluent-booking-index.js',
            'build'  => 'admin/fluent-booking-index.js',
            'module' => false,
        ],
        'fb_team'           => [
            'src'    => 'resources/Blocks/TeamManagement/fluent-booking-team-management-index.jsx',
            'build'  => 'admin/fluent-booking-team-management-index.js',
            'module' => false,
        ],
        'fb_calendar'       => [
            'src'    => 'resources/Blocks/CalendarManagement/fluent-booking-calendar-management-index.jsx',
            'build'  => 'admin/fluent-booking-calendar-management-index.js',
            'module' => false,
        ],
        'fb_booking'        => [
            'src'    => 'resources/Blocks/BookingManagement/fluent-booking-booking-management-index.jsx',
            'build'  => 'admin/fluent-booking-booking-management-index.js',
            'module' => false,
        ],
        'public_app'        => [
            'src'    => 'resources/public/app.js',
            'build'  => 'public/js/app.js',
            'module' => false,
        ],
        'widget'            => [
            'src'    => 'resources/public/widget.js',
            'build'  => 'public/js/widget.js',
            'module' => false,
        ],
        'ff_public'         => [
            'src'    => 'resources/public/fluentform.js',
            'build'  => 'public/js/fluentform.js',
            'module' => false,
        ],
        'ff_conversational' => [
            'src'    => 'resources/public/fluentform-conversational.js',
            'build'  => 'public/js/fluentform-conversational.js',
            'module' => false,
        ],
        'phone_field'       => [
            'src'    => 'resources/public/ExtendedPhone/phone-field.js',
            'build'  => 'public/js/phone-field.js',
            'module' => false,
        ],
        'manage_meeting'    => [
            'src'    => 'resources/public/public-manage-meeting.js',
            'build'  => 'public/js/public-manage-meeting.js',
            'module' => false,
        ],
        'team_app'          => [
            'src'    => 'resources/public/Team/team_app.js',
            'build'  => 'public/js/team_app.js',
            'module' => false,
        ],
        'calendar_app'      => [
            'src'    => 'resources/public/Team/calendar_app.js',
            'build'  => 'public/js/calendar_app.js',
            'module' => false,
        ],
        'bookings'          => [
            'src'    => 'resources/public/Booking/bookings.js',
            'build'  => 'public/js/bookings.js',
            'module' => false,
        ],
    ];

    protected static $styles = [
        'fb_index_css'    => [
            'src'   => 'resources/Blocks/fluent-booking-block.scss',
            'build' => 'admin/fluent-booking-index.css',
            'rtl'   => false,
        ],
        'fb_team_css'     => [
            'src'   => 'resources/Blocks/TeamManagement/fcal-team-management-block.scss',
            'build' => 'admin/fluent-booking-team-management-index.css',
            'rtl'   => false,
        ],
        'fb_calendar_css' => [
            'src'   => 'resources/Blocks/CalendarManagement/fcal-calendar-management-block.scss',
            'build' => 'admin/fluent-booking-calendar-management-index.css',
            'rtl'   => false,
        ],
        'fb_booking_css'  => [
            'src'   => 'resources/Blocks/BookingManagement/fcal-booking-management-block.scss',
            'build' => 'admin/fluent-booking-booking-management-index.css',
            'rtl'   => false,
        ],
        'admin_css'       => [
            'src'   => 'resources/scss/admin.scss',
            'build' => 'admin/admin.css',
            'rtl'   => true,
        ],
        'admin_rtl_css'   => [
            'src'   => 'resources/scss/fluentbooking_admin_rtl.scss',
            'build' => 'admin/fluentbooking_admin_rtl.css',
            'rtl'   => false,
        ],
        'saas_css'        => [
            'src'   => 'resources/scss/saas.scss',
            'build' => 'public/saas.css',
            'rtl'   => true,
        ],
        'saas_public_css' => [
            'src'   => 'resources/scss/saas_public.scss',
            'build' => 'public/saas_public.css',
            'rtl'   => true,
        ],
        'saas_admin_css'  => [
            'src'   => 'resources/scss/saas_admin.scss',
            'build' => 'public/saas_admin.css',
            'rtl'   => false,
        ],
    ];

    protected static $moduleHandles = [];

    protected static $clientEnqueued = false;

    protected static function devServer()
    {
        static $origin = null;

        if ($origin !== null) {
            return $origin;
        }

        $origin = '';

        if (defined('FLUENT_BOOKING_DISABLE_VITE_DEV') && FLUENT_BOOKING_DISABLE_VITE_DEV) {
            return $origin;
        }

        $hotFile = FLUENT_BOOKING_DIR . '.vite-hot';

        if (!is_readable($hotFile)) {
            return $origin;
        }

        $contents = trim((string) file_get_contents($hotFile));

        if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $contents)) {
            $origin = $contents;
        }

        return $origin;
    }

    public static function isDev()
    {
        return static::devServer() !== '';
    }

    public static function scriptUrl($entry)
    {
        if (!isset(static::$entries[$entry])) {
            return '';
        }

        if (static::isDev()) {
            return static::devServer() . '/' . static::$entries[$entry]['src'];
        }

        return App::getInstance()['url.assets'] . static::$entries[$entry]['build'];
    }

    public static function styleUrl($entry)
    {
        if (!isset(static::$styles[$entry])) {
            return '';
        }

        $style = static::$styles[$entry];

        if (static::isDev()) {
            return static::devServer() . '/' . $style['src'];
        }

        $file = $style['build'];

        if ($style['rtl'] && Helper::fluentbooking_is_rtl()) {
            $file = preg_replace('/\.css$/', '-rtl.css', $file);
        }

        return App::getInstance()['url.assets'] . $file;
    }

    public static function styleIsModule()
    {
        return static::isDev();
    }

    public static function enqueueScript($handle, $entry, $deps = [], $version = false, $inFooter = true)
    {
        if (!isset(static::$entries[$entry])) {
            return;
        }

        $src = static::scriptUrl($entry);

        if (!$src) {
            return;
        }

        static::bootDevClient();

        wp_enqueue_script($handle, $src, $deps, $version, $inFooter);

        if (static::isDev() || static::$entries[$entry]['module']) {
            static::markAsModule($handle);
        }
    }

    public static function enqueueStyle($handle, $entry, $deps = [], $version = false, $media = 'all')
    {
        if (!isset(static::$styles[$entry])) {
            return;
        }

        $src = static::styleUrl($entry);

        if (static::isDev()) {
            if (!static::$styles[$entry]['src']) {
                return;
            }

            static::bootDevClient();

            $devHandle = $handle . '-vite-style';

            wp_enqueue_script($devHandle, $src, [], $version, true);
            static::markAsModule($devHandle);

            return;
        }

        wp_enqueue_style($handle, $src, $deps, $version, $media);
    }

    protected static function bootDevClient()
    {
        if (!static::isDev() || static::$clientEnqueued) {
            return;
        }

        static::$clientEnqueued = true;

        wp_enqueue_script('fluent-booking-vite-client', static::devServer() . '/@vite/client', [], null, false);
        static::markAsModule('fluent-booking-vite-client');
    }

    protected static function markAsModule($handle)
    {
        if (in_array($handle, static::$moduleHandles, true)) {
            return;
        }

        if (!static::$moduleHandles) {
            add_filter('script_loader_tag', function ($tag, $handle) {
                if (!in_array($handle, self::$moduleHandles, true)) {
                    return $tag;
                }

                $tag = preg_replace('/\stype=([\'"])[^\'"]*\1/', '', $tag);

                return str_replace('<script ', '<script type="module" ', $tag);
            }, 10, 2);
        }

        static::$moduleHandles[] = $handle;
    }
}
