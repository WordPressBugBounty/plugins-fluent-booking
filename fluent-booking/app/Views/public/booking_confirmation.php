<?php
    /*
    * @var $booking \FluentBooking\App\Models\Booking
    */
    defined( 'ABSPATH' ) || exit;
?>

<div class="fcal_confirmation">
    <?php do_action('fluent_booking/booking_details_header', $booking); ?>
    <div class="fcal_confirm_header">
        <div class="fcal_check_holder">
            <?php if ($confirm_icon): ?>
                <img style="max-width: 44px;" src="<?php echo $confirm_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" />
            <?php else: ?>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-calendar text-emphasis h-5 w-5"><path d="M8 2v4"></path><path d="M16 2v4"></path><rect width="18" height="18" x="3" y="4" rx="2"></rect><path d="M3 10h18"></path></svg>
            <?php endif; ?>
        </div>
        <h2><?php echo esc_html($title); ?></h2>
        <p><?php echo wp_kses_post($sub_heading); ?></p>
    </div>
    <div class="fcal_confirm_body">
        <?php foreach ($sections as $section): ?>
            <div class="fcal_confirm_section">
                <div class="fcal_confirm_section_title">
                    <h4><?php echo esc_html($section['title']); ?></h4>
                </div>
                <?php foreach ((array)$section['content'] as $content) : ?>
                    <div class="fcal_confirm_section_content">
                        <?php echo wp_kses_post($content); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <?php if($extra_html): ?>
        <hr />
        <div class="fcal_payment_html">
            <h3 style="margin-bottom: 10px;"><?php esc_html_e('Payment Details', 'fluent-booking'); ?></h3>
            <?php echo $extra_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <style>
            .fluent_booking_payment_receipt {
                background: transparent !important;
                padding: 0 !important;
            }
            .fcal_payment_html h4 {
                margin: 10px 0;
            }
        </style>
        <?php endif; ?>

        <?php if ($action_type == 'cancel'): ?>
            <div class="fcal_booking_manage fcal_cancellation_wrap fcal_action_<?php echo esc_attr($action_type); ?>">
                <form id="fcal_cancellation_form" action="<?php echo esc_url($action_url); ?>" method="POST" class="fcal_form_cancellation">
                    <label for="cancellation_reason">
                        <?php echo esc_html($cancel_field['label'])?>
                        <?php if ($cancel_field['required']) : ?>
                            <span class="required">*</span>
                        <?php endif; ?>
                    </label>
                    <div class="fcal_form_field">
                        <textarea placeholder="<?php echo esc_attr($cancel_field['placeholder']); ?>"
                            name="cancellation_reason" id="cancellation_reason" rows="3"></textarea>
                        <?php if ($cancel_field['help_text']) : ?>
                            <p class="fcal_help_text"><?php echo esc_html($cancel_field['help_text']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="fcal_form_actions">
                        <a href="<?php echo esc_url($booking->getConfirmationUrl()); ?>"
                            class="fcal_btn fcal_btn_secondary"><?php esc_html_e('Nevermind', 'fluent-booking'); ?></a>
                        <button class="fcal_btn fcal_btn_primary fcal_cancel_btn"
                                type="submit"><?php esc_html_e('Cancel Booking', 'fluent-booking'); ?></button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <?php if ($booking->canCancel() || $booking->canReschedule()): ?>
                <div class="fcal_booking_manage fcal_normal_booking_footer">
                    <?php esc_html_e('Need to make a change?', 'fluent-booking') ?>
                    <?php if ($booking->canCancel()): ?>
                        <a href="<?php echo esc_url($booking->getCancelUrl()); ?>"><?php esc_html_e('Cancel', 'fluent-booking') ?></a>
                    <?php endif; ?>
                    <?php ($booking->canCancel() && $booking->canReschedule()) ? esc_html_e('or', 'fluent-booking') : ''; ?>
                    <?php if ($booking->canReschedule()): ?>
                        <a href="<?php echo esc_url($booking->getRescheduleUrl()); ?>"><?php esc_html_e('Reschedule', 'fluent-booking');  ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($bookmarks): ?>
            <div class="fcal_booking_manage fcal_to_calendars">
                <span><?php esc_html_e('Add to calendar', 'fluent-booking'); ?></span>
                <div class="fcal_cal_items">
                    <?php foreach ($bookmarks as $bookmark): ?>
                        <div title="<?php echo esc_attr($bookmark['title']); ?>">
                            <a href="<?php echo esc_url($bookmark['url']); ?>" target="_blank" rel="noopener">
                                <img style="width: 20px; height: 20px;" src="<?php echo esc_url($bookmark['icon']); ?>" alt="<?php echo esc_attr($bookmark['title']); ?>"/>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php do_action('fluent_booking/booking_confirmation_footer', $booking); ?>
</div>
