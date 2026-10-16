<?php

namespace WiliWeb\Cashback\Settings;

final class Settings
{
    public static function defaults(): array
    {
        return ['rate_bps' => 500,'validity_days' => 30,'min_spend_toman' => 1500000,'round_step_toman' => 500,'reminder_days' => [14,3],
            'font_family' => '','show_product' => 1,'show_cart' => 1,'show_checkout' => 1,'show_popup' => 1,
            'sms_enabled' => 0,'sms_mode' => 'pattern','sms_key' => '','sms_sender' => '','pattern_grant' => '','pattern_reminder' => '',
            'pattern_variables' => 'name=name,amount=amount,balance=balance,expires=expires,order=order',
            'text_grant' => '{name} عزیز، از خرید شماره {order} مبلغ {amount} تومان اعتبار گرفتی. موجودی قابل استفاده: {balance} تومان. اعتبار این خرید تا {expires} معتبر است.',
            'text_reminder' => '{name} عزیز، {amount} تومان اعتبار کیف پولت تا {expires} قابل استفاده است. موجودی قابل استفاده: {balance} تومان.',
            'text_hint' => 'با این خرید {amount} تومان اعتبار خرید بعدی می‌گیری.',
            'currency' => 'IRT'];
    }
    public static function get(): array
    {
        return array_replace(self::defaults(), (array)get_option('wwcb_settings', []));
    }
    public static function rules(): array
    {
        return array_intersect_key(self::get(), array_flip(['rate_bps','validity_days','min_spend_toman','round_step_toman','currency']));
    }
    public static function digits(string $s): string
    {
        return strtr($s, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
    }
    public static function normalize(array $input, array $previous): array
    {
        $out = array_replace(self::defaults(), $previous);
        $rules = ['validity_days' => [1,3650],'min_spend_toman' => [0,100000000000],'round_step_toman' => [1,100000000]];
        foreach ($rules as $key => [$min,$max]) {
            if (array_key_exists($key, $input)) {
                $v = self::digits(trim((string)$input[$key]));
                if (!preg_match('/^\d+$/D', $v) || (float)$v < $min || (float)$v > $max) {
                    throw new \InvalidArgumentException('مقدار «'.$key.'» نامعتبر است.');
                }
                $out[$key] = (int)$v;
            }
        }
        if (isset($input['rate'])) {
            $v = self::digits(trim((string)$input['rate']));
            if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $v) || (float)$v > 100) {
                throw new \InvalidArgumentException('درصد باید بین صفر و صد باشد.');
            }
            $out['rate_bps'] = (int)round((float)$v * 100);
        }
        if (array_key_exists('reminder_days', $input)) {
            $parts = is_array($input['reminder_days']) ? $input['reminder_days'] : preg_split('/[,،\s]+/u', self::digits(trim((string)$input['reminder_days'])), -1, PREG_SPLIT_NO_EMPTY);
            $days = [];
            foreach ($parts as $d) {
                $d = self::digits(trim((string)$d));
                if (!ctype_digit($d) || (int)$d < 1 || (int)$d >= $out['validity_days'] || in_array((int)$d, $days, true)) {
                    throw new \InvalidArgumentException('روزهای یادآوری باید یکتا، مثبت و کمتر از مدت اعتبار باشند.');
                }
                $days[] = (int)$d;
            }
            rsort($days);
            $out['reminder_days'] = $days;
        }
        foreach ($out['reminder_days'] as $day) {
            if ($day >= $out['validity_days']) {
                throw new \InvalidArgumentException('نوبت یادآوری باید کمتر از مدت اعتبار باشد.');
            }
        }
        if (isset($input['font_family'])) {
            $v = trim((string)$input['font_family']);
            if (strlen($v) > 100 || ($v !== '' && !preg_match('/^[\p{L}\p{N} _-]+$/uD', $v))) {
                throw new \InvalidArgumentException('فقط نام خانواده فونت بارگذاری‌شده در سایت مجاز است.');
            }
            $out['font_family'] = $v;
        }
        foreach (['show_product','show_cart','show_checkout','show_popup','sms_enabled'] as $key) {
            if (isset($input[$key])) {
                $out[$key] = empty($input[$key]) ? 0 : 1;
            }
        }
        foreach (['sms_sender','pattern_grant','pattern_reminder','pattern_variables','text_grant','text_reminder','text_hint'] as $key) {
            if (isset($input[$key])) {
                $out[$key] = trim(strip_tags((string)$input[$key]));
            }
        }
        if (isset($input['sms_mode'])) {
            $out['sms_mode'] = $input['sms_mode'] === 'text' ? 'text' : 'pattern';
        }
        if (!empty($input['clear_key'])) {
            $out['sms_key'] = '';
        } elseif (!empty($input['sms_key'])) {
            $out['sms_key'] = trim((string)$input['sms_key']);
        }
        return $out;
    }
    public static function sanitize($input): array
    {
        try {
            $settings = self::normalize((array)$input, self::get());
        } catch (\InvalidArgumentException $e) {
            add_settings_error('wwcb_settings', 'invalid', $e->getMessage());
            return self::get();
        }
        return $settings;
    }
}
