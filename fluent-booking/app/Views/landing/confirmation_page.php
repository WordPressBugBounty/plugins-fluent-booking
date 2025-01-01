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

    <link rel="icon" type="image/x-icon" href="<?php echo esc_url($author['avatar']); ?>" />

    <meta property="og:title" content="<?php echo esc_attr($title); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo esc_url($url); ?>">
    <meta property="og:description" content="<?php echo esc_attr($description); ?>">
    <meta property="og:author" content="<?php echo esc_attr($author['name']); ?>">

    <?php foreach ($css_files as $css_file): ?>
        <link rel="stylesheet" href="<?php echo esc_url($css_file); ?>?version=<?php echo esc_attr(FLUENT_BOOKING_ASSETS_VERSION); ?>" media="all" />
    <?php endforeach; ?>
</head>
<body class="booking-confirmation-page">

    <div class="confirmation_page">
        <div class="fcal_conf_wrap">
            <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php if (!empty($back_button['show'])): ?>
            <div class="fcal_back_btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-left h-5 w-5 rtl:rotate-180">
                    <path d="m15 18-6-6 6-6"></path>
                </svg>
                <a href="<?php echo esc_url($back_button['url']); ?>"><?php echo esc_html($back_button['text']); ?></a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        <?php foreach ($js_vars as $varKey => $values): ?>
            var <?php echo esc_attr($varKey); ?> = <?php echo wp_json_encode($values); ?>;
        <?php endforeach; ?>
    </script>

    <?php foreach ($js_files as $fileKey => $file): ?>
        <script id="<?php echo esc_attr($fileKey); ?>" src="<?php echo esc_url($file); ?>" defer="defer"></script>
    <?php endforeach; ?>

    <script>
        const theme = '<?php echo esc_attr($theme); ?>';

        const confirmationPage = document.querySelector('.confirmation_page');
        function applyModeClasses(element, darkMode) {
            const darkClass  = 'fcal-dark-mode';
            const lightClass = 'fcal-light-mode';

            if (element) {
                if (darkMode) {
                    element.classList.add(darkClass);
                    element.classList.remove(lightClass);
                } else {
                    element.classList.add(lightClass);
                    element.classList.remove(darkClass);
                }
            }
        }

        if (confirmationPage) {
            if (theme === 'system-default') {
                const runColorMode = (fn) => {
                    if (!window.matchMedia) {
                        return;
                    }
                    const query = window.matchMedia('(prefers-color-scheme: dark)');
                    fn(query.matches);
                    query.addEventListener('change', (event) => fn(event.matches));
                };

                runColorMode((isDarkMode) => {
                    applyModeClasses(confirmationPage, isDarkMode);
                });
            } else if (theme === 'dark-mode') {
                applyModeClasses(confirmationPage, true);
            } else {
                applyModeClasses(confirmationPage, false);
            }
        }

    </script>
</body>
</html>
