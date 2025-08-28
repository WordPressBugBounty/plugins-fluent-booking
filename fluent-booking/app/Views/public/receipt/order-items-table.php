<?php

defined( 'ABSPATH' ) || exit;

if (!$order->items) {
    return '';
}

?>
    <table class="table fluent_booking_order_items_table fluent_booking_table table_bordered">
        <thead>
        <th><?php esc_html_e('Item', 'fluent-booking'); ?></th>
        <th><?php esc_html_e('Quantity', 'fluent-booking'); ?></th>
        <th><?php esc_html_e('Price', 'fluent-booking'); ?></th>
        <th><?php esc_html_e('Line Total', 'fluent-booking'); ?></th>
        </thead>
        <tbody>
        <?php $subTotal = 0; ?>
        <?php foreach ($order->items->toArray() as $order_item) {
           if (is_array($order_item)) {
               if ($order_item['item_total']) :?>
                   <tr>
                       <td><?php echo esc_html($order_item['item_name']); ?></td>
                       <td><?php echo esc_html($order_item['quantity']); ?></td>
                       <td><?php echo esc_attr(fluentbookingFormattedAmount($order_item['item_price'], $currency_settings)); ?></td>
                       <td><?php echo esc_attr(fluentbookingFormattedAmount($order_item['item_total'], $currency_settings)); ?></td>
                   </tr>
                   <?php
                   $subTotal += $order_item['item_total'];
               endif;
           } else {
               if ($order_item->item_total) :?>
                   <tr>
                       <td><?php echo esc_html($order_item->item_name); ?></td>
                       <td><?php echo esc_html($order_item->quantity); ?></td>
                       <td><?php echo esc_html(fluentbookingFormattedAmount($order_item->item_price, $currency_settings)); ?></td>
                       <td><?php echo esc_html(fluentbookingFormattedAmount($order_item->item_total, $currency_settings)); ?></td>
                   </tr>
                   <?php
                   $subTotal += $order_item->item_total;
               endif;
           }

        };
        ?>
        </tbody>
        <tfoot>
        <?php $discountTotal = 0;
        if (isset($order->discounts) && count($order->discounts)) : ?>
            <tr class="fluent_booking_total_row">
                <th style="text-align: right" colspan="3"><?php esc_html_e('Sub-Total', 'fluent-booking'); ?></th>
                <td><?php echo esc_html(fluentbookingFormattedAmount($subTotal, $currency_settings)); ?></td>
            </tr>
            <?php
            foreach ($order->discounts as $discount) :
                $discountTotal += $discount->item_total;
                ?>
                <tr class="fluent_booking_discount_row">
                    <th style="text-align: right"
                        colspan="3"><?php printf(esc_html__('Discounts(%s)', 'fluent-booking'), esc_html($discount->item_name)); ?></th>
                    <td><?php echo '-' . esc_html(fluentbookingFormattedAmount($discount->item_total, $currency_settings)); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        <tr class="fluent_booking_total_payment_row">
            <th style="text-align: right" colspan="3"><?php esc_html_e('Total', 'fluent-booking'); ?></th>
            <td>
                <?php if (isset($hasSubscription) && $hasSubscription) : ?> 
                    <?php echo esc_attr(fluentbookingFormattedAmount($order->total_amount, $currency_settings)); ?>
                <?php else:  ?> 
                    <?php echo esc_attr(fluentbookingFormattedAmount($order->total_amount, $currency_settings)); ?>
                <?php endif; ?>
            </td>
        </tr>
        </tfoot>
    </table>
