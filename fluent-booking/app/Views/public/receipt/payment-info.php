<?php defined( 'ABSPATH' ) || exit; ?>

<div class="fluent_booking_payment_info">
    <table width="100%">
        <tbody>
            <tr>
                <td>
                    <div class="fluent_booking_payment_info_item fluent_booking_payment_info_item_order_id">
                        <?php if ($order->items) : ?>
                            <div class="fluent_booking_item_heading"><?php esc_html_e('Order ID:', 'fluent-booking'); ?></div>
                        <?php else : ?>
                            <div class="fluent_booking_item_heading"><?php esc_html_e('Submission ID:', 'fluent-booking'); ?></div>
                        <?php endif; ?>
                        <div class="fluent_booking_item_value">#<?php echo esc_attr($order->id); ?></div>
                    </div>
                </td>
                <td>
                    <div class="fluent_booking_payment_info_item fluent_booking_payment_info_item_date">
                        <div class="fluent_booking_item_heading"><?php esc_html_e('Date:', 'fluent-booking'); ?></div>
                        <div class="fluent_booking_item_value"><?php echo esc_attr(gmdate(get_option('date_format'), strtotime($order->created_at))); ?></div>
                    </div>
                </td>
                <?php if ($order->total_amount) : ?>
                    <?php
                        $currencySetting['currency_sign'] = \FluentBooking\App\Services\CurrenciesHelper::getCurrencySign($order->currency);
                    ?>
                <td>
                    <div class="fluent_booking_payment_info_item fluent_booking_payment_info_item_total">
                        <div class="fluent_booking_item_heading"><?php esc_html_e('Total:', 'fluent-booking'); ?></div>
                        <div class="fluent_booking_item_value"><?php echo ($order->total_amount > 0) ? esc_attr(fluentbookingFormattedAmount($order->total_amount, $currencySetting)) : 'pending'; ?></div>
                    </div>
                </td>
                <?php endif; ?>
                <?php if ($order->payment_method) : ?>
                    <td>
                        <div class="fluent_booking_payment_info_item fluent_booking_payment_info_item_payment_method">
                            <div class="fluent_booking_item_heading"><?php esc_html_e('Payment Method:', 'fluent-booking'); ?></div>
                            <div class="fluent_booking_item_value"><?php echo esc_attr(ucfirst($order->payment_method)); ?></div>
                        </div>
                    </td>
                <?php endif; ?>
                <?php if ($order->status && $order->items) : ?>
                        <td>
                            <div class="fluent_booking_payment_info_item fluent_booking_payment_info_item_payment_status">
                                <div class="fluent_booking_item_heading"><?php esc_html_e('Payment Status:', 'fluent-booking'); ?></div>
                                <div class="fluent_booking_item_value"><?php echo esc_attr(ucfirst($order->status)); ?></div>
                            </div>
                        </td>
                <?php endif; ?>
            </tr>
        </tbody>
    </table>
</div>


