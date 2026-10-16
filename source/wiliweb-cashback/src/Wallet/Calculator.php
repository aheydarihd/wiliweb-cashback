<?php

namespace WiliWeb\Cashback\Wallet;

final class Calculator
{
    public const MAX = 100000000000;
    public function earned(int $base, int $rate, int $step): int
    {
        if ($base < 0 || $base > self::MAX || $rate < 0 || $rate > 10000 || $step < 1 || $step > self::MAX) {
            throw new \InvalidArgumentException('مبلغ یا تنظیم محاسبه نامعتبر است.');
        }
        if (!$base || !$rate) {
            return 0;
        }
        $denominator = 10000 * $step;
        return intdiv($base * $rate + $denominator - 1, $denominator) * $step;
    }
    public function eligible(int $amount, int $minimum): bool
    {
        return $amount >= $minimum;
    }
    public function allocate(int $amount, array $items): array
    {
        if ($amount < 0 || $amount > self::MAX || !$items || array_sum($items) <= 0) {
            throw new \InvalidArgumentException('تخصیص نامعتبر است.');
        }
        foreach ($items as $value) {
            if (!is_int($value) || $value < 0 || $value > self::MAX) {
                throw new \InvalidArgumentException('مبلغ نامعتبر است.');
            }
        }
        ksort($items, SORT_NUMERIC);
        $sum = array_sum($items);
        $out = [];
        $remainders = [];
        foreach ($items as $id => $value) {
            [$out[$id], $remainders[$id]] = self::ratio($amount, $value, $sum);
        }
        arsort($remainders, SORT_REGULAR);
        $left = $amount - array_sum($out);
        foreach ($remainders as $id => $_) {
            if ($left-- <= 0) {
                break;
            } $out[$id]++;
        }
        return $out;
    }
    /** Exact a*b/divisor using quotient/remainder doubling, without optional extensions or overflow. */
    private static function ratio(int $a, int $b, int $divisor): array
    {
        $q = 0;
        $r = 0;
        $partQ = intdiv($a, $divisor);
        $partR = $a % $divisor;
        while ($b > 0) {
            if ($b % 2) {
                $q += $partQ;
                $r += $partR;
                if ($r >= $divisor) {
                    $q++;
                    $r -= $divisor;
                }
            }
            $b = intdiv($b, 2);
            if (!$b) {
                break;
            }
            $partQ *= 2;
            $partR *= 2;
            if ($partR >= $divisor) {
                $partQ++;
                $partR -= $divisor;
            }
        }
        return [$q,$r];
    }
    public static function money($value): int
    {
        $s = (string)$value;
        if (!preg_match('/^\d+(?:\.0+)?$/D', $s) || (float)$s > self::MAX) {
            throw new \InvalidArgumentException('مبلغ باید عدد صحیح تومان باشد.');
        }
        return (int)$s;
    }
}
