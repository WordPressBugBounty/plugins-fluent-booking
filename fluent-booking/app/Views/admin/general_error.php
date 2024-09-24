<?php defined( 'ABSPATH' ) || exit; ?>

<!DOCTYPE html>
<html lang='en'>
<head>
    <title>Something went wrong</title>
    <meta charset='utf-8'>

    <meta content='width=device-width, initial-scale=1' name='viewport'>
    <meta content='yes' name='apple-mobile-web-app-capable'>
    <meta name="robots" content="noindex"/>

    <style>
        body * {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        body {
            background: #f6f6f6;
        }
        .fcal_error_wrap {
            margin: 100px auto;
            max-width: 600px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,.1);
        }

        .error_header {
            padding: 10px 15px;
            border-bottom: 1px solid #ebebeb;
        }

        .error_header h3 {
            margin: 0;
        }

        .error_body {
            padding: 10px 15px;
        }

        .error_footer {
            padding: 10px 15px;
            text-align: center;
            border-top: 1px solid #ebebeb;
        }

        .error_footer a {
            padding: 9px 12px;
            display: inline-block;
            text-decoration: none;
            background: #3F51B5;
            color: white;
            border-radius: 6px;
        }
    </style>
</head>
<body>
<div class="fcal_error_wrap">
    <div class="error_header">
        <h3><?php echo esc_html($title); ?></h3>
    </div>
    <div class="error_body">
        <p><?php echo wp_kses_post($body); ?></p>
    </div>
    <?php if (!empty($btn_url)): ?>
        <div class="error_footer">
            <a href="<?php echo esc_url($btn_url); ?>" class="button"><?php echo esc_html($btn_text); ?></a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
