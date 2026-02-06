<?php

namespace FluentBooking\App\Services\Integrations\FluentCart;

use FluentBooking\Framework\Foundation\Application;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\Renderer\Receipt\ThankYouRender;
use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\App\Services\Helper;
use FluentBooking\Framework\Support\Arr;

class Bootstrap
{
    public function __construct(Application $app)
    {
        $app->router->group(function ($router) {
            require_once __DIR__ . '/Http/cart_api.php';
        });

        $this->registerHooks();
    }

    public function registerHooks()
    {
        add_filter('fluent_booking/public_event_vars', [$this, 'maybePushPaymentVars'], 11, 2);
        add_filter('fluent_booking/booking_data', [$this, 'maybePushPaymentData'], 10, 2);
        add_action('fluent_cart/cart/cart_data_items_updated', [$this, 'maybeSaveBookingID'], 10, 1);

        add_action('fluent_cart/receipt/thank_you/after_order_items', [$this, 'maybeShowBookingDetailsInReceipt'], 10, 1);
        add_action('fluent_booking/booking_meta_info_main_meta_cart', [$this, 'pushOrderDataToBookingView'], 10, 2);

        // new API
        add_action('fluent_cart/cart/line_item/line_meta', [$this, 'maybeRenderBookingInfoOnCartItem'], 10, 1);
        // We are adding booking id to the order config after draft is created
        add_action('fluent_booking/cart/booking_order_created', function ($eventData) {
            $cart = Arr::get($eventData, 'cart');
            if (empty($cart->checkout_data['fluent_booking_data'])) {
                return;
            }

            $order = Arr::get($eventData, 'order');
            $config = $order->config;
            $bookingId = Arr::get($cart->checkout_data, 'fluent_booking_data.booking_id');
            if ($bookingId) {
                $config['fcal_booking_id'] = $bookingId;
                $order->config = $config;
                $order->save();
            }
        });

        // after order confirmation
        add_action('fluent_booking/cart/booking_order_completed', [$this, 'maybeScheduleBooking'], 10, 1);

    }


    public function maybePushPaymentVars($eventVars, CalendarSlot $calendarEvent)
    {
        if (!CartHelper::isEnabled($calendarEvent)) {
            return $eventVars;
        }

        $paymentSettings = $calendarEvent->getPaymentSettings();
        $defaultDuration = $calendarEvent->getDefaultDuration();

        $eventVars['slot']['total_payment'] = CartHelper::getCartProductPrice($paymentSettings, $defaultDuration);

        $isMultiEnabled = Arr::get($paymentSettings, 'multi_payment_enabled') === 'yes';
        if ($calendarEvent->isMultiDurationEnabled() && $isMultiEnabled) {
            $productIds = Arr::get($paymentSettings, 'multi_payment_cart_ids', []);
            $eventVars['multi_payment_cart_ids'] = CartHelper::getCartProductPriceByDuration($productIds);
        }

        return $eventVars;
    }

    public function maybePushPaymentData($bookingData, $calendarEvent)
    {
        if (Arr::get($bookingData, 'source') != 'web') {
            return $bookingData;
        }

        if (!CartHelper::isEnabled($calendarEvent)) {
            return $bookingData;
        }

        $duration = Arr::get($bookingData, 'slot_minutes');

        $variationId = CartHelper::getEventProductId($calendarEvent, $duration);
        if (!$variationId) {
            return $bookingData;
        }

        $product = CartHelper::getProduct($variationId);
        if (!$product) {
            return $bookingData;
        }

        $bookingData['source'] = 'cart';
        $bookingData['payment_method'] = 'fluent_cart';
        $bookingData['payment_status'] = 'pending';
        $bookingData['status'] = 'pending';

        add_filter('fluent_booking/booking_confirmation_response', function ($response, $booking) use ($product) {
            if ($booking->status != 'pending' || $booking->source != 'cart') {
                return $response;
            }

            $quantity = $booking->getMeta('quantity', 1);
            $newItem = $product->toArray();

            $instantCart = \FluentCart\App\Helpers\CartHelper::generateCartFromCustomVariation($newItem, $quantity);

            $cartData = $instantCart->cart_data;
            $cartData[0]['fcal_booking_id'] = $booking->id;
            $instantCart->cart_data = $cartData;
            
            $instantCart->cart_group = 'instant';
            $instantCart->first_name = $booking->first_name;
            $instantCart->last_name = $booking->last_name;
            $instantCart->email = $booking->email;
            $instantCart->user_id = $booking->person_user_id;
            $instantCart->cart_hash = md5('booking_cart_' . wp_generate_uuid4() . time());
            $instantCart->checkout_data = [
                'is_locked'                       => 'yes',
                'fluent_booking_data'             => [
                    'booking_id'        => $booking->id,
                    'target_variant_id' => $product->id
                ],
                '__on_success_actions__'          => [
                    'fluent_booking/cart/booking_order_completed'
                ],
                '__after_draft_created_actions__' => [
                    'fluent_booking/cart/booking_order_created'
                ],
                '__cart_notices'                  => [

                ]
            ];

            $instantCart->save();

            $cartHash = $instantCart->cart_hash;
            $checkoutUrl = add_query_arg(
                [
                    'fct_cart_hash' => $cartHash
                ],
                (new StoreSettings())->getCheckoutPage()
            );

            $response['data']['redirect_to'] = $checkoutUrl;
            $response['data']['redirect_message'] = __('You are redirecting to checkout page to complete the appointment.', 'fluent-booking');

            do_action('fluent_booking/log_booking_activity', [
                'booking_id'  => $booking->id,
                'status'      => 'closed',
                'type'        => 'info',
                'title'       => __('Redirect to FluentCart checkout page', 'fluent-booking'),
                'description' => __('User redirected to FluentCart checkout page to comeplete the order.', 'fluent-booking')
            ]);

            return $response;
        }, 10, 2);

        return $bookingData;
    }

    public function maybeSaveBookingID($cart)
    {
        $bookingHash = '';
        if (isset($_REQUEST['fcal_hash'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $bookingHash = sanitize_text_field(wp_unslash($_REQUEST['fcal_hash'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if (!$bookingHash || empty($cart['cart'])) {
            return;
        }

        $cartData = $cart['cart']->cart_data;
        if (!empty(Arr::get($cartData[0], 'fcal_booking_id'))) {
            return;
        }

        $booking = Booking::where('hash', $bookingHash)->first();
        if (!$booking) {
            return;
        }

        if (!CartHelper::isEnabled($booking->calendar_event)) {
            return;
        }

        $cartData[0]['fcal_booking_id'] = $booking->id;
        $cart['cart']->cart_data = $cartData;
        $cart['cart']->save();
    }

    public function maybeRenderBookingInfoOnCartItem($eventInfo)
    {
        $item = Arr::get($eventInfo, 'item', []);

        if (empty($item['fcal_booking_id'])) {
            return;
        }

        $bookingId = $item['fcal_booking_id'];
        $booking = Booking::find($bookingId);
        if (!$booking || $booking->status !== 'pending') {
            return;
        }

        $bookingTime = $booking->getFullBookingDateTimeText($booking->person_time_zone, true) . ' (' . $booking->person_time_zone . ')';

        if ($booking->calendar_event->allowMultiBooking()) {
            $bookingTime = array_merge((array)$bookingTime, $booking->getOtherBookingTimes());
        }
        ?>
        <div class="fct_item_title booking_meta">
            <div class="fcal_meta_label"
                 style="font-weight: 600;"><?php echo esc_html__('Appointment:', 'fluent-booking'); ?></div>
            <div class="fcal_meta_value"
                 style="font-weight: 400;"><?php echo esc_html(implode(', ', (array)$bookingTime)); ?></div>
        </div>
        <?php
    }

    public function maybeScheduleBooking($eventData)
    {
        $order = Arr::get($eventData, 'order');
        $bookingId = Arr::get($order->config, 'fcal_booking_id', '');
        if (empty($order) || empty($bookingId)) {
            return;
        }

        $cart = Arr::get($eventData, 'cart');

        if (!$cart) {
            return;
        }

        $targetProductId = Arr::get($cart->checkout_data, 'fluent_booking_data.target_variant_id', 0);

        if (!$targetProductId) {
            return;
        }

        $checkoutItem = array_filter($cart->cart_data, function ($item) use ($targetProductId) {
            return Arr::get($item, 'object_id', 0) == $targetProductId;
        });

        if (!$checkoutItem) {
            return;
        }

        $booking = Booking::find($bookingId);
        if (!$booking || $booking->source_id) {
            return;
        }

        $calendarEvent = $booking->calendar_event;
        if (!CartHelper::isEnabled($calendarEvent)) {
            return;
        }

        if ($booking->status != 'pending') {
            do_action('fluent_booking/log_booking_activity', [
                'booking_id'  => $booking->id,
                'status'      => 'closed',
                'type'        => 'success',
                'title'       => __('Cart: Booking status could not be changed', 'fluent-booking'),
                'description' => sprintf(
                /* translators: Notification message when the booking status could not be changed due to its current status. %1$s is the status, %2$s and %3$s are the HTML link tags for "View Order" */
                    __('Booking status could not be changed as it is in %1$s status. %2$sView Order%3$s', 'fluent-booking'),
                    $booking->status,
                    '<a target="_blank" href="' . $order->getViewUrl('admin') . '">',
                    '</a>')
            ]);
            return;
        }

        $isRequireConfirmation = $calendarEvent->isConfirmationRequired($booking->start_time, $booking->created_at);

        if (!$isRequireConfirmation) {
            $booking->status = 'scheduled';
        }

        $booking->payment_status = 'paid';
        $booking->source_id = $order->id;
        $booking->save();

        $this->maybeUpdateChildBookings($booking, $calendarEvent, $order);

        do_action('fluent_booking/log_booking_activity', CartHelper::getSuccessLog($booking->id, $order));

        $bookingData = [
            'name'  => $booking->first_name . ' ' . $booking->last_name,
            'email' => $booking->email,
            'phone' => $booking->phone
        ];

        $order->addLog(
            __('Booking Confirmation', 'fluent-booking'),
            sprintf(
            /* translators: Order log message for the change of booking status to scheduled. %1$s is the booking ID, %2$s is the booking status, %3$s is the date/time+timezone, %4$s is a link open tag, %5$s is the link close tag */
                __('Booking #%1$s status changed to %2$s at %3$s. %4$sView Booking%5$s', 'fluent-booking'),
                $booking->id,
                $booking->status,
                $booking->getFullBookingDateTimeText($booking->calendar->author_timezone, true) . ' (' . $booking->calendar->author_timezone . ')',
                '<a target="_blank" href="' . esc_url(Helper::getAppBaseUrl('scheduled-events?period=upcoming&booking_id=' . $booking->id)) . '">',
                '</a>'
            ),
            'info',
            'FluentBooking'
        );

        // this pre hook is for early actions that require for remote calendars and locations
        do_action('fluent_booking/pre_after_booking_' . $booking->status, $booking, $booking->calendar_event, $bookingData);

        do_action('fluent_booking/after_booking_' . $booking->status, $booking, $booking->calendar_event, $bookingData);
    }

    public function maybeShowBookingDetailsInReceipt($event)
    {
        $order = Arr::get($event, 'order');
        $bookingId = Arr::get($order->config, 'fcal_booking_id', '');

        if (empty($order) || empty($bookingId)) {
            return;
        }

        $booking = Booking::find($bookingId);
        if (!$booking) {
            return;
        }

        $redirectUrl = $booking->getRedirectUrlWithQuery();

        if ($redirectUrl && in_array($order->payment_status, Status::getOrderPaymentSuccessStatuses()) && Arr::get($event, 'is_first_time', false)) {
            add_action('wp_footer', function () use ($redirectUrl) {
                ?>
                <script type="text/javascript">
                    document.addEventListener('DOMContentLoaded', function () {
                        window.location.href = "<?php echo esc_url($redirectUrl); ?>";
                    });
                </script>
                <?php
            });
        }
        ?>
        <style>
            .fcal_receipt_booking_details h5 {
                margin: 0 0 10px;
                border-bottom: 1px solid #dee2e6;
                padding-bottom: 5px;
                font-size: 15px;
                font-weight: 700;
                color: #495057;
            }

            .fcal_receipt_booking_info p {
                margin: 0 0 4px;
                color: #111111;
                font-size: 14px;
            }
        </style>
        <div class="fcal_receipt_booking_details">
            <h5><?php esc_html_e('Booking Details', 'fluent-booking'); ?></h5>
            <div class="fcal_receipt_booking_info">
                <p>
                    <b><?php esc_html_e('Meeting Info:', 'fluent-booking'); ?></b> <?php echo esc_html($booking->getBookingTitle()); ?>
                </p>
                <p>
                    <b><?php esc_html_e('Date & Time:', 'fluent-booking'); ?></b> <?php echo esc_html(implode(', ', $booking->getAllBookingShortTimes($booking->person_time_zone))); ?>
                    (<?php echo esc_html($booking->person_time_zone); ?>)
                </p>
                <p>
                    <b><?php esc_html_e('Status:', 'fluent-booking'); ?></b> <?php echo esc_html(ucfirst($booking->status)); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url($booking->getConfirmationUrl()); ?>"><?php esc_html_e('View Full Meeting Details', 'fluent-booking'); ?></a>
                </p>
            </div>
        </div>
        <?php
    }

    public function pushOrderDataToBookingView($meta, $booking)
    {
        $orderId = $booking->source_id;
        if (!$orderId) {
            return $meta;
        }

        $order = CartHelper::getOrder($orderId);
        if (!$order) {
            return $meta;
        }

        // Get FluentCart Order Summary as html
        ob_start();
        printf(
        /* translators: 1: order number 2: order date 3: order status */
            esc_html__('Order %1$s was placed on %2$s and is currently %3$s.', 'fluent-booking'),
            '<span class="order-number"><a href="' . esc_url($order->getViewUrl('admin')) . '">' . '#' . esc_html($order->id) . '</a></span>',
            '<span class="order-date">' . esc_html(DateTimeHelper::formatToLocale($order->created_at, 'date_time')) . '</span>',
            '<span class="order-status">' . esc_html($order->status) . '</span>'
        );

        (new ThankYouRender(['order' => $order]))->renderOrderItems();

        $orderSummary = ob_get_clean();

        $meta[] = [
            'id'      => 'cart-order-summary',
            'title'   => __('Order Summary', 'fluent-booking'),
            'content' => $orderSummary
        ];

        return $meta;
    }

    private function maybeUpdateChildBookings($booking, $calendarEvent, $order)
    {
        $childBookingIds = Booking::where('parent_id', $booking->id)
            ->where('status', 'pending')
            ->pluck('id')
            ->toArray();

        if (!$childBookingIds) {
            return;
        }

        foreach ($childBookingIds as $childBookingId) {
            $childBooking = Booking::find($childBookingId);

            if (!$childBooking) {
                continue;
            }

            $childBooking->update([
                'status'         => $booking->status,
                'payment_status' => $booking->payment_status,
            ]);

            if ($booking->status == 'scheduled') {
                do_action('fluent_booking/pre_after_booking_scheduled', $childBooking, $calendarEvent, $childBooking);

                $childBooking = Booking::with(['calendar_event', 'calendar'])->find($childBooking->id);

                do_action('fluent_booking/after_booking_scheduled', $childBooking, $calendarEvent, $childBooking);
            }

            do_action('fluent_booking/log_booking_activity', CartHelper::getSuccessLog($childBookingId, $order));
        }
    }
}
