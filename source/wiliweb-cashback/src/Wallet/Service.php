<?php

namespace WiliWeb\Cashback\Wallet;

final class Service
{
    private Repository $r;
    private Clock $clock;
    public function __construct(?Repository $r = null, ?Clock $clock = null)
    {
        $this->r = $r ?? new Repository();
        $this->clock = $clock ?? new Clock();
    }
    public function grantRecord(int $id): ?array
    {
        return $this->r->one('SELECT * FROM '.$this->r->table('grants').' WHERE id=%d', [$id]);
    }
    private function assertWritable(): void
    {
        if (get_woocommerce_currency() !== 'IRT' || get_option('woocommerce_calc_taxes') === 'yes') {
            throw new \DomainException('عملیات جدید کیف پول فقط با واحد تومان و مالیات غیرفعال مجاز است.');
        }
    }
    /** Serialize a provider submission with grant revocation, using the same owner lock. */
    public function withActiveGrant(int $id, bool $requireRemaining, callable $submit): ?array
    {
        $grant = $this->grantRecord($id);
        if (!$grant) {
            return null;
        }
        $user = (int)$grant['user_id'];
        return $this->r->transactionForUser($user, function () use ($id, $user, $requireRemaining, $submit) {
            $this->reconcile($user);
            $fresh = $this->grantRecord($id);
            if (!$fresh || $fresh['status'] !== 'active' || (int)$fresh['expires_at'] <= $this->clock->now() || ($requireRemaining && (int)$fresh['remaining'] <= 0)) {
                return null;
            }
            return $submit($fresh, $this->rawSnapshot($user));
        });
    }
    private function rawSnapshot(int $user): array
    {
        $grants = $this->r->rows('SELECT * FROM '.$this->r->table('grants')." WHERE user_id=%d AND status='active' AND expires_at>%d AND remaining>0 ORDER BY expires_at,id", [$user,$this->clock->now()]);
        $available = array_sum(array_column($grants, 'remaining'));
        $debt = 0;
        foreach ($this->r->rows('SELECT * FROM '.$this->r->table('claims').' WHERE user_id=%d', [$user]) as $c) {
            $debt += max(0, (int)$c['needed'] - $this->settled((int)$c['id']));
        }
        $next = null;
        if ($grants) {
            $date = (int)$grants[0]['expires_at'];
            $amount = 0;
            foreach ($grants as $g) {
                if ((int)$g['expires_at'] === $date) {
                    $amount += (int)$g['remaining'];
                }
            } $next = ['amount_toman' => $amount,'expires_at' => $date];
        }
        $acc = $this->r->one('SELECT * FROM '.$this->r->table('accounts').' WHERE user_id=%d', [$user]);
        return ['available_toman' => (int)$available,'debt_toman' => $debt,'currency' => 'IRT','revision' => (int)($acc['revision'] ?? 0),'next_expiry' => $next];
    }
    public function snapshot(int $user): array
    {
        return $this->r->transactionForUser($user, function () use ($user) {
            $this->reconcile($user);
            return $this->rawSnapshot($user);
        });
    }
    public function grant(int $user, int $order, int $amount, int $expiry, array $rules): int
    {
        $this->assertWritable();
        if ($amount <= 0 || $amount > Calculator::MAX || $expiry <= $this->clock->now()) {
            throw new \DomainException('اعتبار یا تاریخ انقضا نامعتبر است.');
        }
        return $this->r->transactionForUser($user, function () use ($user, $order, $amount, $expiry, $rules) {
            $source = 'order:'.$order;
            $old = $this->r->one('SELECT * FROM '.$this->r->table('grants').' WHERE source_key=%s', [$source]);
            if ($old) {
                if ((int)$old['user_id'] !== $user) {
                    throw new \DomainException('مالک سفارش متفاوت است.');
                }return (int)$old['id'];
            }
            $id = $this->r->insert('grants', ['user_id' => $user,'source_key' => $source,'order_id' => $order,'issued' => $amount,'remaining' => $amount,'expires_at' => $expiry,'created_at' => $this->clock->now(),'rules' => wp_json_encode($rules)]);
            $this->r->record('grant:'.$order, $user, 'grant', $amount, $this->clock->now(), ['order_id' => $order,'details' => wp_json_encode(['grant_id' => $id,'expires_at' => $expiry])]);
            $this->reconcile($user);
            return $id;
        });
    }
    private function take(int $user, int $amount, int $op, string $kind = 'spend', int $claim = 0): void
    {
        if ($amount < 0 || $amount > Calculator::MAX) {
            throw new \DomainException('مبلغ نامعتبر است.');
        }
        $grants = $this->r->rows('SELECT * FROM '.$this->r->table('grants')." WHERE user_id=%d AND status='active' AND expires_at>%d AND remaining>0 ORDER BY expires_at,id", [$user,$this->clock->now()]);
        if (array_sum(array_column($grants, 'remaining')) < $amount) {
            throw new \DomainException('موجودی کیف پول کافی نیست یا اعتبار منقضی شده است.');
        }
        foreach ($grants as $g) {
            if (!$amount) {
                break;
            }$use = min($amount, (int)$g['remaining']);
            $this->r->sql('UPDATE '.$this->r->table('grants').' SET remaining=remaining-%d WHERE id=%d', [$use,$g['id']]);
            $this->r->insert('allocations', ['user_id' => $user,'op_id' => $op,'grant_id' => $g['id'],'amount' => $use,'kind' => $kind,'claim_id' => $claim]);
            $amount -= $use;
        }
    }
    public function reserve(int $user, int $order, int $amount, string $key): array
    {
        $this->assertWritable();
        return $this->r->transactionForUser($user, function () use ($user, $order, $amount, $key) {
            $old = $this->r->event($key);
            if ($old) {
                if ((int)$old['user_id'] !== $user || (int)$old['order_id'] !== $order || -(int)$old['amount'] !== $amount) {
                    throw new \DomainException('درخواست رزرو با سفارش قبلی تطابق ندارد.');
                }
                return ['token' => $old['token'],'amount_toman' => -(int)$old['amount'],'expires_at' => (int)$old['expires_at'],'state' => $old['state']];
            }
            $this->reconcile($user);
            if ($amount <= 0) {
                throw new \DomainException('مبلغ رزرو نامعتبر است.');
            }
            $token = bin2hex(random_bytes(24));
            $expiry = $this->clock->now() + 900;
            $op = $this->r->record($key, $user, 'purchase', -$amount, $this->clock->now(), ['order_id' => $order,'state' => 'reserved','token' => $token,'expires_at' => $expiry]);
            $this->take($user, $amount, $op);
            return ['token' => $token,'amount_toman' => $amount,'expires_at' => $expiry,'state' => 'reserved'];
        });
    }
    public function settle(string $token, string $key): array
    {
        $op = $this->r->one('SELECT * FROM '.$this->r->table('operations').' WHERE token=%s AND kind=%s', [$token,'purchase']);
        if (!$op) {
            throw new \DomainException('رزرو کیف پول یافت نشد.');
        }
        return $this->r->transactionForUser((int)$op['user_id'], function () use ($op, $key) {
            $fresh = $this->r->one('SELECT * FROM '.$this->r->table('operations').' WHERE id=%d', [$op['id']]);
            if ($fresh['state'] === 'committed') {
                return ['state' => 'committed','amount_toman' => -(int)$fresh['amount']];
            }
            if ($fresh['state'] !== 'reserved') {
                throw new \DomainException('رزرو آزاد شده است؛ سفارش نیاز به بررسی دارد.');
            }
            $alloc = $this->r->rows('SELECT a.*,g.status,g.expires_at FROM '.$this->r->table('allocations').' a JOIN '.$this->r->table('grants').' g ON g.id=a.grant_id WHERE a.op_id=%d', [$op['id']]);
            foreach ($alloc as $a) {
                if ($a['status'] !== 'active' || (int)$a['expires_at'] <= $this->clock->now()) {
                    throw new \DomainException('اعتبار رزروشده معتبر نیست؛ سفارش نیاز به بررسی دارد.');
                }
            }
            $this->r->sql('UPDATE '.$this->r->table('operations')." SET state='committed' WHERE id=%d", [$op['id']]);
            $this->r->record($key, (int)$op['user_id'], 'settlement', 0, $this->clock->now(), ['order_id' => $op['order_id']]);
            return ['state' => 'committed','amount_toman' => -(int)$op['amount']];
        });
    }
    private function returnAllocation(array $a, int $amount): void
    {
        $amount = min($amount, (int)$a['amount'] - (int)$a['returned']);
        if ($amount <= 0) {
            return;
        }
        $this->r->sql('UPDATE '.$this->r->table('allocations').' SET returned=returned+%d WHERE id=%d', [$amount,$a['id']]);
        $this->r->sql('UPDATE '.$this->r->table('grants').' SET remaining=remaining+%d WHERE id=%d', [$amount,$a['grant_id']]);
    }
    public function release(string $token, string $key): void
    {
        $op = $this->r->one('SELECT * FROM '.$this->r->table('operations')." WHERE token=%s AND kind='purchase'", [$token]);
        if (!$op) {
            return;
        }
        $this->r->transactionForUser((int)$op['user_id'], function () use ($op, $key) {
            if ($this->r->event($key)) {
                return;
            }
            $fresh = $this->r->one('SELECT * FROM '.$this->r->table('operations').' WHERE id=%d', [$op['id']]);
            if ($fresh['state'] !== 'reserved') {
                return;
            }
            foreach ($this->r->rows('SELECT * FROM '.$this->r->table('allocations').' WHERE op_id=%d', [$op['id']]) as $a) {
                $this->returnAllocation($a, (int)$a['amount'] - (int)$a['returned']);
            }
            $this->r->sql('UPDATE '.$this->r->table('operations')." SET state='released' WHERE id=%d", [$op['id']]);
            $this->r->record($key, (int)$op['user_id'], 'release', -(int)$op['amount'], $this->clock->now(), ['order_id' => $op['order_id']]);
            $this->reconcile((int)$op['user_id']);
        });
    }
    private function settled(int $claim): int
    {
        $row = $this->r->one('SELECT COALESCE(SUM(amount-returned),0) AS total FROM '.$this->r->table('allocations')." WHERE claim_id=%d AND kind='debt'", [$claim]);
        return (int)$row['total'];
    }
    private function reconcile(int $user): void
    {
        // Claims describe actual spent credit from revoked sources. Debt settlements never create a second claim.
        $alloc = $this->r->rows('SELECT a.* FROM '.$this->r->table('allocations').' a JOIN '.$this->r->table('grants').' g ON g.id=a.grant_id JOIN '.$this->r->table('operations')." o ON o.id=a.op_id WHERE a.user_id=%d AND a.kind='spend' AND g.status='revoked' AND o.state='committed' AND o.kind='purchase'", [$user]);
        foreach ($alloc as $a) {
            $this->r->sql('INSERT INTO '.$this->r->table('claims').' (user_id,allocation_id,needed) VALUES (%d,%d,%d) ON DUPLICATE KEY UPDATE needed=VALUES(needed)', [$user,$a['id'],max(0, (int)$a['amount'] - (int)$a['returned'])]);
        }
        // If a settlement source is itself revoked, reopen its original claim, not a new unrelated debt.
        foreach ($this->r->rows('SELECT a.* FROM '.$this->r->table('allocations').' a JOIN '.$this->r->table('grants')." g ON g.id=a.grant_id WHERE a.user_id=%d AND a.kind='debt' AND g.status='revoked' AND a.returned<a.amount", [$user]) as $a) {
            $this->returnAllocation($a, (int)$a['amount'] - (int)$a['returned']);
        }
        foreach ($this->r->rows('SELECT * FROM '.$this->r->table('claims').' WHERE user_id=%d ORDER BY id', [$user]) as $c) {
            $paid = $this->settled((int)$c['id']);
            $excess = $paid - (int)$c['needed'];
            if ($excess > 0) {
                foreach ($this->r->rows('SELECT * FROM '.$this->r->table('allocations')." WHERE claim_id=%d AND kind='debt' AND returned<amount ORDER BY id DESC", [$c['id']]) as $a) {
                    $back = min($excess, (int)$a['amount'] - (int)$a['returned']);
                    $this->returnAllocation($a, $back);
                    $excess -= $back;
                    if (!$excess) {
                        break;
                    }
                }
            }
        }
        foreach ($this->r->rows('SELECT * FROM '.$this->r->table('claims').' WHERE user_id=%d ORDER BY id', [$user]) as $c) {
            $need = (int)$c['needed'] - $this->settled((int)$c['id']);
            if ($need <= 0) {
                continue;
            }
            $row = $this->r->one('SELECT COALESCE(SUM(remaining),0) AS total FROM '.$this->r->table('grants')." WHERE user_id=%d AND status='active' AND expires_at>%d", [$user,$this->clock->now()]);
            $pay = min($need, (int)$row['total']);
            if ($pay > 0) {
                $id = $this->r->record('debt:'.wp_generate_uuid4(), $user, 'debt_payment', -$pay, $this->clock->now(), ['details' => wp_json_encode(['claim_id' => $c['id']])]);
                $this->take($user, $pay, $id, 'debt', (int)$c['id']);
            }
        }
    }
    public function reverse(int $order, string $key, array $returnedByItem, bool $revoke): array
    {
        $op = $this->r->one('SELECT * FROM '.$this->r->table('operations').' WHERE order_id=%d ORDER BY id LIMIT 1', [$order]);
        if (!$op) {
            return ['restored_toman' => 0,'expired_toman' => 0,'revoked_toman' => 0,'debt_toman' => 0];
        }$user = (int)$op['user_id'];
        return $this->r->transactionForUser($user, function () use ($order, $key, $returnedByItem, $revoke, $user) {
            $old = $this->r->event($key);
            if ($old) {
                return json_decode($old['details'], true);
            }
            $revoked = 0;
            if ($revoke) {
                foreach ($this->r->rows('SELECT * FROM '.$this->r->table('grants')." WHERE order_id=%d AND status!='revoked'", [$order]) as $g) {
                    $revoked += (int)$g['issued'];
                    $this->r->sql('UPDATE '.$this->r->table('grants')." SET status='revoked' WHERE id=%d", [$g['id']]);
                }
            }
            $amount = max(0, (int)array_sum($returnedByItem));
            $restored = 0;
            $expired = 0;
            foreach ($this->r->rows('SELECT a.*,g.expires_at,g.status FROM '.$this->r->table('allocations').' a JOIN '.$this->r->table('operations').' o ON o.id=a.op_id JOIN '.$this->r->table('grants')." g ON g.id=a.grant_id WHERE o.order_id=%d AND o.kind='purchase' AND o.state='committed' AND a.kind='spend' AND a.returned<a.amount ORDER BY a.id DESC", [$order]) as $a) {
                if (!$amount) {
                    break;
                }$back = min($amount, (int)$a['amount'] - (int)$a['returned']);
                $this->returnAllocation($a, $back);
                $amount -= $back;
                if ($a['status'] === 'revoked') {
                    continue;
                }
                if ((int)$a['expires_at'] <= $this->clock->now()) {
                    $expired += $back;
                } else {
                    $restored += $back;
                }
            }
            $this->reconcile($user);
            $result = ['restored_toman' => $restored,'expired_toman' => $expired,'revoked_toman' => $revoked,'debt_toman' => $this->rawSnapshot($user)['debt_toman']];
            $this->r->record($key, $user, 'reversal', $restored, $this->clock->now(), ['order_id' => $order,'details' => wp_json_encode($result)]);
            return $result;
        });
    }
    public function adjust(int $user, int $amount, string $reason, int $actor, int $expiry, string $key): void
    {
        $this->assertWritable();
        if (!$amount || abs($amount) > Calculator::MAX || trim($reason) === '' || $actor < 1) {
            throw new \DomainException('مبلغ، دلیل و مدیر معتبر لازم است.');
        }
        if ($amount > 0 && $expiry <= $this->clock->now()) {
            throw new \DomainException('تاریخ انقضا باید در آینده باشد.');
        }
        $this->r->transactionForUser($user, function () use ($user, $amount, $reason, $actor, $expiry, $key) {
            if ($this->r->event($key)) {
                return;
            }$this->reconcile($user);
            $op = $this->r->record($key, $user, 'adjustment', $amount, $this->clock->now(), ['actor_id' => $actor,'reason' => $reason,'details' => wp_json_encode(['expires_at' => $expiry])]);
            if ($amount > 0) {
                $this->r->insert('grants', ['user_id' => $user,'source_key' => 'admin:'.$op,'issued' => $amount,'remaining' => $amount,'expires_at' => $expiry,'created_at' => $this->clock->now(),'rules' => '{}']);
            } else {
                $this->take($user, -$amount, $op, 'adjust');
            }$this->reconcile($user);
        });
    }
    public function expire(int $limit = 100): int
    {
        $grants = $this->r->rows('SELECT id,user_id FROM '.$this->r->table('grants')." WHERE status='active' AND expires_at<=%d LIMIT %d", [$this->clock->now(),max(1, min(500, $limit))]);
        foreach ($grants as $row) {
            $this->r->transactionForUser((int)$row['user_id'], function () use ($row) {
                $g = $this->grantRecord((int)$row['id']);
                if ($g['status'] !== 'active') {
                    return;
                }$this->r->sql('UPDATE '.$this->r->table('grants')." SET status='expired' WHERE id=%d", [$g['id']]);
                $key = 'expire:'.$g['id'];
                if (!$this->r->event($key)) {
                    $this->r->record($key, (int)$g['user_id'], 'expiry', -(int)$g['remaining'], $this->clock->now(), ['order_id' => $g['order_id']]);
                }
            });
        }
        return count($grants);
    }
}
