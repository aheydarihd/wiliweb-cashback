<?php

namespace WiliWeb\Cashback\Appearance;

use WiliWeb\Cashback\Settings\Settings;

final class Assets
{
    public static function enqueue(): void
    {
        if (!defined('WWCB_FILE')) {
            return;
        }
        wp_enqueue_style('wwcb', plugins_url('assets/css/frontend.css', WWCB_FILE), [], WWCB_VERSION);
        $font = Settings::get()['font_family'];
        if ($font !== '') {
            wp_add_inline_style('wwcb', '.wwcb{font-family:"'.esc_attr($font).'",sans-serif}');
        } elseif (function_exists('prk_option') && !is_admin()) {
            wp_add_inline_style('wwcb', '.wwcb{font-family:var(--prk-font-family,inherit)}');
        }
        wp_enqueue_script('wwcb', plugins_url('assets/js/frontend.js', WWCB_FILE), ['jquery'], WWCB_VERSION, true);
        wp_localize_script('wwcb', 'wwcbConfig', ['root' => esc_url_raw(rest_url('wwcb/v1/')),'nonce' => wp_create_nonce('wp_rest')]);
    }
    public static function register(): void
    {
        add_action('wp_enqueue_scripts', function () {
            if (is_account_page() || is_product() || is_cart() || is_checkout()) {
                self::enqueue();
            }
        });
    }
}
