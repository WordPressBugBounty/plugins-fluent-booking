<?php

namespace FluentBooking\Database;

use FluentBooking\Database\Migrations\BookingActivityMigrator;
use FluentBooking\Database\Migrations\BookingMigrator;
use FluentBooking\Database\Migrations\BookingMetaMigrator;
use FluentBooking\Database\Migrations\BookingHostMigrator;
use FluentBooking\Database\Migrations\CalendarMigrator;
use FluentBooking\Database\Migrations\CalendarSlotsMigrator;
use FluentBooking\Database\Migrations\MetaMigrator;

class DBMigrator
{
    public static function run($network_wide = false)
    {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        if (is_multisite() && $network_wide) {
            global $wpdb;
            $blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            foreach ($blog_ids as $blog_id) {
                switch_to_blog($blog_id);
                static::migrate();
                restore_current_blog();
            }
        } else {
            static::migrate();
        }
    }

    private static function migrate()
    {
        CalendarMigrator::migrate();
        CalendarSlotsMigrator::migrate();
        BookingMigrator::migrate();
        BookingMetaMigrator::migrate();
        BookingHostMigrator::migrate();
        MetaMigrator::migrate();
        BookingActivityMigrator::migrate();
    }
}
