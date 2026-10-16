<?php

namespace WiliWeb\Cashback\Wallet;

final class Repository
{
    public function table(string $name): string
    {
        global $wpdb;
        if (!in_array($name, ['accounts','grants','operations','allocations','claims','sms'], true)) {
            throw new \InvalidArgumentException('Unknown table');
        }
        return $wpdb->prefix.'wwcb_'.$name;
    }
    public function sql(string $sql, array $args = []): int
    {
        global $wpdb;
        $result = $wpdb->query($args ? $wpdb->prepare($sql, ...$args) : $sql);
        if ($result === false) {
            throw new \RuntimeException('خطای ثبت تراکنش کیف پول.');
        }
        return (int)$result;
    }
    public function rows(string $sql, array $args = []): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A);
        if ($wpdb->last_error) {
            throw new \RuntimeException('خطای خواندن اطلاعات کیف پول.');
        }
        return $rows ?: [];
    }
    public function one(string $sql, array $args = []): ?array
    {
        return $this->rows($sql, $args)[0] ?? null;
    }
    public function insert(string $table, array $data): int
    {
        global $wpdb;
        if ($wpdb->insert($this->table($table), $data) === false) {
            throw new \RuntimeException('ثبت رویداد کیف پول ناموفق بود.');
        }
        return (int)$wpdb->insert_id;
    }
    public function transactionForUser(int $userId, callable $fn)
    {
        if ($userId < 1) {
            throw new \DomainException('حساب کاربری معتبر لازم است.');
        }
        $t = $this->table('accounts');
        $this->sql("INSERT IGNORE INTO $t (user_id) VALUES (%d)", [$userId]);
        $this->sql('START TRANSACTION');
        try {
            $this->one("SELECT user_id FROM $t WHERE user_id=%d FOR UPDATE", [$userId]);
            $result = $fn();
            $this->sql("UPDATE $t SET revision=revision+1 WHERE user_id=%d", [$userId]);
            $this->sql('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->sql('ROLLBACK');
            throw $e;
        }
    }
    public function event(string $key): ?array
    {
        return $this->one('SELECT * FROM '.$this->table('operations').' WHERE event_key=%s', [hash('sha256', $key)]);
    }
    public function record(string $key, int $user, string $kind, int $amount, int $now, array $extra = []): int
    {
        return $this->insert('operations', array_replace(['event_key' => hash('sha256', $key),'user_id' => $user,'kind' => $kind,'amount' => $amount,'reason' => '','details' => '{}','created_at' => $now], $extra));
    }
}
