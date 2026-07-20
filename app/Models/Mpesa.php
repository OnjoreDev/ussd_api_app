<?php

declare(strict_types=1);

namespace App\Models;

class Mpesa extends Model
{
    /**
     * Store the initial pending STK push payload into the database.
     * Matches schema constraints for foreign keys and types.
     * * @param array $data
     * @return bool
     */
    public function createTransaction(array $data): bool
    {
        // Use INSERT IGNORE to prevent the 1062 error if the ID already exists
        $sql = "INSERT IGNORE INTO mpesa_transactions 
            (member_id, wallet_type_id, amount, phone, checkout_request_id, merchant_request_id, status) 
            VALUES 
            (:member_id, :wallet_type_id, :amount, :phone, :checkout_request_id, :merchant_request_id, 'pending')";

        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute([
            ':member_id'           => (int) $data['member_id'],
            ':wallet_type_id'      => (int) $data['wallet_type_id'],
            ':amount'              => (float) $data['amount'],
            ':phone'               => (string) $data['phone_number'],
            ':checkout_request_id' => (string) $data['checkout_request_id'],
            ':merchant_request_id' => (string) $data['merchant_request_id']
        ]);
    }

    /**
     * Update transaction status using the unique checkout_request_id key.
     * * @param string $checkoutRequestId
     * @param string $status ('completed' or 'failed')
     * @param string|null $receiptNumber
     * @return bool
     */
    public function updateTransactionStatus(string $checkoutRequestId, string $status, ?string $receiptNumber = null): bool
    {
        $sql = "UPDATE mpesa_transactions 
                SET status = :status, mpesa_receipt_number = :receipt_number 
                WHERE checkout_request_id = :checkout_request_id";

        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute([
            ':status'              => $status,
            ':receipt_number'      => $receiptNumber,
            ':checkout_request_id' => $checkoutRequestId
        ]);
    }

    /**
     * Retrieve transaction details by CheckoutRequestID
     */
    public function findByCheckoutRequestId(string $checkoutRequestId): ?array
    {
        $sql = "SELECT member_id, wallet_type_id, amount 
            FROM mpesa_transactions 
            WHERE checkout_request_id = :checkout_request_id 
            LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':checkout_request_id' => $checkoutRequestId]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    // Add to Mpesa.php
    public function hasPendingTransaction(int $memberId, int $walletTypeId): bool
    {
        // Check for pending transactions created in the last 5 minutes
        $stmt = $this->pdo->prepare("SELECT id FROM mpesa_transactions 
                             WHERE member_id = ? AND wallet_type_id = ? 
                             AND status = 'pending' 
                             AND created_at > NOW() - INTERVAL 5 MINUTE
                             LIMIT 1");
        $stmt->execute([$memberId, $walletTypeId]);
        return (bool) $stmt->fetch();
    }
}
