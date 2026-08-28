<?php
defined( 'ABSPATH' ) || exit;

/**
 * @var string $calendar_app
 * @var string $element_id
 */
?>

<div class="ff-el-group has-conditions">
    <div class="fcal_cal_wrap">
        <div class="<?php echo esc_attr($calendar_app); ?>" data-element_id="<?php echo esc_attr($element_id); ?>"></div>
    </div>
</div>
