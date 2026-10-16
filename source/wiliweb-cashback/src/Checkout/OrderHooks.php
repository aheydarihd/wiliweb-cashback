<?php

namespace WiliWeb\Cashback\Checkout;

use WiliWeb\Cashback\Wallet\{Service,Calculator};
use WiliWeb\Cashback\Settings\Settings;
use WiliWeb\Cashback\Log;

final class OrderHooks
{
    private Service $wallet;
    public function __construct(?Service $wallet = null)
    {
        $this->wallet = $wallet ?? new Service();
    }
    public function register(): void
    {
        add_action('woocommerce_checkout_create_order', function ($order) {
            $order->update_meta_data('_wwcb_rules', Settings::rules());
        }, 20);
        add_action('woocommerce_checkout_order_processed', function ($id, $data, $order) {
            $this->created($order);
        }, 20, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [$this,'created'], 20);
        add_action('woocommerce_payment_complete', [$this,'paid'], 20);
        add_action('woocommerce_order_status_completed', [$this,'zeroPaid'], 20);
        add_action('woocommerce_order_status_processing', [$this,'zeroPaid'], 20);
        add_action('woocommerce_order_status_failed', [$this,'failed']);
    }
    public function zeroPaid(int $id): void
    {
        $o = wc_get_order($id);
        if ($o && (float)$o->get_total() === 0.0) {
            $this->paid($id);
        }
    }
    public function created(\WC_Order $order): void
    {
        $amount = 0;
        foreach ($order->get_items('coupon') as $c) {
            if (str_starts_with($c->get_code(), 'wwcb-wallet-')) {
                $amount += Calculator::money($c->get_discount());
            }
        }
        if ($amount) {
            $this->reserveForOrder($order, $amount);
        } elseif (!$order->get_meta('_wwcb_rules')) {
            $order->update_meta_data('_wwcb_rules', Settings::rules());
            $order->save();
        }
    }
    public function reserveForOrder(\WC_Order $order, int $amount): void
    {
        if ($order->get_currency() !== 'IRT' || !Classic::supported()) {
            throw new \DomainException('واحد پول یا مالیات برای مصرف کیف پول پشتیبانی نمی‌شود.');
        }
        if (!$order->get_user_id()) {
            throw new \DomainException('برای استفاده از کیف پول وارد حساب شوید.');
        }
        $rules = $order->get_meta('_wwcb_rules') ?: Settings::rules();
        // Do not persist SMS credentials inside order snapshots.
        $rules = array_intersect_key($rules, array_flip(['rate_bps','validity_days','round_step_toman','min_spend_toman','currency']));
        $items = [];
        foreach ($order->get_items() as $id => $item) {
            $items[$id] = Calculator::money($item->get_total());
        }
        $hasWallet = false;
        foreach ($order->get_items('coupon') as $c) {
            if (str_starts_with($c->get_code(), 'wwcb-wallet-')) {
                $hasWallet = true;
            }
        }
        $base = array_sum($items) - ($hasWallet ? 0 : $amount);
        $before = $base + $amount;
        if ($before < $rules['min_spend_toman'] || $amount > $before) {
            throw new \DomainException('حداقل خرید یا مبلغ اعتبار معتبر نیست.');
        }
        if ($hasWallet) {
            $allocation = [];
            foreach ($order->get_items() as $id => $item) {
                $allocation[$id] = (int)$item->get_meta('_wwcb_wallet_share');
            }
            if (array_sum($allocation) !== $amount) {
                throw new \DomainException('سهم اعتبار اقلام معتبر نیست؛ سبد خرید را دوباره محاسبه کنید.');
            }
        } else {
            $allocation = (new Calculator())->allocate($amount, $items);
        }
        $r = $this->wallet->reserve($order->get_user_id(), $order->get_id(), $amount, 'reserve:'.$order->get_id());
        if ($r['state'] === 'released') {
            throw new \DomainException('رزرو قبلی آزاد شده است؛ سفارش تازه ثبت کنید.');
        }
        $order->update_meta_data('_wwcb_wallet', $amount);
        $order->update_meta_data('_wwcb_base', max(0, $base));
        $order->update_meta_data('_wwcb_token', $r['token']);
        $order->update_meta_data('_wwcb_rules', $rules);
        $order->update_meta_data('_wwcb_items', $allocation);
        $order->save();
        Log::note($order, 'reserved', sprintf('برای این سفارش %s تومان از اعتبار کیف پول رزرو شد. مبنای کش‌بک جدید: %s تومان.', number_format($amount), number_format(max(0, $base))));
        Log::write('wallet_reserved', ['order_id' => $order->get_id(),'user_id' => $order->get_user_id(),'wallet' => $amount]);
    }
    public function paid(int $id): void
    {
        $order = wc_get_order($id);
        if (!$order || !$order->is_paid() || !$order->get_user_id() || $order->get_meta('_wwcb_processed')) {
            return;
        }
        if ($order->get_currency() !== 'IRT' || get_option('woocommerce_calc_taxes') === 'yes') {
            return;
        }
        if (in_array($order->get_payment_method(), ['cod','bacs','cheque'], true) && !$order->get_date_paid()) {
            return;
        }
        try {
            $wallet = (int)$order->get_meta('_wwcb_wallet');
            $token = (string)$order->get_meta('_wwcb_token');
            if ($wallet) {
                $this->wallet->settle($token, 'settle:'.$id);
            }
            $rules = $order->get_meta('_wwcb_rules') ?: Settings::rules();
            $base = $order->get_meta('_wwcb_base');
            if ($base === '') {
                $base = 0;
                foreach ($order->get_items() as $item) {
                    $base += Calculator::money($item->get_total());
                }
            }
            $earned = (new Calculator())->earned((int)$base, (int)$rules['rate_bps'], (int)$rules['round_step_toman']);
            $grantId = 0;
            if ($earned) {
                $paid = $order->get_date_paid();
                $expiry = ($paid ? $paid->getTimestamp() : time()) + (int)$rules['validity_days'] * DAY_IN_SECONDS;
                $grantId = $this->wallet->grant($order->get_user_id(), $id, $earned, $expiry, $rules);
            }
            $order->update_meta_data('_wwcb_earned', $earned);
            $order->update_meta_data('_wwcb_grant', $grantId);
            $order->update_meta_data('_wwcb_processed', 1);
            $order->save();
            Log::note($order, 'paid', sprintf('پرداخت تأیید شد. مصرف کیف پول: %s تومان؛ پرداخت سایر روش‌ها: %s تومان؛ مبنای کش‌بک: %s تومان؛ کش‌بک جدید: %s تومان.', number_format($wallet), number_format((float)$order->get_total()), number_format((float)$base), number_format($earned)));
            Log::write('payment_committed', ['order_id' => $id,'user_id' => $order->get_user_id(),'wallet' => $wallet,'cash' => (int)$order->get_total(),'earned' => $earned]);
            if ($grantId) {
                do_action('wwcb_granted', $grantId);
            }
        } catch (\Throwable $e) {
            Log::write('payment_requires_reconciliation', ['order_id' => $id,'code' => 'wallet_commit_failed'], 'error');
            if ($order->get_meta('_wwcb_wallet')) {
                $order->update_status('on-hold', 'اعتبار کیف پول قابل تسویه نیست؛ بررسی مدیر لازم است.');
            }
            Log::note($order, 'review', 'ثبت/تسویه کیف پول نیاز به بررسی دارد. رویداد در لاگ wiliweb-cashback ثبت شد.');
        }
    }
    public function failed(int $id): void
    {
        $order = wc_get_order($id);
        if ($order && $order->get_meta('_wwcb_token')) {
            $this->wallet->release($order->get_meta('_wwcb_token'), 'failed:'.$id);
            Log::note($order, 'failed', 'پرداخت ناموفق شد؛ رزرو اعتبار آزاد شد و اعتبار جدیدی صادر نشد.');
        }
    }
}
