<?php
defined( 'ABSPATH' ) || exit;

/**
 * @var string $header_content
 */
?>

<div class="fluent_booking_submission_header">
    <div class="fluent_booking_submission_message" style="margin-bottom: 20px;">
        <?php echo $header_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </div>
</div>
