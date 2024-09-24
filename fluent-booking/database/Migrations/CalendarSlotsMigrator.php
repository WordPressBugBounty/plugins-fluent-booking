<?php

namespace FluentBooking\Database\Migrations;

class CalendarSlotsMigrator
{
    static $tableName = 'fcal_calendar_events';

    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . static::$tableName;

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $sql = "CREATE TABLE $table (
                `id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `hash` VARCHAR(192) NULL,
                `user_id` BIGINT(20) UNSIGNED NOT NULL,
                `calendar_id` BIGINT(20) UNSIGNED NOT NULL,
                `duration` INT(11) UNSIGNED NOT NULL,
                `title` VARCHAR(192) NOT NULL,
                `slug` VARCHAR(192) NOT NULL,
                `media_id` BIGINT(20) UNSIGNED,
                `description` LONGTEXT NULL,
                `settings` LONGTEXT NULL,
                `availability_type` VARCHAR(192) DEFAULT 'custom',
                `availability_id` BIGINT(20) UNSIGNED,
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `type` VARCHAR(20) NOT NULL DEFAULT 'free',
                `color_schema` VARCHAR(100) NOT NULL DEFAULT 'default',
                `location_type` VARCHAR(100) NOT NULL DEFAULT '',
                `location_heading` TEXT NULL,
                `location_settings` LONGTEXT NULL,
                `event_type` VARCHAR(20) NOT NULL DEFAULT 'single',
                `is_display_spots` BOOLEAN NOT NULL DEFAULT 0,
                `max_book_per_slot` INT(10) UNSIGNED NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `fcal_cs_user_id` (`user_id`),
                KEY `fcal_cs_hash` (`hash`),
                KEY `fcal_cs_status` (`status`),
                KEY `fcal_cs_slug` (`slug`),
                KEY `fcal_cs_type` (`type`)
            ) $charsetCollate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

            dbDelta($sql);
        }
    }
}
