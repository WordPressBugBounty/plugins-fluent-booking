<?php
    /**
     * @var $theme
     * @var $calenderEvent
     */
    
    defined( 'ABSPATH' ) || exit;

    $mode = '';
    if ($theme == 'dark') {
        $mode = 'fcal-dark-mode';
    } else if ($theme == 'light') {
        $mode = 'fcal-light-mode';
    }
?>
<div class="fcal_cal_wrap <?php echo esc_attr($mode); ?>">
    <div class="fluent_booking_app" data-calendar_id="<?php echo (int)$calenderEvent->calendar_id; ?>"
         data-event_id="<?php echo (int)$calenderEvent->id; ?>"></div>
    <?php do_action('fluent_booking/short_code_render', $calenderEvent); ?>
</div>

