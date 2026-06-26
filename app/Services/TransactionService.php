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

    public function execute(
        int $memberId, 
        int $walletTypeId, 
        int $amount, 
        string $type, 
        string $description, 
        string $reference
    ): bool {
        $this->pdo->beginTransaction();

        try {
            $sqlCheck = "SELECT balance FROM wallets WHERE member_id = ? AND wallet_type_id = ? FOR UPDATE";
            $stmt = $this->pdo->prepare($sqlCheck);
            $stmt->execute([$memberId, $walletTypeId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            $prevBalance = $wallet ? (int)$wallet['balance'] : 0;
            $change = ($type === 'Credit') ? $amount : -$amount;
            $runningBalance = $prevBalance + $change;

            // Updated to match actual table schema
            $this->transactionModel->create([
                'member_id'        => $memberId,
                'wallet_type_id'   => $walletTypeId,
                'type'             => $type,
                'amount'           => $amount,
                'previous_balance' => $prevBalance,
                'running_balance'  => $runningBalance,
                'currency'         => 'KES',
                'reference'        => $reference,
                'description'      => $description
            ]);

            $sqlUpdate = "UPDATE wallets SET balance = ? WHERE member_id = ? AND wallet_type_id = ?";
            $this->pdo->prepare($sqlUpdate)->execute([$runningBalance, $memberId, $walletTypeId]);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error("Transaction failed: " . $e->getMessage());
            return false;
        }
    }
}