<?php defined('ABSPATH') or die;
/**
Plugin Name: FluentBooking - Appointment Scheduling & Booking Solution
Description: FluentBooking is the ultimate solution for booking appointments, meetings, webinars, events, sales calls, and more.
Version: 1.8.0
Author: Appointment & Booking Solution Team - WPManageNinja
Author URI: https://fluentbooking.com
Plugin URI: https://fluentbooking.com/pricing/
License: GPLv2 or later
Text Domain: fluent-booking
Domain Path: /language
*/

define('FLUENT_BOOKING_LITE', true);

if (defined('FLUENT_BOOKING_VERSION')) {
    return;
}

define('FLUENT_BOOKING_DIR', plugin_dir_path(__FILE__));
define('FLUENT_BOOKING_URL', plugin_dir_url(__FILE__));
define('FLUENT_BOOKING_VERSION', '1.8.0');
define('FLUENT_BOOKING_ASSETS_VERSION', '1.8.0');

require __DIR__ . '/vendor/autoload.php';

call_user_func(function ($bootstrap) {
    $bootstrap(__FILE__);
}, require(__DIR__ . '/boot/app.php'));
