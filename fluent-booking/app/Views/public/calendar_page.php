<?php defined( 'ABSPATH' ) || exit; ?>

<div id="<?php echo esc_attr($wrapper_id); ?>" class="fcal_calendar_wrapper fcal_calendars <?php echo esc_attr($wrapper_class); ?>">
    <div class="fcal_calendar_block_inner">
        <div style="display: none;" class="fcal_cals_wrap">
            <?php if (!empty($logo) || !empty($title) || !empty($description)): ?>
                <div class="fcal_calendar_header">
                    <?php if ($logo): ?>
                        <div class="fcal_person_avatar">
                            <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($title); ?>">
                        </div>
                    <?php endif; ?>
                    <?php if ($title): ?>
                        <h2 class="fcal_calendar_title"><?php echo wp_kses_post($title); ?></h2>
                    <?php endif; ?>
                    <?php if ($description): ?>
                        <div class="fcal_calendar_description">
                            <?php echo wp_kses_post($description); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div style="height: 250px;width: 100%;text-align: center;display: flex;align-items: center;justify-content: center;flex-basis: max-content;flex-direction: column;" class="fcal_calendar_loading">
            <h3><?php esc_html_e('Loading....', 'fluent-booking'); ?></h3>
            <i class="fcal-inline-spinner"></i>
            <style>
                @keyframes fcal-inline-spinner-kf {
                    0% {
                        transform: rotate(0deg);
                    }
                    100% {
                        transform: rotate(360deg);
                    }
                }

                .fcal-inline-spinner,
                .fcal-inline-spinner:before {
                    display: inline-block;
                    width: 111px;
                    height: 111px;
                    transform-origin: 50%;
                    border: 2px solid transparent;
                    border-color: #74a8d0 #74a8d0 transparent transparent;
                    border-radius: 50%;
                    content: "";
                    animation: linear fcal-inline-spinner-kf 900ms infinite;
                    position: relative;
                    vertical-align: inherit;
                    line-height: inherit;
                }
                .c-inline-spinner {
                    top: 3px;
                    margin: 0 3px;
                }
                .c-inline-spinner:before {
                    border-color: #74a8d0 #74a8d0 transparent transparent;
                    position: absolute;
                    left: -2px;
                    top: -2px;
                    border-style: solid;
                }
            </style>
        </div>
    </div>
</div>

<style>
    .fcal_phone_wrapper .flag {
        background: url(<?php echo esc_url(\FluentBooking\App\App::getInstance()['url.assets'].'images/flags_responsive.png'); ?>) no-repeat;
        background-size: 100%;
    }
</style>
