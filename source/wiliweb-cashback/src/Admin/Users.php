<?php

namespace WiliWeb\Cashback\Admin;

use WiliWeb\Cashback\Wallet\{Service,Repository,Calculator};
use WiliWeb\Cashback\Settings\Settings;
use WiliWeb\Cashback\Account\Presenter;
use WiliWeb\Cashback\Appearance\{Assets,Format};
use WiliWeb\Cashback\Log;

final class Users
{
    public static function allowed(int $id): bool
    {
        return current_user_can('manage_woocommerce') && current_user_can('edit_user', $id);
    }
    public static function url(int $id): string
    {
        return add_query_arg(['page' => 'wwcb-user','user_id' => $id], admin_url('users.php'));
    }
    public function register(): void
    {
        add_filter('manage_users_columns', function ($columns) {
            if (current_user_can('manage_woocommerce')) {
                $columns['wwcb_wallet'] = 'اعتبار کیف پول';
            }return $columns;
        });
        add_filter('manage_users_custom_column', [$this,'column'], 10, 3);
        add_action('admin_menu', function () {
            add_users_page('کیف پول کاربر', 'کیف پول کاربران', 'manage_woocommerce', 'wwcb-user', [$this,'page']);
        });
        add_action('show_user_profile', [$this,'profile']);
        add_action('edit_user_profile', [$this,'profile']);
        add_action('admin_post_wwcb_adjust', [$this,'adjust']);
    }
    public function column($value, $column, $id)
    {
        if ($column !== 'wwcb_wallet' || !self::allowed((int)$id)) {
            return $value;
        }
        $r = new Repository();
        $row = $r->one('SELECT COALESCE(SUM(remaining),0) AS total FROM '.$r->table('grants')." WHERE user_id=%d AND status='active' AND expires_at>%d", [$id,time()]);
        return '<a href="'.esc_url(self::url((int)$id)).'">'.esc_html(Format::money($row['total'] ?? 0)).'</a>';
    }
    public function profile($user): void
    {
        if (!self::allowed($user->ID)) {
            return;
        }echo '<h2>کیف پول</h2><p><a class="button" href="'.esc_url(self::url($user->ID)).'">مشاهده اعتبار، تاریخچه و اصلاح موجودی</a></p>';
    }
    public function page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('دسترسی به این بخش مجاز نیست.', 403);
        }
        $id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        if (!$id) {
            $this->picker();
            return;
        }
        if (!self::allowed($id) || !get_userdata($id)) {
            wp_die('دسترسی به این کیف پول مجاز نیست.', 403);
        }
        $user = get_userdata($id);
        echo '<div class="wrap"><h1>کیف پول '.esc_html($user->display_name).'</h1>';
        if (isset($_GET['wwcb_saved'])) {
            echo '<div class="notice notice-success"><p>اصلاح اعتبار ثبت شد.</p></div>';
        }
        if (isset($_GET['wwcb_error'])) {
            echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['wwcb_error']))).'</p></div>';
        }
        Presenter::render($id, isset($_GET['wwcb_page']) ? absint($_GET['wwcb_page']) : 1, true);
        echo '<div class="wwcb wwcb-admin-adjust"><h2>افزایش یا کاهش دستی اعتبار</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('wwcb_adjust_'.$id);
        echo '<input type="hidden" name="action" value="wwcb_adjust"><input type="hidden" name="user_id" value="'.esc_attr($id).'"><input type="hidden" name="event_key" value="'.esc_attr(wp_generate_uuid4()).'">';
        echo '<label>نوع تغییر <select name="direction"><option value="credit">افزایش اعتبار</option><option value="debit">کاهش اعتبار</option></select></label><label>مبلغ (تومان) <input name="amount" type="number" min="1" max="100000000000" required></label><label>مدت اعتبار جدید (روز) <input name="days" type="number" min="1" max="3650" value="'.esc_attr(Settings::get()['validity_days']).'"> <small>فقط برای افزایش؛ هر اعتبار تاریخ انقضای مستقل دارد.</small></label><label>دلیل تغییر <textarea name="reason" maxlength="500" required></textarea></label>';
        submit_button('ثبت تغییر اعتبار');
        echo '</form><p>کاهش اعتبار فقط از موجودی قابل استفاده انجام می‌شود. تراکنش و نام مدیر در تاریخچه ثبت خواهند شد.</p></div></div>';
    }
    private function picker(): void
    {
        $search = isset($_GET['wwcb_search']) && is_string($_GET['wwcb_search']) ? sanitize_text_field(wp_unslash($_GET['wwcb_search'])) : '';
        echo '<div class="wrap"><h1>کیف پول کاربران</h1><p>کاربر را پیدا کنید و «مشاهده کیف پول» را بزنید تا موجودی، تاریخچه و فرم اصلاح اعتبار نمایش داده شود.</p><form method="get" action="'.esc_url(admin_url('users.php')).'"><input type="hidden" name="page" value="wwcb-user"><label for="wwcb-user-search"><strong>جست‌وجوی کاربر</strong></label><p><input class="regular-text" id="wwcb-user-search" name="wwcb_search" type="search" value="'.esc_attr($search).'" placeholder="نام، نام کاربری، ایمیل یا شناسه کاربر"> <button class="button button-primary" type="submit">جست‌وجو</button></p></form>';
        if ($search !== '') {
            $digits = Settings::digits($search);
            $args = ['number' => 20,'orderby' => 'display_name','order' => 'ASC','search' => '*'.$search.'*','search_columns' => ['user_login','user_email','display_name']];
            if (ctype_digit($digits)) {
                $args = ['number' => 1,'include' => [(int)$digits]];
            }
            $users = array_filter(get_users($args), static fn ($user) => self::allowed((int)$user->ID));
            if (!$users) {
                echo '<div class="notice notice-info inline"><p>کاربری پیدا نشد. نام، نام کاربری، ایمیل یا شناسه را بررسی کنید.</p></div>';
            } else {
                echo '<table class="widefat striped"><thead><tr><th>کاربر</th><th>نام کاربری</th><th>ایمیل</th><th>کیف پول</th></tr></thead><tbody>';
                foreach ($users as $user) {
                    echo '<tr><td>'.esc_html($user->display_name).' <small>(#'.esc_html($user->ID).')</small></td><td>'.esc_html($user->user_login).'</td><td>'.esc_html($user->user_email).'</td><td><a class="button" href="'.esc_url(self::url((int)$user->ID)).'">مشاهده کیف پول</a></td></tr>';
                }
                echo '</tbody></table><p class="description">حداکثر ۲۰ نتیجه نمایش داده می‌شود؛ برای نتیجه دقیق‌تر، عبارت کامل‌تری وارد کنید.</p>';
            }
        }
        echo '<p><a href="'.esc_url(admin_url('users.php')).'">رفتن به فهرست همه کاربران</a></p></div>';
    }
    public function adjust(): void
    {
        $id = absint($_POST['user_id'] ?? 0);
        if (!self::allowed($id) || !get_userdata($id)) {
            wp_die('دسترسی غیرمجاز', 403);
        }
        check_admin_referer('wwcb_adjust_'.$id);
        try {
            $raw = Settings::digits(trim((string)wp_unslash($_POST['amount'] ?? '')));
            $amount = Calculator::money($raw);
            $direction = sanitize_key($_POST['direction'] ?? '');
            if (!in_array($direction, ['credit','debit'], true)) {
                throw new \DomainException('نوع تغییر نامعتبر است.');
            }
            $days = Settings::digits((string)($_POST['days'] ?? ''));
            if (!ctype_digit($days) || (int)$days < 1 || (int)$days > 3650) {
                throw new \DomainException('مدت اعتبار نامعتبر است.');
            }
            $key = sanitize_text_field(wp_unslash($_POST['event_key'] ?? ''));
            if (!preg_match('/^[a-f0-9-]{36}$/D', $key)) {
                throw new \DomainException('شناسه درخواست نامعتبر است.');
            }
            $reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
            if (mb_strlen($reason) > 500) {
                throw new \DomainException('دلیل بیش از حد طولانی است.');
            }
            $signed = $direction === 'debit' ? -$amount : $amount;
            (new Service())->adjust($id, $signed, $reason, get_current_user_id(), time() + (int)$days * DAY_IN_SECONDS, 'admin:'.$key);
            Log::write('admin_adjustment', ['user_id' => $id,'actor_id' => get_current_user_id(),'amount' => $signed]);
            $url = add_query_arg('wwcb_saved', 1, self::url($id));
        } catch (\DomainException|\InvalidArgumentException $e) {
            $url = add_query_arg('wwcb_error', $e->getMessage(), self::url($id));
        } catch (\Throwable $e) {
            Log::write('admin_adjustment_failed', ['user_id' => $id,'code' => 'storage_failure'], 'error');
            $url = add_query_arg('wwcb_error', 'تغییر ثبت نشد؛ لاگ ووکامرس را بررسی کنید.', self::url($id));
        }
        wp_safe_redirect($url);
        exit;
    }
}
