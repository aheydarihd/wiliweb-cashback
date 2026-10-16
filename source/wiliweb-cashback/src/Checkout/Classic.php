<?php

namespace WiliWeb\Cashback\Checkout;

use WiliWeb\Cashback\Settings\Settings;
use WiliWeb\Cashback\Wallet\{Calculator,Service};

final class Classic
{
    private bool $syncing = false;
    private static bool $rendered = false;
    public static function supported(): bool
    {
        return get_woocommerce_currency() === 'IRT' && get_option('woocommerce_calc_taxes') !== 'yes';
    }
    public static function code(): string
    {
        return 'wwcb-wallet-'.get_current_user_id();
    }
    public function register(): void
    {
        add_filter('woocommerce_get_shop_coupon_data', [$this,'coupon'], 10, 2);
        add_filter('woocommerce_coupon_is_valid', [$this,'valid'], 10, 2);
        add_filter('woocommerce_cart_totals_coupon_label', function ($label, $coupon) {
            return str_starts_with($coupon->get_code(), 'wwcb-wallet-') ? 'اعتبار کیف پول' : $label;
        }, 10, 2);
        add_action('woocommerce_after_calculate_totals', [$this,'sync'], 90);
        add_filter('woocommerce_coupon_sort', static function ($priority, $coupon) {
            return str_starts_with($coupon->get_code(), 'wwcb-wallet-') ? PHP_INT_MAX : $priority;
        }, 99, 2);
        add_action('woocommerce_removed_coupon', static function ($code) {
            if ($code === self::code() && WC()->session) {
                WC()->session->set('wwcb_enabled', false);
            }
        });
        add_action('woocommerce_checkout_create_order_line_item', static function ($item, $key) {
            $shares = (array)WC()->session->get('wwcb_line_shares', []);
            $item->add_meta_data('_wwcb_wallet_share', (int)($shares[$key] ?? 0), true);
        }, 10, 2);
        // ParsKala hides the legacy totals/payment layouts; keep the notice outside them.
        add_action('woocommerce_before_checkout_form', [$this,'render'], 8, 0);
        add_action('woocommerce_before_cart', [$this,'render'], 5, 0);
        add_action('woocommerce_check_cart_items', function () {
            if (WC()->cart && WC()->cart->has_discount(self::code()) && !self::supported()) {
                wc_add_notice('واحد پول یا مالیات برای مصرف کیف پول پشتیبانی نمی‌شود.', 'error');
            }
        });
    }
    public function coupon($data, $code)
    {
        if (!is_string($code) || $code !== self::code() || !is_user_logged_in() || !WC()->session || !WC()->session->get('wwcb_enabled') || !self::supported()) {
            return $data;
        }
        return ['discount_type' => 'fixed_cart','amount' => (int)WC()->session->get('wwcb_amount', 0),'individual_use' => false,'usage_limit' => 0,'virtual' => true];
    }
    public function valid($valid, $coupon)
    {
        if (str_starts_with($coupon->get_code(), 'wwcb-wallet-') && ($coupon->get_code() !== self::code() || !is_user_logged_in() || !WC()->session || !WC()->session->get('wwcb_enabled') || !self::supported())) {
            return false;
        }return $valid;
    }
    public function quote(): array
    {
        $s = Settings::get();
        $available = is_user_logged_in() ? (new Service())->snapshot(get_current_user_id())['available_toman'] : 0;
        $cart = WC()->cart;
        $applied = $cart ? (int)round($cart->get_coupon_discount_amount(self::code())) : 0;
        $base = $cart ? (int)round($cart->get_cart_contents_total()) + $applied : 0;
        $eligible = $base >= $s['min_spend_toman'] && self::supported();
        $usable = $eligible ? min($base, $available) : 0;
        return ['available' => $available,'base' => $base,'usable' => $usable,'applied' => $applied,'earned' => (new Calculator())->earned(max(0, $base - $applied), $s['rate_bps'], $s['round_step_toman']),'minimum' => $s['min_spend_toman'],'enabled' => WC()->session ? (bool)WC()->session->get('wwcb_enabled') : false,'supported' => self::supported()];
    }
    public function sync($cart): void
    {
        if ($this->syncing || !WC()->session || !WC()->session->get('wwcb_enabled')) {
            return;
        }
        $this->syncing = true;
        try {
            // Measure WooCommerce's actual per-line discount instead of inventing a proportional split.
            $codes = array_values(array_filter($cart->get_applied_coupons(), static fn ($code) => !str_starts_with($code, 'wwcb-wallet-')));
            $cart->set_applied_coupons($codes);
            $cart->calculate_totals();
            $before = [];
            foreach ($cart->get_cart() as $key => $line) {
                $before[$key] = (int)round($line['line_total']);
            }
            $wanted = $this->quote()['usable'];
            WC()->session->set('wwcb_amount', $wanted);
            if ($wanted > 0) {
                $codes[] = self::code();
                $cart->set_applied_coupons($codes);
                $cart->calculate_totals();
            }
            $shares = [];
            foreach ($cart->get_cart() as $key => $line) {
                $shares[$key] = max(0, $before[$key] - (int)round($line['line_total']));
            }
            WC()->session->set('wwcb_line_shares', $shares);
        } finally {
            $this->syncing = false;
        }
    }
    public function apply(bool $enabled, int $revision = 0): array
    {
        if (!is_user_logged_in() || !WC()->session || !WC()->cart) {
            throw new \DomainException('ابتدا وارد حساب کاربری شوید.');
        }
        WC()->session->set('wwcb_enabled', $enabled);
        if (!$enabled) {
            WC()->cart->remove_coupon(self::code());
            WC()->session->set('wwcb_amount', 0);
            WC()->session->set('wwcb_line_shares', []);
        }
        WC()->cart->calculate_totals();
        return $this->quote();
    }
    public function render(string $context = ''): void
    {
        $s = Settings::get();
        $context = $context ?: (is_checkout() ? 'checkout' : (is_cart() ? 'cart' : ''));
        if (!in_array($context, ['cart','checkout'], true) || !$s['show_'.$context] || !self::supported() || !WC()->cart || WC()->cart->is_empty() || is_order_received_page() || is_wc_endpoint_url('order-pay') || self::$rendered) {
            return;
        }
        self::$rendered = true;
        \WiliWeb\Cashback\Appearance\Assets::enqueue();
        $q = $this->quote();
        $guest = !is_user_logged_in();
        echo '<section class="wwcb wwcb-credit wwcb-credit--compact" data-wwcb-credit data-wwcb-guest="'.($guest ? '1' : '0').'"><span class="wwcb-credit-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="23" height="23" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 8H5a2 2 0 0 1 0-4h13v4M3 6v12a2 2 0 0 0 2 2h15V8"/><path d="M20 12h-4a2 2 0 0 0 0 4h4"/><path d="M16 14h.01"/></svg></span><div class="wwcb-credit-copy"><p class="wwcb-estimate" aria-live="polite"><span data-wwcb-estimate-label>با این خرید </span><b>'.esc_html(number_format($q['earned'])).' تومان</b><span data-wwcb-estimate-suffix> اعتبار می‌گیری</span></p><details class="wwcb-credit-details"><summary>مهلت استفاده '.esc_html((string)$s['validity_days']).' روز <span aria-hidden="true">·</span> شرایط استفاده<svg aria-hidden="true" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m4 6 4 4 4-4"/></svg></summary><div>اعتبار پس از پرداخت موفق ثبت می‌شود و برای خرید بعدی است. مبلغ نهایی پس از تخفیف و کسر کیف پول محاسبه می‌شود.<br>حداقل خرید برای استفاده از کیف پول: '.esc_html(number_format($q['minimum'])).' تومان.</div></details></div><div class="wwcb-credit-wallet">';
        if ($guest) {
            echo '<p data-wwcb-status>برای دریافت اعتبار وارد شو</p><a class="wwcb-action" data-wwcb-login href="'.esc_url(wc_get_page_permalink('myaccount')).'">ورود به حساب</a>';
        } else {
            echo '<p data-wwcb-status aria-live="polite">'.esc_html($q['available'] ? 'موجودی کیف پول: '.number_format($q['available']).' تومان' : 'کیف پولت فعلاً خالیه').'</p><button type="button" class="wwcb-action" data-wwcb-apply="'.($q['enabled'] ? '0' : '1').'" '.disabled(!$q['enabled'] && !$q['usable'], true, false).' '.(!$q['available'] && !$q['enabled'] ? 'hidden' : '').'>'.($q['enabled'] ? ($q['applied'] ? 'حذف اعتبار' : 'لغو استفاده') : 'استفاده از اعتبار').'</button>';
        }
        echo '</div></section>';
    }
}
