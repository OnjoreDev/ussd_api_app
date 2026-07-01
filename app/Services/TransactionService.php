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
     * @param int $amount Points to be debited/credited.
     * @param string $type Either 'Credit' or 'Debit'.
     */
    // In TransactionService.php -> execute()

    public function execute(int $memberId, int $walletTypeId, int $amount, string $type, string $reference, string $description): bool
    {
        try {
            $this->pdo->beginTransaction();

            // 1. Lock the current wallet balance (Raw points)
            $stmt = $this->pdo->prepare("SELECT balance FROM wallets WHERE member_id = ? AND wallet_type_id = ? FOR UPDATE");
            $stmt->execute([$memberId, $walletTypeId]);
            $wallet = $stmt->fetch();

            $previousPoints = $wallet ? (int)$wallet['balance'] : 0;

            // 2. Calculate New Balance (RAW POINTS - no multiplication here)
            $newPoints = ($type === 'Debit') ? ($previousPoints - $amount) : ($previousPoints + $amount);

            // 3. Prepare Ledger Values (Apply * 10 ONLY for the transaction log)
            $ledgerAmount = $amount * 10;
            $ledgerPrev   = $previousPoints * 10;
            $ledgerNew    = $newPoints * 10;

            // 4. Log to Transactions table (uses multiplied KSH values)
            $stmt = $this->pdo->prepare("INSERT INTO transactions 
            (member_id, wallet_type_id, type, amount, previous_balance, running_balance, reference, description) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->execute([$memberId, $walletTypeId, $type, $ledgerAmount, $ledgerPrev, $ledgerNew, $reference, $description]);

            // 5. Update the Wallet Balance (USE RAW POINTS)
            // This is the specific line that ensures your wallet table stays correct
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
