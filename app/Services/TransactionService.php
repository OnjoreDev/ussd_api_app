<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transaction;
use Monolog\Logger;
use PDO;
use Exception;

class TransactionService
{
    private PDO $pdo;
    private Transaction $transactionModel;
    private Logger $logger;

    public function __construct(PDO $pdo, Transaction $transactionModel, Logger $logger)
    {
        $this->pdo = $pdo;
        $this->transactionModel = $transactionModel;
        $this->logger = $logger;
    }

    /**
     * Executes an atomic financial transaction.
     * Updates the wallet balance and records the ledger entry.
     */
    public function execute(int $memberId, int $walletTypeId, int $amount, string $reference, string $description): bool
{
    try {
        $this->pdo->beginTransaction();

        // 1. Attempt to get the wallet, using FOR UPDATE to lock it
        $stmt = $this->pdo->prepare("SELECT balance FROM wallets WHERE member_id = ? AND wallet_type_id = ? FOR UPDATE");
        $stmt->execute([$memberId, $walletTypeId]);
        $wallet = $stmt->fetch();

        if ($wallet) {
            $previousBalance = $wallet['balance'];
        } else {
            // If no wallet exists, initialize it at 0
            $previousBalance = 0;
            $this->pdo->prepare("INSERT INTO wallets (member_id, wallet_type_id, balance) VALUES (?, ?, 0)")
                     ->execute([$memberId, $walletTypeId]);
        }

        $newBalance = $previousBalance + $amount;

        // 2. Log to Transactions
        $stmt = $this->pdo->prepare("INSERT INTO transactions 
            (member_id, wallet_type_id, type, amount, previous_balance, running_balance, reference, description) 
            VALUES (?, ?, 'Credit', ?, ?, ?, ?, ?)");
        $stmt->execute([$memberId, $walletTypeId, $amount, $previousBalance, $newBalance, $reference, $description]);

        // 3. Update the Balance
        $stmt = $this->pdo->prepare("UPDATE wallets SET balance = ? WHERE member_id = ? AND wallet_type_id = ?");
        $stmt->execute([$newBalance, $memberId, $walletTypeId]);

        $this->pdo->commit();
        return true;

    } catch (Exception $e) {
        $this->pdo->rollBack();
        throw $e;
    }
}
}