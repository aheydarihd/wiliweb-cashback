<?php

namespace WiliWeb\Cashback;

final class Plugin
{
    public function boot(): void
    {
        (new Updates\GitHub())->register();
        if (get_option('wwcb_schema') !== '1') {
            Install\Schema::migrate();
        }
        (new Settings\AdminPage())->register();
        (new Admin\Users())->register();
        (new Checkout\Classic())->register();
        (new Checkout\OrderHooks())->register();
        (new Checkout\Refunds())->register();
        (new Checkout\Blocks())->register();
        (new Account\Controller())->register();
        (new Compatibility\ParsKala())->register();
        Appearance\Assets::register();
        (new Sms\Outbox())->register();
        add_action('init', function () {
            load_plugin_textdomain('wiliweb-cashback', false, dirname(plugin_basename(WWCB_FILE)).'/languages');
        });
        add_action('init', function () {
            if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_recurring_action')) {
                if (!as_has_scheduled_action('wwcb_tick', [], 'wwcb')) {
                    as_schedule_recurring_action(time() + 60, 300, 'wwcb_tick', [], 'wwcb');
                }
            } elseif (!wp_next_scheduled('wwcb_tick')) {
                wp_schedule_event(time() + 60, 'hourly', 'wwcb_tick');
            }
        }, 30);
        add_filter('woocommerce_currencies', function ($currencies) {
            $currencies['IRT'] = 'تومان';
            return $currencies;
        });
        add_filter('woocommerce_currency_symbol', function ($symbol, $currency) {
            return $currency === 'IRT' ? 'تومان' : $symbol;
        }, 10, 2);
    }
}
