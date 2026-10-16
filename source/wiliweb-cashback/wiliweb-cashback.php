<?php

/**
 * Plugin Name: WiliWeb Cashback | کش‌بک و کیف پول
 * Description: کش‌بک ووکامرس با انقضای مستقل، گزارش کیف پول، مدیریت اعتبار کاربران و پیامک IPPanel.
 * Version: 0.1.6
 * Update URI: https://github.com/aheydarihd/wiliweb-cashback
 * Author: WiliWeb
 * Author URI: https://wiliweb.com
 * Plugin URI: https://wiliweb.com
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: wiliweb-cashback
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
define('WWCB_VERSION', '0.1.6');
define('WWCB_FILE', __FILE__);
define('WWCB_DIR', __DIR__.'/');
add_action('before_woocommerce_init', static function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});
spl_autoload_register(static function ($class) {
    $prefix = 'WiliWeb\\Cashback\\';
    if (str_starts_with($class, $prefix)) {
        $path = WWCB_DIR.'src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});
register_activation_hook(__FILE__, static function ($network) {
    if ($network) {
        wp_die('کیف پول را برای هر سایت جداگانه فعال کنید؛ فعال‌سازی شبکه پشتیبانی نمی‌شود.');
    }if (!class_exists('WooCommerce')) {
        wp_die('ابتدا ووکامرس را فعال کنید.');
    }\WiliWeb\Cashback\Install\Schema::migrate();
    add_rewrite_endpoint('wwcb-wallet', EP_ROOT | EP_PAGES);
    flush_rewrite_rules(false);
});
register_deactivation_hook(__FILE__, static function () {
    wp_clear_scheduled_hook('wwcb_tick');
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('wwcb_tick', [], 'wwcb');
    }flush_rewrite_rules(false);
});
add_action('plugins_loaded', static function () {
    if (class_exists('WooCommerce')) {
        (new \WiliWeb\Cashback\Plugin())->boot();
    } else {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p>کش‌بک ویلی‌وب به ووکامرس نیاز دارد.</p></div>';
        });
    }
}, 20);

