<?php

namespace FluentBooking\Database\Migrations;

class BookingMigrator
{
    static $tableName = 'fcal_bookings';

    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . static::$tableName;

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $sql = "CREATE TABLE $table (
                `id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `hash` VARCHAR(192) NULL,
                `calendar_id` BIGINT(20) UNSIGNED NOT NULL,
                `event_id` BIGINT(20) UNSIGNED NOT NULL,
                `group_id` BIGINT(20) UNSIGNED NULL,
                `fcrm_id` BIGINT(20) UNSIGNED NULL,
                `parent_id` BIGINT(20) UNSIGNED NULL,
                `host_user_id` BIGINT(20) UNSIGNED NULL,
                `person_user_id` BIGINT(20) UNSIGNED NULL,
                `person_contact_id` BIGINT(20) UNSIGNED NULL,
                `person_time_zone` VARCHAR(100) NULL,
                `start_time` TIMESTAMP NULL,
                `end_time` TIMESTAMP NULL,
                `slot_minutes` INT(11) UNSIGNED NOT NULL,
                `first_name` VARCHAR(192) NULL,
                `last_name` VARCHAR(192) NULL,
                `email` VARCHAR(192) NULL,
                `message` TEXT NULL,
                `internal_note` TEXT NULL,
                `phone` VARCHAR(100) NULL,
                `country` VARCHAR(100) NULL,
                `ip_address` VARCHAR(192) NULL,
                `browser` VARCHAR(192) NULL,
                `device` VARCHAR(192) NULL,
                `other_info` LONGTEXT NULL,
                `location_details` LONGTEXT NULL,
                `cancelled_by` BIGINT(20) UNSIGNED NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'scheduled',
                `source` VARCHAR(20) NOT NULL DEFAULT 'web',
                `booking_type` VARCHAR(20) NOT NULL DEFAULT 'scheduling',
                `event_type` VARCHAR(20) NOT NULL DEFAULT 'single', /* singe|group */
                `payment_status` VARCHAR(20) NULL, /* pending|paid */
                `payment_method` VARCHAR(20) NULL,
                `source_url` TEXT NULL,
                `source_id` BIGINT(20) UNSIGNED NULL,
                `utm_source` VARCHAR(192) NULL DEFAULT '',
                `utm_medium` VARCHAR(192) NULL DEFAULT '',
                `utm_campaign` VARCHAR(192) NULL DEFAULT '',
                `utm_term` VARCHAR(192) NULL DEFAULT '',
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `fcal_b_parent_id` (`parent_id`),
                KEY `fcal_b_hash` (`hash`),
                KEY `fcal_b_calendar_id` (`calendar_id`),
                KEY `fcal_b_fcrm_id` (`fcrm_id`),
                KEY `fcal_b_event_id` (`event_id`),
                KEY `fcal_b_booking_type` (`booking_type`),
                KEY `fcal_b_start_time` (`start_time`)
            ) $charsetCollate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

            dbDelta($sql);
        }
    }
}
