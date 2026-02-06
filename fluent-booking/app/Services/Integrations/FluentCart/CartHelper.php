<?php

namespace FluentBooking\App\Services\Integrations\FluentCart;

use FluentBooking\Framework\Support\Arr;
use FluentCart\App\Models\Order as CartOrder;
use FluentCart\App\Models\ProductVariation as CartProductVariant;

class CartHelper
{
    public static function getProduct($productId)
    {
        return CartProductVariant::where('id', $productId)
            ->whereHas('product', function ($query) {
                $query->whereIn('post_status', ['publish', 'private']);
            })
            ->first();
    }

    public static function getOrder($orderId)
    {
        return CartOrder::find($orderId);
    }

    public static function getEventProductId($calendarEvent, $duration = null)
    {
        $paymentSettings = $calendarEvent->getPaymentSettings();

        if ($calendarEvent->isMultiDurationEnabled() && Arr::get($paymentSettings, 'multi_payment_enabled') == 'yes') {
            if ($productId = Arr::get($paymentSettings, 'multi_payment_cart_ids.'. $duration)) {
                return (int) $productId;
            }
            return null;
        }

        $productId = Arr::get($paymentSettings, 'cart_product_id');
        if ($productId) {
            return (int) $productId;
        }

        return null;
    }

    public static function getProductVariationOptions($product)
    {
        $placeholder = self::getPlaceholderImage();

        $isSimple = Arr::get($product->detail, 'variation_type') == 'simple';
        
        $options = [];
        foreach ($product->variants as $variant) {
            $thumbnail = $isSimple ? $product->thumbnail : $variant->thumbnail;
            $options[] = [
                'value' => strval($variant->id),
                'title' => $variant->variation_title,
                'price' => $variant->formatted_total,
                'image' => $thumbnail ?? $placeholder
            ];
        }

        if (!empty($options)) {
            return [
                'id'      => $product->ID,
                'title'   => $product->post_title,
                'options' => $options
            ];
        }

        return [];
    }

    public static function getPlaceholderImage()
    {
        return \FluentCart\App\Vite::getAssetUrl('images/placeholder.svg');
    }

    public static function getCartProductPrice($paymentSettings, $defaultDuration)
    {
        $productId = $paymentSettings['cart_product_id'];

        if (Arr::get($paymentSettings, 'multi_payment_enabled') == 'yes') {
            $productId = Arr::get($paymentSettings, 'multi_payment_cart_ids.' . $defaultDuration);
        }

        $price = '';
        $product = self::getProduct($productId);
        if ($product) {
            $price = $product->formatted_total;
        }

        return $price;
    }

    public static function getCartProductPriceByDuration($productIds = [])
    {   
        $productPrices = [];
        if (empty($productIds)) {
            return $productPrices;
        }

        $products = CartProductVariant::whereIn('id', array_values($productIds))
            ->whereHas('product', function ($query) {
                $query->whereIn('post_status', ['publish', 'private']);
            })
            ->get()
            ->keyBy('id');

        foreach ($productIds as $duration => $productId) {
            if (!isset($products[$productId])) {
                continue;
            }

            $product = $products[$productId];
            $productPrices[$duration] = [
                'value' => $product->formatted_total
            ];
        }

        return $productPrices;
    }

    public static function isEnabled($calendarEvent = null)
    {
        if (!$calendarEvent) {
            return false;
        }

        return $calendarEvent->isPaidEvent() && $calendarEvent->isCartEnabled();
    }

    public static function getSuccessLog($bookingId, $order)
    {
        return [
            'booking_id'  => $bookingId,
            'status'      => 'closed',
            'type'        => 'success',
            'title'       => __('Cart: Booking status changed to scheduled', 'fluent-booking'),
            'description' => sprintf(
                /* translators: Notification message for the change of Woocommerce order status and booking status to scheduled. %1$s is a link to view the order, %2$s is the closing link tag */
                __('Cart order status changed to paid and booking status changed to scheduled. %1$sView Order%2$s', 'fluent-booking'),
                '<a target="_blank" href="' . $order->getViewUrl('admin') . '">',
                '</a>'
            )
        ];
    }
}