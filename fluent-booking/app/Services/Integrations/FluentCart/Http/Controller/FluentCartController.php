<?php

namespace FluentBooking\App\Services\Integrations\FluentCart\Http\Controller;

use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Http\Controllers\Controller;
use FluentBooking\Framework\Http\Request\Request;
use FluentCart\App\Models\Product as CartProduct;
use FluentCart\App\Models\ProductDetail as CartProductDetail;
use FluentCart\App\Models\ProductVariation as CartProductVariant;
use FluentCart\App\Services\Permission\PermissionManager as CartPermission;
use FluentBooking\App\Services\Integrations\FluentCart\CartHelper;
use FluentBooking\Framework\Support\Arr;

class FluentCartController extends Controller
{
    public function createProduct(Request $request)
    {
        if (!CartPermission::hasPermission('products/create')) {
            return $this->sendError([
                'message' => __('You do not have permission to create products', 'fluent-booking')
            ], 422);
        }

        $title = $request->getSafe('title');
        $price = $request->getSafe('price', 'floatval');

        $postData = [
            'post_title'  => $title,
            'post_name'   => sanitize_title($title),
            'post_status' => 'publish',
            'post_type'   => \FluentCart\App\CPT\FluentProducts::CPT_NAME,
        ];

        $createdPostId = wp_insert_post($postData);

        if (is_wp_error($createdPostId)) {
            return $this->sendError([
                'code'    => 403,
                'message' => $createdPostId->get_error_message()
            ]);
        }

        $detailData = [
            'post_id'          => $createdPostId,
            'fulfillment_type' => 'digital',
            'min_price'        => $price * 100,
            'max_price'        => $price * 100,
        ];

        $productDetail = CartProductDetail::create($detailData);

        $variationData = [
            'post_id'          => $createdPostId,
            'serial_index'     => 1,
            'variation_title'  => $title,
            'stock_status'     => 'in-stock',
            'payment_type'     => 'onetime',
            'total_stock'      => 1,
            'available'        => 1,
            'fulfillment_type' => 'digital',
            'item_price'       => $price * 100,
            'other_info'       => [
                'description'        => '',
                'payment_type'       => 'onetime',
                'times'              => '',
                'repeat_interval'    => '',
                'trial_days'         => '',
                'billing_summary'    => '',
                'manage_setup_fee'   => 'no',
                'signup_fee_name'    => '',
                'signup_fee'         => '',
                'setup_fee_per_item' => 'no',
            ]
        ];

        $variation = CartProductVariant::create($variationData);

        if (!$productDetail || !$variation) {
            return $this->sendError([
                'code'    => 403,
                'message' => __('Failed to create product', 'fluent-booking')
            ]);
        }

        $product = CartProduct::where('ID', $createdPostId)->first();

        $product->updateProductMeta('created_from', 'fluent_booking');

        $formattedProduct = CartHelper::getProductVariationOptions($product);

        return [
            'message' => __('Product has been created', 'fluent-booking'),
            'product' => $formattedProduct,
            'variant' => $variation
        ];
    }

    public function getCartProducts(Request $request)
    {
        $search = trim($request->getSafe('search'));
        $includeId = $request->getSafe('include_id', 'intval');

        $products = CartProduct::query()
            ->select('ID', 'post_title')
            ->whereIn('post_status', ['publish', 'private'])
            ->whereHas('detail')
            ->whereHas('variants')
            ->when($search, function ($query) use ($search) {
                $query->where('post_title', 'LIKE', '%' . $search . '%');
            })
            ->with(['detail', 'variants' => function ($query) {
                $query->select('id', 'post_id', 'item_price', 'variation_title');
            }])
            ->limit(20)
            ->get();

        $includeProductId = $includeId ? CartProductVariant::where('id', $includeId)->value('post_id') : null;

        $isIncluded = false;
        $formattedProducts = [];
        foreach ($products as $product) {
            if ($product->ID == $includeProductId) {
                $isIncluded = true;
            }
            $formattedProduct = CartHelper::getProductVariationOptions($product);
            $formattedProducts[] = $formattedProduct;
        }

        if (!$isIncluded && $includeProductId) {
            $product = CartProduct::where('ID', $includeProductId)
                ->select('ID', 'post_title')
                ->whereIn('post_status', ['publish', 'private'])
                ->whereHas('detail')
                ->whereHas('variants')
                ->with(['detail', 'variants' => function ($query) {
                    $query->select('id', 'post_id', 'item_price', 'variation_title');
                }])->first();
            $formattedProduct = CartHelper::getProductVariationOptions($product);
            $formattedProducts[] = $formattedProduct;
        }

        return [
            'items' => array_filter($formattedProducts)
        ];
    }

    public function saveEventCartSettings(Request $request, $calendarId, $eventId)
    {
        $calendarEvent = CalendarSlot::where('calendar_id', $calendarId)->findOrFail($eventId);

        $data = $request->validate([
            'settings' => 'required|array',
        ]);

        $data = $data['settings'];

        $isEnabled = Arr::get($data, 'enabled', 'no') === 'yes';
        $isMultiEnabled = Arr::get($data, 'multi_payment_enabled', 'no') === 'yes';

        $eventType = $isEnabled ? 'cart' : 'free';

        $settings = $calendarEvent->getPaymentSettings();

        $settings['enabled'] = $isEnabled ? 'yes' : 'no';
        $settings['multi_payment_enabled'] = $isMultiEnabled ? 'yes' : 'no';
        $settings['driver'] = $isEnabled ? 'cart' : $settings['driver'];

        if (!$isMultiEnabled) {
            $product = CartHelper::getProduct(Arr::get($data, 'cart_product_id'));
            if (!$product) {
                return $this->sendError([
                    'message' => __('Please select a product', 'fluent-booking')
                ], 422);
            }

            $settings['cart_product_id'] = Arr::get($data, 'cart_product_id');
        }

        if ($isMultiEnabled) {
            $productIds = array_map('intval', Arr::get($data, 'multi_payment_cart_ids'));
            $products = CartProductVariant::whereIn('id', array_values($productIds))->exists();
            if (!$products) {
                return $this->sendError([
                    'message' => __('Please select products', 'fluent-booking')
                ], 422);
            }

            $settings['multi_payment_cart_ids'] = Arr::get($data, 'multi_payment_cart_ids');
        }

        $calendarEvent->type = $eventType;
        $calendarEvent->save();

        $calendarEvent->updateMeta('payment_settings', $settings);

        return [
            'message' => __('Settings has been saved', 'fluent-booking')
        ];
    }
}