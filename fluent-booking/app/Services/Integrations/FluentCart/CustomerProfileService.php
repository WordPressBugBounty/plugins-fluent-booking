<?php

namespace FluentBooking\App\Services\Integrations\FluentCart;

use FluentBooking\App\Services\DateTimeHelper;

/**
 * Read a FluentCart customer's profile (lifetime value, orders, status) for the
 * booking details Customer card. All methods no-op (return null) when FluentCart
 * is inactive or the booking email has no matching customer.
 */
class CustomerProfileService
{
    public static function isActive()
    {
        return defined('FLUENTCART_VERSION');
    }

    // customers/view is Cart Pro; on free only WP admins pass (FluentCart's own gate).
    public static function canView()
    {
        return self::isActive()
            && \FluentCart\App\Services\Permission\PermissionManager::userCan('customers/view');
    }

    public static function getProfileData($email)
    {
        if (!self::isActive() || !$email) {
            return null;
        }

        $customer = \FluentCart\App\Models\Customer::where('email', $email)->first();

        if (!$customer) {
            return null;
        }

        $currency = \FluentCart\Api\CurrencySettings::get('currency');

        return [
            'full_name'   => $customer->full_name,
            'status'      => $customer->status,
            'profile_url' => self::adminCustomerUrl($customer->id),
            'stats'       => [
                'ltv'      => self::formatMoney($customer->ltv, $currency),
                'orders'   => (int) $customer->purchase_count,
                'aov'      => self::formatMoney($customer->aov, $currency),
                'last_buy' => $customer->last_purchase_date
                    ? DateTimeHelper::formatToLocale($customer->last_purchase_date, 'date')
                    : '',
            ],
        ];
    }

    /**
     * Format a cents amount to a currency string. centsToDecimal handles
     * zero-decimal currencies (JPY, KRW); the sign is an HTML entity rendered
     * via v-html, so the result is sanitized server-side.
     */
    private static function formatMoney($cents, $currency)
    {
        $sign = \FluentCart\App\Helpers\CurrenciesHelper::getCurrencySign($currency);

        return wp_kses_post($sign . \FluentCart\App\Helpers\CurrenciesHelper::centsToDecimal($cents, $currency));
    }

    private static function adminCustomerUrl($id)
    {
        $base = apply_filters('fluent_cart/admin_base_url', admin_url('admin.php?page=fluent-cart#/'), []);

        return $base . 'customers/' . (int) $id . '/view';
    }
}
