<?php

namespace WiliWeb\Cashback\Compatibility;

final class ParsKala
{
    public function register(): void
    {
        // Theme shortcodes can bypass cart hooks or run outside the assigned WooCommerce pages.
        add_filter('do_shortcode_tag', static function ($output, $tag) {
            if (!in_array($tag, ['prk_cart4','prk_checkout_v4'], true)) {
                return $output;
            }
            ob_start();
            (new \WiliWeb\Cashback\Checkout\Classic())->render($tag === 'prk_cart4' ? 'cart' : 'checkout');
            return ob_get_clean().$output;
        }, 10, 2);
        add_filter('prk_myaccount_v4_menu_items', function ($items) {
            $items['wwcb-wallet'] = ['slug' => 'wwcb-wallet','title' => 'کیف پول','type' => 'woocommerce_endpoint','priority' => 25,'enabled' => true,'desktop' => true,'mobile' => true,'endpoint_register' => true,'icon' => 'ri-wallet-3-line'];
            return $items;
        });
    }
}
