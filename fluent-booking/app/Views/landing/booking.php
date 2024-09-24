<?php

use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\Booking;

/**
 * @var ?Booking $existing_booking
 * @var Calendar $calendar
 * @var CalendarSlot $calendar_event
 * @var array $author
 * @var array $css_files
 * @var array $js_files
 * @var array $js_vars
 * @var boolean $on_rescheduling
 * @var string $description
 * @var string $title
 * @var string $url
 */
?>

<?php defined( 'ABSPATH' ) || exit; ?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <title><?php echo esc_attr($title); ?></title>
    <meta charset='utf-8'>

    <meta content='width=device-width, initial-scale=1' name='viewport'>
    <meta content='yes' name='apple-mobile-web-app-capable'>
    <meta name="description" content="<?php echo esc_attr($description); ?>">
    <meta name="robots" content="noindex">

    <link rel="icon" type="image/x-icon" href="<?php echo esc_url($author['avatar']); ?>"/>

    <meta property="og:title" content="<?php echo esc_attr($title); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo esc_url($url); ?>">
    <meta property="og:site_name" content="<?php echo esc_attr(get_bloginfo('name')); ?>">
    <meta property="og:description" content="<?php echo esc_attr($description); ?>">
    <meta property="og:author" content="<?php echo esc_attr($author['name']); ?>">

    <?php foreach ($css_files as $css_file): ?>
        <link rel="stylesheet"
              href="<?php echo esc_url($css_file); ?>?version=<?php echo esc_attr(FLUENT_BOOKING_ASSETS_VERSION); ?>"
              media="all"/>
    <?php endforeach; ?>

    <style>
        .fcal_wrap {
            display: block;
            max-width: 1000px !important;
            margin: 0 auto;
        }
    </style>

    <?php do_action('fluent_booking/author_landing_head', $calendar_event); ?>

</head>
<body>

    <div class="calendar_wrap">
        <?php do_action('fluent_booking/before_calendar_event_landing_page', $calendar_event); ?>
        <div class="fluent_booking_app fcal_loading" data-calendar_id="<?php echo (int)$calendar->id; ?>"
            data-event_id="<?php echo (int)$calendar_event->id; ?>">
            <h3><?php esc_html_e('Loading...', 'fluent-booking'); ?></h3>
        </div>
        <?php do_action('fluent_booking/after_calendar_event_landing_page', $calendar_event); ?>
    </div>

    <script>
        <?php foreach ($js_vars as $varKey => $values): ?>
            var <?php echo esc_attr($varKey); ?> = <?php echo wp_json_encode($values); ?>;
        <?php endforeach; ?>
    </script>

    <?php foreach ($js_files as $fileKey => $file): ?>
        <script id="<?php echo esc_attr($fileKey); ?>"
                src="<?php echo esc_url($file); ?>?version=<?php echo esc_attr(FLUENT_BOOKING_ASSETS_VERSION); ?>"
                defer="defer">
        </script>
    <?php endforeach; ?>

    <?php do_action('fluent_booking/author_landing_footer', $calendar_event); ?>
</body>
</html>
