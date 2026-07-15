<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class MpesaC2B extends Model
{

    //insert records into the c2b db table
    /**
     * Records the initial B2C request before calling M-Pesa.
     */
    public function createB2CTransaction(array $data): bool
    {
        // Ensure originator_conversation_id is included
        $sql = "INSERT INTO mpesa_b2c_transactions 
            (member_id, amount, phone, originator_conversation_id, conversation_id, status, created_at) 
            VALUES 
            (:member_id, :amount, :phone, :originator_id, :conversation_id, 'pending', NOW())";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'member_id'       => $data['member_id'],
            'amount'          => $data['amount'],
            'phone'           => $data['phone'],
            'originator_id'   => $data['originator_conversation_id'], // Must be unique
            'conversation_id' => $data['conversation_id'] ?? null
        ]);
    }

    /**
     * Updates the status once the M-Pesa callback is received.
     */
    public function updateB2CTransaction(string $conversationId, string $status, array $resultData): bool
    {
        $sql = "UPDATE mpesa_b2c_transactions 
                SET status = :status, 
                    result_data = :result_data, 
                    updated_at = NOW() 
                WHERE conversation_id = :conversation_id";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'status'          => $status,
            'result_data'     => json_encode($resultData),
            'conversation_id' => $conversationId
        ]);
    }
    /**
     * Find a transaction by OriginatorConversationID (used for callbacks)
     */
    public function findByOriginatorId(string $originatorId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM mpesa_b2c_transactions WHERE originator_conversation_id = ?");
        $stmt->execute([$originatorId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
