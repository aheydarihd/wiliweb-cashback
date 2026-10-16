<?php

namespace WiliWeb\Cashback\Install;

final class Schema
{
    public static function migrate(): void
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix.'wwcb_';
        $tables = [
            'accounts' => "user_id bigint unsigned NOT NULL, currency varchar(8) NOT NULL DEFAULT 'IRT', revision bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (user_id)",
            'grants' => "id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, source_key varchar(100) NOT NULL, order_id bigint unsigned NOT NULL DEFAULT 0, issued bigint NOT NULL, remaining bigint NOT NULL, expires_at bigint NOT NULL, status varchar(16) NOT NULL DEFAULT 'active', created_at bigint NOT NULL, rules longtext NOT NULL, PRIMARY KEY  (id), UNIQUE KEY source_key (source_key), KEY owner_expiry (user_id,status,expires_at)",
            'operations' => "id bigint unsigned NOT NULL AUTO_INCREMENT, event_key char(64) NOT NULL, user_id bigint unsigned NOT NULL, order_id bigint unsigned NOT NULL DEFAULT 0, kind varchar(24) NOT NULL, amount bigint NOT NULL DEFAULT 0, state varchar(24) NOT NULL DEFAULT 'committed', token varchar(64) NOT NULL DEFAULT '', expires_at bigint NOT NULL DEFAULT 0, actor_id bigint unsigned NOT NULL DEFAULT 0, reason text NOT NULL, details longtext NOT NULL, created_at bigint NOT NULL, PRIMARY KEY  (id), UNIQUE KEY event_key (event_key), KEY owner_time (user_id,created_at), KEY order_id (order_id), KEY token (token)",
            'allocations' => "id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, op_id bigint unsigned NOT NULL DEFAULT 0, grant_id bigint unsigned NOT NULL, amount bigint NOT NULL, returned bigint NOT NULL DEFAULT 0, kind varchar(16) NOT NULL DEFAULT 'spend', claim_id bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (id), KEY owner_grant (user_id,grant_id), KEY op_id (op_id), KEY claim_id (claim_id)",
            'claims' => "id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, allocation_id bigint unsigned NOT NULL, needed bigint NOT NULL DEFAULT 0, PRIMARY KEY  (id), UNIQUE KEY allocation_id (allocation_id), KEY user_id (user_id)",
            'sms' => "id bigint unsigned NOT NULL AUTO_INCREMENT, dedupe_key char(64) NOT NULL, user_id bigint unsigned NOT NULL, grant_id bigint unsigned NOT NULL DEFAULT 0, kind varchar(16) NOT NULL, days int NOT NULL DEFAULT 0, due_at bigint NOT NULL, state varchar(16) NOT NULL DEFAULT 'pending', claimed_at bigint NOT NULL DEFAULT 0, provider_ref varchar(100) NOT NULL DEFAULT '', error_code varchar(80) NOT NULL DEFAULT '', PRIMARY KEY  (id), UNIQUE KEY dedupe_key (dedupe_key), KEY due_state (state,due_at)"
        ];
        foreach ($tables as $name => $fields) {
            $lines = str_replace(', ', ",\n", $fields);
            dbDelta("CREATE TABLE {$p}{$name} (\n{$lines}\n) ENGINE=InnoDB $c;");
            if ($wpdb->last_error) {
                throw new \RuntimeException('ساخت جدول کیف پول ناموفق بود.');
            }
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $p.$name));
            if (strtolower((string)$engine) !== 'innodb') {
                throw new \RuntimeException('کیف پول به جدول‌های InnoDB نیاز دارد؛ فعال‌سازی انجام نشد.');
            }
        }
        update_option('wwcb_schema', '1', false);
    }
}
