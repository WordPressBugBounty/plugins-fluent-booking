<?php defined( 'ABSPATH' ) || exit; ?>

<?php if ($items) : ?>
    <h4><?php esc_html_e('Customer Details', 'fluent-booking'); ?></h4>
    <table class="table fluent_booking_table input_items_table table_bordered">
        <tbody>
        <?php foreach ($items as $fluentBookingItem) : ?>
            <?php if ((isset($fluentBookingItem['value']) && $fluentBookingItem['value'] !== '' && isset($fluentBookingItem['label']))) : ?>
                <tr>
                    <th><?php echo wp_kses_post($fluentBookingItem['label']); ?></th>
                    <td><?php
                        if (is_array($fluentBookingItem['value'])) {
                            echo wp_kses_post(implode(', ', $fluentBookingItem['value']));
                        } else {
                            echo wp_kses_post($fluentBookingItem['value']);
                        }; ?>
                    </td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
