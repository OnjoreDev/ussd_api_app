<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Transaction extends Model
{
    /**
     * Records a new movement in the ledger.
     */
    // In App\Models\Transaction.php
    public function create(array $data): bool
    {
        $sql = "INSERT INTO transactions 
            (member_id, wallet_type_id, type, debit, credit, previous_balance, running_balance, status, payment_receipt, description) 
            VALUES (:member_id, :wallet_type_id, :type, :debit, :credit, :previous_balance, :running_balance, 'Completed', :payment_receipt, :description)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($data);
    }

    /**
     * Locks a wallet row for an atomic update (prevents race conditions).
     */
    public function getWalletForUpdate(int $memberId, int $walletTypeId): ?array
    {
        $sql = "SELECT balance FROM wallets WHERE member_id = ? AND wallet_type_id = ? FOR UPDATE";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$memberId, $walletTypeId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Updates the actual wallet balance.
     */
    public function updateBalance(int $memberId, int $walletTypeId, int $newBalance): bool
    {
        $sql = "UPDATE wallets SET balance = ? WHERE member_id = ? AND wallet_type_id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$newBalance, $memberId, $walletTypeId]);
    }
}