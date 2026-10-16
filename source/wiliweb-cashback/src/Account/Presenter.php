<?php

namespace WiliWeb\Cashback\Account;

use WiliWeb\Cashback\Wallet\{Repository,Service};
use WiliWeb\Cashback\Appearance\{Format,Assets};

final class Presenter
{
    public static function wallet(int $user, int $page = 1): array
    {
        $r = new Repository();
        $reservations = $r->rows('SELECT * FROM '.$r->table('operations')." WHERE user_id=%d AND kind='purchase' AND state='reserved' AND expires_at>%d ORDER BY expires_at,id LIMIT 20", [$user,time()]);
        $reservedUntil = null;
        $reservedToman = 0;
        foreach ($reservations as $reservation) {
            $reservedToman += max(0, -(int)$reservation['amount']);
            $expires = (int)$reservation['expires_at'];
            if ($expires > 0 && ($reservedUntil === null || $expires < $reservedUntil)) {
                $reservedUntil = $expires;
            }
        }
        return ['snapshot' => (new Service())->snapshot($user),
            'reserved_toman' => $reservedToman,
            'reserved_until' => $reservedUntil,
            'reservations' => $reservations,
            'grants' => $r->rows('SELECT * FROM '.$r->table('grants')." WHERE user_id=%d AND status='active' AND remaining>0 AND expires_at>%d ORDER BY expires_at,id LIMIT 100", [$user,time()]),
            'history' => $r->rows('SELECT * FROM '.$r->table('operations')." WHERE user_id=%d AND kind!='settlement' ORDER BY id DESC LIMIT 20 OFFSET %d", [$user,(max(1, $page) - 1) * 20]),
            'count' => (int)($r->one('SELECT COUNT(*) AS n FROM '.$r->table('operations')." WHERE user_id=%d AND kind!='settlement'", [$user])['n'] ?? 0)];
    }
    public static function render(int $user, int $page = 1, bool $admin = false): void
    {
        Assets::enqueue();
        $data = self::wallet($user, $page);
        $s = $data['snapshot'];
        echo '<div class="wwcb wwcb-wallet"><header class="wwcb-wallet-head"><div><span class="wwcb-eyebrow">اعتبار خریدهای شما</span><h2>کیف پول</h2><p>اعتبارهای هر خرید، مهلت استفاده خودشون رو دارن.</p></div><div class="wwcb-balance"><span>موجودی قابل استفاده</span><strong>'.esc_html(Format::money($s['available_toman'])).'</strong></div></header>';
        if ($s['debt_toman']) {
            echo '<p class="wwcb-warning" role="status">بدهی ناشی از اعتبار باطل‌شده: <b>'.esc_html(Format::money($s['debt_toman'])).'</b> — از اعتبارهای بعدی کسر می‌شود.</p>';
        }
        if ($s['next_expiry']) {
            echo '<p class="wwcb-next">'.esc_html(Format::money($s['next_expiry']['amount_toman'])).' از اعتبارتان تا <b>'.esc_html(Format::date($s['next_expiry']['expires_at'])).'</b> قابل استفاده است.</p>';
        }
        if ($data['reserved_toman'] > 0 && $data['reserved_until']) {
            $minutes = max(1, (int)ceil(((int)$data['reserved_until'] - time()) / 60));
            echo '<aside class="wwcb-reservation" role="status"><strong>اعتبار در انتظار پرداخت</strong><p>'.esc_html(Format::money($data['reserved_toman'])).' برای تکمیل سفارش موقتاً کنار گذاشته شده و در موجودی قابل استفاده نمایش داده نمی‌شود.</p><small>اگر پرداخت لغو شود یا سفارش تا پایان مهلت رزرو تکمیل نشود، اعتبار خودکار برمی‌گردد؛ مهلت فعلی حدود '.esc_html(number_format($minutes)).' دقیقه دیگر است.</small></aside>';
        }
        echo '<section><h3>اعتبارهای فعال</h3><div class="wwcb-grants">';
        if (!$data['grants']) {
            echo '<p class="wwcb-empty">فعلاً اعتبار قابل استفاده‌ای ندارید. اعتبار خریدهای بعدی اینجا نمایش داده می‌شه.</p>';
        }
        foreach ($data['grants'] as $g) {
            $days = max(0, (int)ceil(((int)$g['expires_at'] - time()) / DAY_IN_SECONDS));
            echo '<article class="wwcb-grant"><span>'.($g['order_id'] ? 'خرید شماره '.esc_html($g['order_id']) : 'اعتبار ثبت‌شده توسط مدیر').'</span><strong>'.esc_html(Format::money($g['remaining'])).'</strong><small>'.esc_html('اعتبار اولیه: '.Format::money($g['issued'])).'</small><span>تا '.esc_html(Format::date((int)$g['expires_at'])).'</span><small>'.esc_html($days).' روز باقی‌مانده</small></article>';
        }
        echo '</div></section><section class="wwcb-history"><h3>گردش کیف پول</h3><div class="wwcb-transactions">';
        if (!$data['history']) {
            echo '<p class="wwcb-empty">هنوز تراکنشی ثبت نشده.</p>';
        }
        foreach ($data['history'] as $e) {
            $details = json_decode($e['details'], true) ?: [];
            $amount = (int)$e['amount'];
            $state = ['reserved' => 'در انتظار پرداخت','released' => 'آزاد شده','committed' => 'ثبت‌شده','review_required' => 'نیاز به بررسی'][$e['state']] ?? $e['state'];
            echo '<article class="wwcb-transaction"><div><strong>'.esc_html(Format::label($e['kind'])).'</strong><small>'.esc_html(Format::date((int)$e['created_at'])).' · '.esc_html($state).'</small>';
            if ($e['order_id']) {
                $o = wc_get_order((int)$e['order_id']);
                if ($o) {
                    $url = $admin ? $o->get_edit_order_url() : $o->get_view_order_url();
                    echo '<a href="'.esc_url($url).'">سفارش '.esc_html($o->get_order_number()).'</a>';
                }
            }
            if ($e['reason']) {
                echo '<p>'.esc_html($e['reason']).'</p>';
            }
            if ($admin && $e['actor_id']) {
                $actor = get_userdata((int)$e['actor_id']);
                echo '<small>مدیر: '.esc_html($actor ? $actor->display_name : 'شناسه '.$e['actor_id']).'</small>';
            }
            if ($e['kind'] === 'reversal') {
                echo '<small>'.esc_html('ابطال: '.Format::money($details['revoked_toman'] ?? 0).'؛ برگشت منقضی: '.Format::money($details['expired_toman'] ?? 0).'؛ بدهی: '.Format::money($details['debt_toman'] ?? 0)).'</small>';
            }
            echo '</div><b class="'.($amount < 0 ? 'wwcb-negative' : 'wwcb-positive').'">'.($amount > 0 ? '+' : '').esc_html(Format::money($amount)).'</b></article>';
        }
        echo '</div>';
        if ($data['count'] > 20) {
            echo '<nav aria-label="صفحه‌های گردش کیف پول">'.wp_kses_post(paginate_links(['base' => add_query_arg('wwcb_page', '%#%'),'format' => '','current' => max(1, $page),'total' => (int)ceil($data['count'] / 20)])).'</nav>';
        }
        echo '</section></div>';
    }
}
