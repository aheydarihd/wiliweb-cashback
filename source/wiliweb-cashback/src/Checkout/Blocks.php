<?php

namespace WiliWeb\Cashback\Checkout;

final class Blocks
{
    public function register(): void
    {
        $register = static function () {
            if (function_exists('woocommerce_store_api_register_update_callback')) {
                woocommerce_store_api_register_update_callback(['namespace' => 'wwcb','callback' => function ($data) {
                    if (!isset($data['enabled']) || !is_bool($data['enabled'])) {
                        throw new \Exception('درخواست کیف پول نامعتبر است.');
                    }(new Classic())->apply($data['enabled']);
                }]);
            }
        };
        if (did_action('woocommerce_blocks_loaded')) {
            $register();
        } else {
            add_action('woocommerce_blocks_loaded', $register);
        }
        add_filter('render_block', function ($html, $block) {
            if (in_array($block['blockName'] ?? '', ['woocommerce/checkout','woocommerce/cart'], true)) {
                ob_start();
                (new Classic())->render($block['blockName'] === 'woocommerce/cart' ? 'cart' : 'checkout');
                $html = ob_get_clean().$html;
            }return $html;
        }, 10, 2);
    }
}
