<?php defined( 'ABSPATH' ) || exit; ?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <title><?php echo esc_attr($title); ?></title>
    <meta charset='utf-8'>

    <meta content='width=device-width, initial-scale=1' name='viewport'>
    <meta content='yes' name='apple-mobile-web-app-capable'>
    <meta name="description" content="<?php echo esc_attr($description); ?>">
    <meta name="robots" content="noindex"/>

    <?php if (!empty($author['avatar'])): ?>
        <link rel="icon" type="image/x-icon" href="<?php echo esc_url($author['avatar']); ?>">
    <?php endif; ?>

    <meta property="og:title" content="<?php echo esc_attr($title); ?>"/>
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo esc_url($url); ?>"/>
    <meta property="og:description" content="<?php echo esc_attr($description); ?>"/>
    <meta property="og:author" content="<?php echo esc_attr($author['name']); ?>"/>

    <?php if (!empty($author['featured_image'])) {
        ?>
        <meta property="og:image" content="<?php echo esc_url($author['featured_image']); ?>"/>
    <?php } ?>

    <?php foreach ($css_files as $css_file): ?>
        <link rel="stylesheet"
              href="<?php echo esc_url($css_file); ?>?version=<?php echo esc_html(FLUENT_BOOKING_ASSETS_VERSION); ?>"
              media="screen"/>
    <?php endforeach; ?>

    <style>
        :root {
            --fcal_dark: #1B2533;
            --fcal_primaryColor: #2653C7;
            --fcal_gray: #6b7280;
        }

        .fluent_booking_wrap {
            max-width: 752px;
            margin: 40px auto;
        }
        .fluent_booking_app {
            margin-top: 66px;
        }
        .fcal_author_header {
            max-width: 600px;
            margin: auto;
        }
        .fcal_slot {
            background: #fff;
        }

        .fcal_phone_wrapper .flag {
            background: url(<?php echo esc_url(\FluentBooking\App\App::getInstance()['url.assets'].'images/flags_responsive.png'); ?>) no-repeat;
            background-size: 100%;
        }
    </style>

    <?php foreach ($header_js_files as $fileKey => $file): ?>
        <script id="<?php echo esc_attr($fileKey); ?>" src="<?php echo esc_url($file); ?>?version=<?php echo esc_attr(FLUENT_BOOKING_ASSETS_VERSION); ?>"></script>
    <?php endforeach; ?>

    <?php do_action('fluent_booking/main_landing'); ?>
</head>
<body>
        <?php \FluentBooking\App\App::getInstance('view')->render('landing.author_html', [
            'author' => $author,
            'calendar' => $calendar,
            'events' => $events
        ]); ?>
        </div>
    </div>

    <script>
        <?php foreach ($js_vars as $varKey => $values): ?>
            var <?php echo esc_attr($varKey); ?> = <?php echo wp_json_encode($values); ?>;
        <?php endforeach; ?>
    </script>

    <?php foreach ($js_files as $fileKey => $file): ?>
        <script id="<?php echo esc_attr($fileKey); ?>" src="<?php echo esc_url($file); ?>?version=<?php echo esc_attr(FLUENT_BOOKING_ASSETS_VERSION); ?>" defer="defer"></script>
    <?php endforeach; ?>

    <?php do_action('fluent_booking/main_landing_footer'); ?>
</body>
</html>
