<?php

namespace WiliWeb\Cashback\Appearance;

final class Format
{
    public static function money($amount): string
    {
        return number_format((int)$amount).' تومان';
    }
    public static function date(int $stamp): string
    {
        if (class_exists('IntlDateFormatter')) {
            // ICU rejects WordPress numeric zones such as +03:30. Project the local
            // wall clock into UTC, preserving the offset that applies at this instant.
            $localStamp = $stamp + wp_timezone()->getOffset(new \DateTimeImmutable('@'.$stamp));
            $f = new \IntlDateFormatter('fa_IR@calendar=persian', \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd');
            return (string)$f->format($localStamp);
        }
        return wp_date('Y/m/d', $stamp).' (میلادی)';
    }
    public static function label(string $type): string
    {
        return ['grant' => 'دریافت کش‌بک','purchase' => 'مصرف در خرید','settlement' => 'تأیید پرداخت کیف پول','release' => 'آزادسازی اعتبار رزرو‌شده','reversal' => 'لغو یا مرجوعی','adjustment' => 'اصلاح دستی مدیر','debt_payment' => 'تسویه بدهی','expiry' => 'انقضای اعتبار'][$type] ?? $type;
    }
}
