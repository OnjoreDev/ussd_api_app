<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Wallet extends Model
{
    /**
     * Credits the Welfare Wallet (ID 2) for a specific member.
     */
    public function creditWelfare(int $memberId, float $amount): bool
    {
        // 1. We use INSERT ... ON DUPLICATE KEY UPDATE.
        // 2. This requires a UNIQUE index on (member_id, wallet_type_id).
        $sql = "INSERT INTO wallets (member_id, wallet_type_id, balance) 
            VALUES (?, 2, ?) 
            ON DUPLICATE KEY UPDATE balance = balance + ?";

        $stmt = $this->pdo->prepare($sql);

        // We pass $amount three times: 
        // - Once for the INSERT (initial balance)
        // - Once for the UPDATE (incrementing balance)
        return $stmt->execute([$memberId, $amount, $amount]);
    }
}
