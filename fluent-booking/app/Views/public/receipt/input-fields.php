<?php defined( 'ABSPATH' ) || exit; ?>

<?php if ($items) : ?>
    <h4><?php esc_html_e('Customer Details', 'fluent-booking'); ?></h4>
    <table class="table fluent_booking_table input_items_table table_bordered">
        <tbody>
        <?php foreach ($items as $item) : ?>
            <?php if ((isset($item['value']) && $item['value'] !== '' && isset($item['label']))) : ?>
                <tr>
                    <th><?php echo wp_kses_post($item['label']); ?></th>
                    <td><?php
                        if (is_array($item['value'])) {
                            echo wp_kses_post(implode(', ', $item['value']));
                        } else {
                            echo wp_kses_post($item['value']);
                        }; ?>
                    </td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
