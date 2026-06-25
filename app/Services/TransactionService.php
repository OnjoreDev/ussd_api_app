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
     * The unified gateway for all financial movements.
     */
    public function execute(
        int $memberId, 
        int $walletTypeId, 
        int $amount, 
        string $type, 
        string $description, 
        string $receipt
    ): bool {
        $this->pdo->beginTransaction();

        try {
            // 1. Attempt to lock the existing wallet or create it if it doesn't exist
            $sqlCheck = "SELECT balance FROM wallets WHERE member_id = ? AND wallet_type_id = ? FOR UPDATE";
            $stmt = $this->pdo->prepare($sqlCheck);
            $stmt->execute([$memberId, $walletTypeId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$wallet) {
                // Initialize new wallet with 0 balance if it doesn't exist
                $sqlInsert = "INSERT INTO wallets (member_id, wallet_type_id, balance) VALUES (?, ?, 0)";
                $this->pdo->prepare($sqlInsert)->execute([$memberId, $walletTypeId]);
                $prevBalance = 0;
            } else {
                $prevBalance = (int)$wallet['balance'];
            }
            
            $change = ($type === 'Credit') ? $amount : -$amount;
            $runningBalance = $prevBalance + $change;

            // 2. Log in transactions ledger (Now includes wallet_type_id)
            $this->transactionModel->create([
                'member_id'        => $memberId,
                'wallet_type_id'   => $walletTypeId,
                'type'             => $type,
                'debit'            => ($type === 'Debit') ? $amount : 0,
                'credit'           => ($type === 'Credit') ? $amount : 0,
                'previous_balance' => $prevBalance,
                'running_balance'  => $runningBalance,
                'payment_receipt'  => $receipt,
                'description'      => $description
            ]);

            // 3. Update the balance
            $sqlUpdate = "UPDATE wallets SET balance = ? WHERE member_id = ? AND wallet_type_id = ?";
            $this->pdo->prepare($sqlUpdate)->execute([$runningBalance, $memberId, $walletTypeId]);

            $this->pdo->commit();
            $this->logger->info("Transaction successful", ['receipt' => $receipt]);
            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error("Transaction failed: " . $e->getMessage());
            return false;
        }
    }
}