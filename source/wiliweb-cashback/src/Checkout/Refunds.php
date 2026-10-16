<?php

namespace WiliWeb\Cashback\Checkout;

use WiliWeb\Cashback\Wallet\Service;
use WiliWeb\Cashback\Log;

final class Refunds
{
    private Service $wallet;
    public function __construct(?Service $wallet = null)
    {
        $this->wallet = $wallet ?? new Service();
    }
    public function register(): void
    {
        add_action('woocommerce_order_status_cancelled', [$this,'cancelled']);
        add_action('woocommerce_order_status_refunded', [$this,'cancelled'], 20);
        add_action('woocommerce_order_refunded', [$this,'refunded'], 10, 2);
    }
    public function cancelled(int $id): void
    {
        $o = wc_get_order($id);
        if (!$o) {
            return;
        }
        if ($o->get_meta('_wwcb_token')) {
            $this->wallet->release($o->get_meta('_wwcb_token'), 'cancel-release:'.$id);
        }
        $this->apply($o, 'cancel:'.$id, (int)$o->get_meta('_wwcb_wallet'));
    }
    public function refunded(int $id, int $refundId): void
    {
        $o = wc_get_order($id);
        $refund = wc_get_order($refundId);
        if (!$o || !$refund) {
            return;
        }
        $allocation = $o->get_meta('_wwcb_items');
        $allocation = is_array($allocation) ? $allocation : [];
        // Cumulative fractions avoid rounding the same item up repeatedly across refunds.
        $before = [];
        $after = [];
        $cashBase = 0;
        foreach ($o->get_items() as $item) {
            $cashBase += (float)$item->get_total();
        }
        $refunds = $o->get_refunds();
        usort($refunds, static fn ($a, $b) => $a->get_id() <=> $b->get_id());
        foreach ($refunds as $prior) {
            if ($prior->get_id() > $refundId) {
                continue;
            }
            $parts = [];
            $lines = $prior->get_items();
            if (!$lines && $cashBase > 0) {
                foreach ($allocation as $itemId => $credit) {
                    $parts[$itemId] = abs((float)$prior->get_amount()) / $cashBase;
                }
            }
            foreach ($lines as $line) {
                $itemId = (int)$line->get_meta('_refunded_item_id');
                $source = $o->get_item($itemId);
                if (!$source || empty($allocation[$itemId])) {
                    continue;
                }
                $qty = abs((float)$line->get_quantity());
                $cash = (float)$source->get_total();
                $parts[$itemId] = ($parts[$itemId] ?? 0) + ($qty > 0 ? $qty / max(1, (float)$source->get_quantity()) : ($cash > 0 ? abs((float)$line->get_total()) / $cash : 0));
            }
            foreach ($parts as $itemId => $fraction) {
                $after[$itemId] = min(1, ($after[$itemId] ?? 0) + $fraction);
                if ($prior->get_id() < $refundId) {
                    $before[$itemId] = $after[$itemId];
                }
            }
        }
        $amount = 0;
        foreach ($allocation as $itemId => $credit) {
            $amount += max(0, (int)round($credit * ($after[$itemId] ?? 0)) - (int)round($credit * ($before[$itemId] ?? 0)));
        }
        if ($o->has_status('refunded')) {
            $amount = (int)$o->get_meta('_wwcb_wallet');
        }
        $this->apply($o, 'refund:'.$refundId, $amount);
    }
    private function apply(\WC_Order $o, string $key, int $amount): void
    {
        $result = $this->wallet->reverse($o->get_id(), $key, ['total' => $amount], true);
        Log::note($o, str_replace(':', '_', $key), sprintf('لغو/مرجوعی: کش‌بک باطل‌شده %s تومان؛ اعتبار برگشتیِ معتبر %s تومان؛ اعتبار برگشتیِ منقضی %s تومان؛ بدهی فعلی کیف پول %s تومان.', number_format($result['revoked_toman']), number_format($result['restored_toman']), number_format($result['expired_toman']), number_format($result['debt_toman'])));
        Log::write('wallet_reversed', ['order_id' => $o->get_id(),'user_id' => $o->get_user_id(),'restored' => $result['restored_toman'],'expired' => $result['expired_toman'],'revoked' => $result['revoked_toman'],'debt' => $result['debt_toman']]);
    }
}
