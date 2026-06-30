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
    /**
     * Fetch a specific wallet record for a member by wallet type
     */
    public function getWalletByMemberAndType(int $memberId, int $walletTypeId): ?array
    {
        $sql = "SELECT * FROM wallets WHERE member_id = ? AND wallet_type_id = ? LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$memberId, $walletTypeId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    // Add these to App\Models\Wallet

    /**
     * Specifically for Chama Points (Wallet ID 3)
     */
    public function updateChamaPoints(int $memberId, int $points): bool
    {
        // Points can be positive (earn) or negative (redeem)
        $sql = "INSERT INTO wallets (member_id, wallet_type_id, balance) 
            VALUES (?, 3, ?) 
            ON DUPLICATE KEY UPDATE balance = balance + ?";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$memberId, $points, $points]);
    }

    /**
     * Fetch Chama Points balance specifically
     */
    public function getChamaPointsBalance(int $memberId): int
    {
        $wallet = $this->getWalletByMemberAndType($memberId, 3);
        return $wallet ? (int)$wallet['balance'] : 0;
    }
}
