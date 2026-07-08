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
     * * @param int $memberId
     * @param int $walletTypeId
     * @param float $amount Money points to be debited/credited (FIXED: int to float parameter casting)
     * @param string $type Either 'Credit' or 'Debit'.
     * @param string $reference
     * @param string $description
     * @return bool
     */
    public function execute(int $memberId, int $walletTypeId, float $amount, string $type, string $reference, string $description): bool
    {
        try {
            $this->pdo->beginTransaction();

            // 1. Lock the current wallet balance (Raw points)
            $stmt = $this->pdo->prepare("SELECT balance FROM wallets WHERE member_id = ? AND wallet_type_id = ? FOR UPDATE");
            $stmt->execute([$memberId, $walletTypeId]);
            $wallet = $stmt->fetch();

            // FIXED: Safe floating point extraction instead of strict int cast truncation
            $previousPoints = $wallet ? (float)$wallet['balance'] : 0.0;

            // 2. Calculate New Balance (RAW POINTS - no multiplication here)
            $newPoints = ($type === 'Debit') ? ($previousPoints - $amount) : ($previousPoints + $amount);

            // 3. Prepare Ledger Values (Apply * 10 ONLY for Chama points wallet_type_id = 3)
            if ($walletTypeId === 3) {
                $ledgerAmount = $amount * 10;
                $ledgerPrev   = $previousPoints * 10;
                $ledgerNew    = $newPoints * 10;
            } else {
                $ledgerAmount = $amount;
                $ledgerPrev   = $previousPoints;
                $ledgerNew    = $newPoints;
            }

            // 4. Log to Transactions table
            $stmt = $this->pdo->prepare("INSERT INTO transactions 
            (member_id, wallet_type_id, type, amount, previous_balance, running_balance, reference, description) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->execute([$memberId, $walletTypeId, $type, $ledgerAmount, $ledgerPrev, $ledgerNew, $reference, $description]);

            // 5. Update the Wallet Balance (USE RAW POINTS)
            $stmt = $this->pdo->prepare("UPDATE wallets SET balance = ? WHERE member_id = ? AND wallet_type_id = ?");
            $stmt->execute([$newPoints, $memberId, $walletTypeId]);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error("Transaction failed: " . $e->getMessage());
            return false;
        }
    }
}