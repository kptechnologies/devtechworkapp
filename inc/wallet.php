<?php
/**
 * Wallet ledger.
 * kind:   credit (money sent to staff) | debit (staff expense) | adjust (admin ± correction)
 * effect: signed amount applied to the balance
 * status: posted (counts, awaiting review for debits) | approved | queried | rejected (does not count)
 */

function wallet_balance(int $userId): float
{
    return (float)q("SELECT COALESCE(SUM(effect),0) FROM wallet_txns WHERE user_id = ? AND status <> 'rejected'", [$userId])->fetchColumn();
}

function wallet_stats(int $userId): array
{
    $t = today();
    $m = date('Y-m-01');
    $r = q("SELECT
            COALESCE(SUM(effect),0) AS balance,
            COALESCE(SUM(CASE WHEN kind='credit' AND txn_date = ? THEN amount END),0) AS in_today,
            COALESCE(SUM(CASE WHEN kind='debit' AND txn_date = ? THEN amount END),0) AS spent_today,
            COALESCE(SUM(CASE WHEN kind='debit' AND txn_date >= ? THEN amount END),0) AS spent_month,
            COALESCE(SUM(CASE WHEN kind='credit' AND txn_date >= ? THEN amount END),0) AS in_month,
            SUM(CASE WHEN kind='debit' AND status IN ('posted','queried') THEN 1 ELSE 0 END) AS pending
        FROM wallet_txns WHERE user_id = ? AND status <> 'rejected'", [$t, $t, $m, $m, $userId])->fetch();
    $q = (int)q("SELECT COUNT(*) FROM wallet_txns WHERE user_id = ? AND status = 'queried'", [$userId])->fetchColumn();
    return [
        'balance'     => round((float)$r['balance'], 2),
        'in_today'    => round((float)$r['in_today'], 2),
        'spent_today' => round((float)$r['spent_today'], 2),
        'spent_month' => round((float)$r['spent_month'], 2),
        'in_month'    => round((float)$r['in_month'], 2),
        'pending'     => (int)$r['pending'],
        'queried'     => $q,
    ];
}

/** Per-staff balances for the admin. */
function wallet_all_balances(): array
{
    $t = today();
    $m = date('Y-m-01');
    $rows = q("SELECT u.id, u.name, u.email, u.location, u.active,
            COALESCE(SUM(CASE WHEN w.status <> 'rejected' THEN w.effect END),0) AS balance,
            COALESCE(SUM(CASE WHEN w.status <> 'rejected' AND w.kind='debit' AND w.txn_date = ? THEN w.amount END),0) AS spent_today,
            COALESCE(SUM(CASE WHEN w.status <> 'rejected' AND w.kind='debit' AND w.txn_date >= ? THEN w.amount END),0) AS spent_month,
            COALESCE(SUM(CASE WHEN w.status <> 'rejected' AND w.kind='credit' AND w.txn_date = ? THEN w.amount END),0) AS in_today,
            SUM(CASE WHEN w.kind='debit' AND w.status IN ('posted','queried') THEN 1 ELSE 0 END) AS pending,
            MAX(w.created_at) AS last_activity
        FROM users u LEFT JOIN wallet_txns w ON w.user_id = u.id
        WHERE u.role = 'staff' OR w.id IS NOT NULL
        GROUP BY u.id, u.name, u.email, u.location, u.active
        ORDER BY u.active DESC, u.name", [$t, $m, $t])->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        foreach (['balance', 'spent_today', 'spent_month', 'in_today'] as $k) {
            $r[$k] = round((float)$r[$k], 2);
        }
        $r['pending'] = (int)$r['pending'];
    }
    return $rows;
}

function wallet_add(array $t): int
{
    $kind = $t['kind'];
    $amount = round(abs((float)$t['amount']), 2);
    $effect = match ($kind) {
        'credit' => $amount,
        'debit'  => -$amount,
        'adjust' => round((float)$t['amount'], 2),
    };
    q('INSERT INTO wallet_txns (user_id, kind, category, amount, effect, description, route, txn_date, status, request_id, report_id, created_by, source, created_at, updated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())', [
        $t['user_id'], $kind, $t['category'] ?? '', $amount, $effect,
        $t['description'] ?? '', $t['route'] ?? '', $t['txn_date'] ?? today(),
        $t['status'] ?? 'posted', $t['request_id'] ?? null, $t['report_id'] ?? null,
        $t['created_by'] ?? null, $t['source'] ?? 'app', $t['created_at'] ?? date('Y-m-d H:i:s'),
    ]);
    return (int)db()->lastInsertId();
}

/** Bump a global "something changed" marker used by the live pulse. */
function touch_change(): void
{
    save_setting('_last_change', microtime(true));
}
