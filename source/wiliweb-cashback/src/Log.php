<?php

namespace WiliWeb\Cashback;

final class Log
{
    public static function write(string $event, array $context = [], string $level = 'info'): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        $safe = array_intersect_key($context, array_flip(['order_id','user_id','actor_id','amount','earned','wallet','cash','event_id','code','state','restored','expired','revoked','debt']));
        wc_get_logger()->log($level, $event, array_merge(['source' => 'wiliweb-cashback'], $safe));
    }
    public static function note(\WC_Order $order, string $key, string $text): void
    {
        if ($order->get_meta('_wwcb_note_'.$key)) {
            return;
        }
        $order->add_order_note('کیف پول ویلی‌وب — '.$text, false);
        $order->update_meta_data('_wwcb_note_'.$key, 1);
        $order->save_meta_data();
    }
}
