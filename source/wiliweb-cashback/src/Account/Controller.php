<?php

namespace WiliWeb\Cashback\Account;

use WiliWeb\Cashback\Appearance\Assets;
use WiliWeb\Cashback\Settings\Settings;
use WiliWeb\Cashback\Checkout\Classic;

final class Controller
{
    public function register(): void
    {
        add_action('init', function () {
            add_rewrite_endpoint('wwcb-wallet', EP_ROOT | EP_PAGES);
        });
        add_filter('woocommerce_account_menu_items', function ($items) {
            $logout = $items['customer-logout'] ?? null;
            unset($items['customer-logout']);
            $items['wwcb-wallet'] = 'کیف پول';
            if ($logout) {
                $items['customer-logout'] = $logout;
            }return $items;
        });
        add_action('woocommerce_account_wwcb-wallet_endpoint', [$this,'render']);
        add_shortcode('wwcb_wallet', function () {
            ob_start();
            $this->render();
            return ob_get_clean();
        });
        add_shortcode('wwcb_cashback', function () {
            ob_start();
            $this->hint();
            return ob_get_clean();
        });
        add_action('woocommerce_single_product_summary', [$this,'hint'], 35);
        add_action('rest_api_init', [$this,'routes']);
        add_action('set_logged_in_cookie', function ($cookie, $expire, $expiration, $uid, $scheme, $token) {
            $list = (array)get_user_meta($uid, 'wwcb_popup_sessions', true);
            $list[hash('sha256', $token)] = time();
            update_user_meta($uid, 'wwcb_popup_sessions', array_slice($list, -10, null, true));
        }, 10, 6);
        add_action('wp_footer', function () {
            if (is_user_logged_in() && is_account_page() && Settings::get()['show_popup']) {
                echo '<dialog class="wwcb wwcb-dialog" data-wwcb-dialog aria-labelledby="wwcb-dialog-title"><button type="button" data-wwcb-close aria-label="بستن پیام کیف پول">×</button><h2 id="wwcb-dialog-title">اعتبار خرید بعدی شما</h2><p data-wwcb-dialog-text></p><a class="button wwcb-action" href="'.esc_url(wc_get_account_endpoint_url('wwcb-wallet')).'">مشاهده کیف پول</a></dialog>';
            }
        });
    }
    public function render(): void
    {
        if (!is_user_logged_in()) {
            echo '<p>برای مشاهده کیف پول وارد حساب کاربری شوید.</p>';
            return;
        }nocache_headers();
        Presenter::render(get_current_user_id(), isset($_GET['wwcb_page']) ? absint($_GET['wwcb_page']) : 1);
    }
    public function hint(): void
    {
        global $product;
        $s = Settings::get();
        if (!$s['show_product'] || !$product instanceof \WC_Product || !Classic::supported()) {
            return;
        }
        static $shown = [];
        if (isset($shown[$product->get_id()])) {
            return;
        }$shown[$product->get_id()] = true;
        Assets::enqueue();
        $price = (float)$product->get_price();
        $amount = (new \WiliWeb\Cashback\Wallet\Calculator())->earned((int)round($price), $s['rate_bps'], $s['round_step_toman']);
        echo '<div class="wwcb wwcb-product-hint" data-wwcb-hint data-price="'.esc_attr($price).'" data-rate="'.esc_attr($s['rate_bps']).'" data-step="'.esc_attr($s['round_step_toman']).'" data-copy="'.esc_attr($s['text_hint']).'"><strong data-wwcb-hint-text>'.esc_html(str_replace('{amount}', number_format($amount), $s['text_hint'])).'</strong><small>مبلغ نهایی بعد از تخفیف و استفاده از کیف پول محاسبه می‌شود.</small></div>';
    }
    private function cart(): void
    {
        if (function_exists('wc_load_cart') && (!WC()->cart || !WC()->session)) {
            wc_load_cart();
        }
        // REST can create the cart after wp_loaded: explicitly hydrate its saved session.
        if (WC()->cart) {
            WC()->cart->get_cart();
            WC()->cart->calculate_totals();
        }
    }
    public function routes(): void
    {
        $permission = static fn () => is_user_logged_in();
        register_rest_route('wwcb/v1', '/quote', ['methods' => 'GET','permission_callback' => '__return_true','callback' => function () {
            $this->cart();
            $response = new \WP_REST_Response((new Classic())->quote());
            $response->header('Cache-Control', 'private, no-store');
            return $response;
        }]);
        register_rest_route('wwcb/v1', '/wallet', ['methods' => 'GET','permission_callback' => $permission,'callback' => function () {
            $this->cart();
            $data = Presenter::wallet(get_current_user_id());
            $data['quote'] = (new Classic())->quote();
            $token = hash('sha256', wp_get_session_token());
            $sessions = (array)get_user_meta(get_current_user_id(), 'wwcb_popup_sessions', true);
            $data['popup'] = Settings::get()['show_popup'] && isset($sessions[$token]) && $data['snapshot']['available_toman'] > 0;
            $data['expiry_text'] = $data['snapshot']['next_expiry'] ? \WiliWeb\Cashback\Appearance\Format::date($data['snapshot']['next_expiry']['expires_at']) : '';
            $response = new \WP_REST_Response($data);
            $response->header('Cache-Control', 'private, no-store');
            return $response;
        }]);
        register_rest_route('wwcb/v1', '/apply', ['methods' => 'POST','permission_callback' => $permission,'callback' => function ($request) {
            $this->cart();
            try {
                $enabled = $request->get_param('enabled');
                if (!is_bool($enabled)) {
                    return new \WP_Error('invalid', 'درخواست نامعتبر است.', ['status' => 400]);
                }$response = new \WP_REST_Response((new Classic())->apply($enabled));
                $response->header('Cache-Control', 'private, no-store');
                return $response;
            } catch (\Throwable $e) {
                return new \WP_Error('wallet_unavailable', 'اعتبار اعمال نشد؛ موجودی و مبلغ خرید را بررسی کنید.', ['status' => 409]);
            }
        }]);
        register_rest_route('wwcb/v1', '/popup', ['methods' => 'POST','permission_callback' => $permission,'callback' => function () {
            $key = hash('sha256', wp_get_session_token());
            $list = (array)get_user_meta(get_current_user_id(), 'wwcb_popup_sessions', true);
            unset($list[$key]);
            update_user_meta(get_current_user_id(), 'wwcb_popup_sessions', $list);
            return ['ok' => true];
        }]);
    }
}
