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
    // In App\Models\Transaction.php

    // In App\Models\Transaction.php

    // Inside App\Models\Transaction.php

    public function create(array $data): bool
    {
        // Table columns: member_id, wallet_type_id, type, amount, previous_balance, running_balance, reference, description
        $sql = "INSERT INTO transactions 
        (member_id, wallet_type_id, type, amount, previous_balance, running_balance, reference, description) 
        VALUES (:member_id, :wallet_type_id, :type, :amount, :previous_balance, :running_balance, :reference, :description)";

        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute([
            'member_id'        => $data['member_id'],
            'wallet_type_id'   => $data['wallet_type_id'],
            'type'             => $data['type'],
            'amount'           => $data['amount'],
            'previous_balance' => $data['previous_balance'],
            'running_balance'  => $data['running_balance'],
            'reference'        => $data['reference'], // MUST match the key passed in TransactionService
            'description'      => $data['description']
        ]);
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
