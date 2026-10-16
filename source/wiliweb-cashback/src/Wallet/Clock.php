<?php

namespace WiliWeb\Cashback\Wallet;

class Clock
{
    private ?int $fixed;
    public function __construct(?int $fixed = null)
    {
        $this->fixed = $fixed;
    }
    public function now(): int
    {
        return $this->fixed ?? time();
    }
    public function set(int $now): void
    {
        $this->fixed = $now;
    }
}
