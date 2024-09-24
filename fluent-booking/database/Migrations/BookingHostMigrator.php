<?php

namespace FluentBooking\Database\Migrations;

class BookingHostMigrator
{
    static $tableName = 'fcal_booking_hosts';

    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . static::$tableName;

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $sql = "CREATE TABLE $table (
                `id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `booking_id` BIGINT(20) UNSIGNED NOT NULL,
                `user_id` BIGINT(20) UNSIGNED NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'confirmed',
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `fcal_bu_booking_id` (`booking_id`),
                KEY `fcal_bu_user_id` (`user_id`),
                KEY `fcal_bu_status` (`status`)
            ) $charsetCollate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

            dbDelta($sql);
        }
    }
}
