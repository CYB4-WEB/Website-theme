<?php

declare(strict_types=1);

namespace Alpha\Models;

use Alpha\Core\Database;

/**
 * Coin / monetization model.
 * Manages balances and the immutable transaction ledger.
 */
class Coin extends BaseModel
{
    protected static string $table = 'user_coins';

    // ── Balance ───────────────────────────────────────────────────────────────

    public function getBalance(int $userId): int
    {
        $tbl = $this->tbl();
        return (int)($this->db->get_var("SELECT balance FROM `{$tbl}` WHERE user_id = :uid", ['uid' => $userId]) ?? 0);
    }

    public function ensureRow(int $userId): void
    {
        $tbl = $this->tbl();
        if (!$this->db->get_var("SELECT id FROM `{$tbl}` WHERE user_id = :uid", ['uid' => $userId])) {
            $this->db->insert($tbl, ['user_id' => $userId, 'balance' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    public function add(int $userId, int $amount, string $type, string $description = '', int $refId = 0): bool
    {
        if ($amount <= 0) {
            return false;
        }
        $this->ensureRow($userId);
        $tbl = $this->tbl();
        $this->db->execute("UPDATE `{$tbl}` SET balance = balance + :amt, updated_at = NOW() WHERE user_id = :uid",
            ['amt' => $amount, 'uid' => $userId]);
        $this->logTransaction($userId, $amount, $type, $description, $refId);
        return true;
    }

    public function deduct(int $userId, int $amount, string $description = '', int $refId = 0): bool
    {
        if ($amount <= 0) {
            return false;
        }
        $this->ensureRow($userId);
        $tbl = $this->tbl();

        // Atomic check-and-deduct
        $rows = $this->db->execute(
            "UPDATE `{$tbl}` SET balance = balance - :amt, updated_at = NOW()
             WHERE user_id = :uid AND balance >= :amt",
            ['amt' => $amount, 'uid' => $userId]
        );

        if ($rows === 0) {
            return false; // insufficient funds
        }

        $this->logTransaction($userId, -$amount, 'spend', $description, $refId);
        return true;
    }

    private function logTransaction(int $userId, int $amount, string $type, string $desc, int $refId): void
    {
        $tbl = Database::table('coin_transactions');
        $this->db->insert($tbl, [
            'user_id'          => $userId,
            'amount'           => $amount,
            'transaction_type' => $type,
            'reference_id'     => $refId,
            'description'      => $desc,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    // ── Chapter unlock ────────────────────────────────────────────────────────

    public function isUnlocked(int $userId, int $chapterId): bool
    {
        $tbl = Database::table('chapter_unlocks');
        return (bool)$this->db->get_var(
            "SELECT id FROM `{$tbl}` WHERE user_id = :uid AND chapter_id = :cid LIMIT 1",
            ['uid' => $userId, 'cid' => $chapterId]
        );
    }

    public function unlock(int $userId, int $chapterId, int $coinCost): array
    {
        if ($this->isUnlocked($userId, $chapterId)) {
            return ['success' => true, 'already' => true];
        }

        if (!$this->deduct($userId, $coinCost, "Unlock chapter #{$chapterId}", $chapterId)) {
            return ['success' => false, 'message' => 'Insufficient coins.'];
        }

        $tbl = Database::table('chapter_unlocks');
        $this->db->insert($tbl, ['user_id' => $userId, 'chapter_id' => $chapterId, 'created_at' => date('Y-m-d H:i:s')]);
        return ['success' => true, 'already' => false];
    }

    // ── Transactions history ──────────────────────────────────────────────────

    public function getTransactions(int $userId, int $limit = 20, int $offset = 0): array
    {
        $tbl = Database::table('coin_transactions');
        return $this->db->get_results(
            "SELECT * FROM `{$tbl}` WHERE user_id = :uid ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}",
            ['uid' => $userId]
        );
    }

    // ── Withdrawals ───────────────────────────────────────────────────────────

    public function requestWithdrawal(int $userId, int $amount, string $method, array $details): array
    {
        $minCoin  = (int)(\Alpha\Core\Config::get('COIN_EXCHANGE_RATE', 100));
        $minWithd = (int)(\Alpha\Core\Config::get('MIN_WITHDRAWAL', 10));
        $minCoins = $minWithd * $minCoin;

        if ($amount < $minCoins) {
            return ['success' => false, 'message' => "Minimum withdrawal is {$minCoins} coins."];
        }

        if (!$this->deduct($userId, $amount, 'Withdrawal request')) {
            return ['success' => false, 'message' => 'Insufficient coins.'];
        }

        $tbl = Database::table('withdrawals');
        $id  = $this->db->insert($tbl, [
            'user_id'        => $userId,
            'amount'         => $amount,
            'payment_method' => $method,
            'payment_details'=> json_encode($details),
            'status'         => 'pending',
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'withdrawal_id' => $id];
    }
}
