<?php

namespace FluentBooking\Database\Migrations;

class BookingActivityMigrator
{
    /**
     * Migrate the table.
     *
     * @return void
     */
    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix .'fcal_booking_activity';
        $indexPrefix = $wpdb->prefix .'fcal_ba_';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `booking_id` BIGINT UNSIGNED NOT NULL,
                `parent_id` BIGINT UNSIGNED NULL,
                `created_by` BIGINT UNSIGNED NULL,
                `status` VARCHAR(50) DEFAULT 'open',
                `type` VARCHAR(50) DEFAULT 'activity',
                `title` VARCHAR(192) NULL,
                `description` LONGTEXT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                 INDEX `{$indexPrefix}_mt_idx` (`booking_id`),
                 INDEX `{$indexPrefix}_mto_type` (`type`)
            ) $charsetCollate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

            dbDelta($sql);
        }
    }
}
