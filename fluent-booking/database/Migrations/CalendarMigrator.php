<?php

namespace FluentBooking\Database\Migrations;

class CalendarMigrator
{
    static $tableName = 'fcal_calendars';

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
                `account_id` BIGINT(20) UNSIGNED NULL,
                `parent_id` BIGINT(20) UNSIGNED NULL,
                `title` VARCHAR(192) NOT NULL,
                `slug` VARCHAR(192) NOT NULL,
                `media_id` BIGINT(20) UNSIGNED,
                `description` LONGTEXT NULL,
                `settings` LONGTEXT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `type` VARCHAR(20) NOT NULL DEFAULT 'simple',
                `event_type` VARCHAR(20) NOT NULL DEFAULT 'scheduling',
                `account_type` VARCHAR(20) NOT NULL DEFAULT 'free',
                `visibility` VARCHAR(20) NOT NULL DEFAULT 'public',
                `author_timezone` VARCHAR(192) NULL DEFAULT 'UTC',
                `max_book_per_slot` INT(10) UNSIGNED NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `fcal_c_user_id` (`user_id`),
                KEY `fcal_c_hash` (`hash`),
                KEY `fcal_c_status` (`status`),
                KEY `fcal_c_slug` (`slug`),
                KEY `fcal_c_event_type` (`event_type`),
                KEY `fcal_c_type` (`type`)
            ) $charsetCollate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

            dbDelta($sql);
        }
    }
}
