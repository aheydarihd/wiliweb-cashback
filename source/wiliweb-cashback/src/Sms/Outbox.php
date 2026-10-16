<?php

namespace WiliWeb\Cashback\Sms;

use WiliWeb\Cashback\Wallet\{Repository,Service};
use WiliWeb\Cashback\Settings\Settings;
use WiliWeb\Cashback\Appearance\Format;
use WiliWeb\Cashback\Log;

final class Outbox
{
    private Repository $r;
    public function __construct()
    {
        $this->r = new Repository();
    }
    public function register(): void
    {
        add_action('wwcb_granted', [$this,'scheduleGrant']);
        add_action('wwcb_tick', [$this,'tick']);
        add_action('update_option_wwcb_settings', [$this,'rebuildFuture']);
    }
    public function scheduleGrant(int $id): void
    {
        $g = (new Service())->grantRecord($id);
        if (!$g) {
            return;
        }$s = Settings::get();
        $now = time();
        $table = $this->r->table('sms');
        if ((int)$g['order_id']) {
            $this->r->sql("INSERT IGNORE INTO $table (dedupe_key,user_id,grant_id,kind,days,due_at,state,error_code) VALUES (%s,%d,%d,'grant',0,%d,%s,%s)", [hash('sha256', 'grant:'.$id),$g['user_id'],$id,$now,$s['sms_enabled'] ? 'pending' : 'suppressed',$s['sms_enabled'] ? '' : 'sms_disabled']);
        }
        foreach ($s['reminder_days'] as $days) {
            $due = (int)$g['expires_at'] - $days * DAY_IN_SECONDS;
            if ($due < $now) {
                continue;
            }
            $key = hash('sha256', 'reminder:'.$id.':'.$days);
            $this->r->sql("INSERT IGNORE INTO $table (dedupe_key,user_id,grant_id,kind,days,due_at) VALUES (%s,%d,%d,'reminder',%d,%d)", [$key,$g['user_id'],$id,$days,$due]);
            $this->r->sql("UPDATE $table SET state='pending',due_at=%d WHERE dedupe_key=%s AND state='suppressed' AND error_code='rule_removed'", [$due,$key]);
        }
    }
    public function rebuildFuture(): void
    {
        $days = Settings::get()['reminder_days'];
        $table = $this->r->table('sms');
        foreach ($this->r->rows("SELECT id,days FROM $table WHERE kind='reminder' AND state='pending' AND due_at>%d", [time()]) as $row) {
            if (!in_array((int)$row['days'], $days, true)) {
                $this->r->sql("UPDATE $table SET state='suppressed',error_code='rule_removed' WHERE id=%d", [$row['id']]);
            }
        }
        $last = 0;
        do {
            $rows = $this->r->rows('SELECT id FROM '.$this->r->table('grants')." WHERE id>%d AND status='active' AND expires_at>%d ORDER BY id LIMIT 200", [$last,time()]);
            foreach ($rows as $row) {
                $last = (int)$row['id'];
                $this->scheduleGrant($last);
            }
        } while (count($rows) === 200);
    }
    public function dispatch(int $limit = 25): int
    {
        $table = $this->r->table('sms');
        $now = time();
        $sent = 0;
        if (!Settings::get()['sms_enabled']) {
            $this->r->sql("UPDATE $table SET state='suppressed',error_code='sms_disabled' WHERE state='pending' AND due_at<=%d", [$now]);
            return 0;
        }
        $this->r->sql("UPDATE $table SET state='suppressed',error_code='stale_reminder' WHERE state='pending' AND kind='reminder' AND due_at<%d", [$now - DAY_IN_SECONDS]);
        $this->r->sql("UPDATE $table SET state='unknown',error_code='abandoned_claim' WHERE state='sending' AND claimed_at<%d", [$now - 300]);
        foreach ($this->r->rows("SELECT * FROM $table WHERE state='pending' AND due_at<=%d ORDER BY id LIMIT %d", [$now,max(1, min(100, $limit))]) as $job) {
            if (!$this->r->sql("UPDATE $table SET state='sending',claimed_at=%d WHERE id=%d AND state='pending'", [$now,$job['id']])) {
                continue;
            }
            $g = (new Service())->grantRecord((int)$job['grant_id']);
            if (!$g || $g['status'] !== 'active' || (int)$g['expires_at'] <= $now || ($job['kind'] === 'reminder' && (int)$g['remaining'] <= 0)) {
                $this->r->sql("UPDATE $table SET state='suppressed',error_code='credit_unavailable' WHERE id=%d", [$job['id']]);
                continue;
            }
            if ($job['kind'] === 'reminder' && !in_array((int)$job['days'], Settings::get()['reminder_days'], true)) {
                $this->r->sql("UPDATE $table SET state='suppressed',error_code='rule_removed' WHERE id=%d", [$job['id']]);
                continue;
            }
            $uid = (int)$g['user_id'];
            $user = get_userdata($uid);
            $order = $g['order_id'] ? wc_get_order((int)$g['order_id']) : false;
            $phone = $order ? $order->get_billing_phone() : (string)get_user_meta($uid, 'billing_phone', true);
            if (!$phone) {
                $phone = (string)get_user_meta($uid, 'billing_mobile_phone', true);
            }
            $result = (new Service())->withActiveGrant((int)$job['grant_id'], $job['kind'] === 'reminder', static function ($grant, $snapshot) use ($job, $phone, $order, $user) {
                $message = ['kind' => $job['kind'],'phone' => $phone,'variables' => ['name' => $order ? $order->get_billing_first_name() : ($user ? $user->display_name : ''),'amount' => number_format((int)($job['kind'] === 'grant' ? $grant['issued'] : $grant['remaining'])),'balance' => number_format($snapshot['available_toman']),'expires' => Format::date((int)$grant['expires_at']),'order' => $order ? $order->get_order_number() : '']];
                return (new IppanelClient())->send($message);
            });
            if ($result === null) {
                $this->r->sql("UPDATE $table SET state='suppressed',error_code='credit_unavailable' WHERE id=%d", [$job['id']]);
                continue;
            }
            $this->r->sql("UPDATE $table SET state=%s,provider_ref=%s,error_code=%s WHERE id=%d", [$result['state'],$result['provider_id'] ?? '',$result['code'] ?? '',$job['id']]);
            Log::write('sms_'.$result['state'], ['event_id' => $job['id'],'user_id' => $uid,'order_id' => $g['order_id'],'code' => $result['code'],'state' => $result['state']], $result['state'] === 'sent' ? 'info' : 'warning');
            if ($result['state'] === 'sent') {
                $sent++;
            }
        }return $sent;
    }
    public function tick(): void
    {
        try {
            $wallet = new Service();
            $wallet->expire(100);
            foreach ($this->r->rows('SELECT * FROM '.$this->r->table('operations')." WHERE state='reserved' AND expires_at<%d LIMIT 100", [time()]) as $op) {
                $o = wc_get_order((int)$op['order_id']);
                if ($o && $o->is_paid()) {
                    (new \WiliWeb\Cashback\Checkout\OrderHooks())->paid($o->get_id());
                } else {
                    $wallet->release($op['token'], 'timeout:'.$op['id']);
                }
            }
            // Rebuild future reminders in bounded batches as grants are created, including admin grants.
            $last = (int)get_option('wwcb_sms_scan', 0);
            foreach ($this->r->rows('SELECT id FROM '.$this->r->table('grants').' WHERE id>%d ORDER BY id LIMIT 100', [$last]) as $row) {
                $this->scheduleGrant((int)$row['id']);
                $last = (int)$row['id'];
            }update_option('wwcb_sms_scan', $last, false);
            $this->dispatch();
        } catch (\Throwable $e) {
            Log::write('scheduler_failed', ['code' => 'background_processing'], 'error');
        }
    }
}
