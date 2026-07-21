<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class LoanRequest extends Model
{
    /**
     * Create a new pending loan request
    */
    public function createPending(int $memberId, int $walletTypeId, int $amount): bool
    {
        // Now the table has the 'wallet_type_id' column, so this query will succeed
        $stmt = $this->pdo->prepare("
        INSERT INTO loan_requests (member_id, wallet_type_id, amount, status, approved_by, created_at) 
        VALUES (?, ?, ?, 'pending', 0, NOW())
    ");
        return $stmt->execute([$memberId, $walletTypeId, $amount]);
    }

    /**
     * Check if a member already has a pending loan request
     */

    public function hasPendingRequest(int $memberId): bool
    {

        $stmt = $this->pdo->prepare("
        SELECT COUNT(*) FROM loan_requests 
        WHERE member_id = ? AND status = 'pending'
    ");
        $stmt->execute([$memberId]);
        $count = (int) $stmt->fetchColumn();

        return $count > 0;
    }

    /**
     * Retrieve a specific loan request by ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM loan_requests WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findLatestByMember(int $memberId): ?array
    {
        $stmt = $this->pdo->prepare("
        SELECT * FROM loan_requests 
        WHERE member_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
        $stmt->execute([$memberId]);

        // Returns the row as an associative array, or null if no record exists
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Updates the status of a loan request
     */

    // public function updateStatus(int $loanId, string $status, int $adminId): bool
    // {
    //     $sql = "UPDATE loan_requests SET status = ?, approved_by = ? WHERE id = ?";
    //     $stmt = $this->pdo->prepare($sql);
    //     return $stmt->execute([$status, $adminId, $loanId]);
    // }
    public function updateStatus(int $id, string $status, array $data): bool
{
    // Ensure these columns exist in your loan_requests table
    $sql = "UPDATE loan_requests 
            SET status = :status, 
                conversation_id = :conv_id, 
                approved_by = :admin_id 
            WHERE id = :id";
            
    $stmt = $this->pdo->prepare($sql);
    return $stmt->execute([
        'status'   => $status,
        'conv_id'  => $data['conversation_id'] ?? null,
        'admin_id' => $data['approved_by'] ?? null,
        'id'       => $id
    ]);
}

    /**
     * Updates ONLY the status field, leaving approved_by completely untouched
     */
    public function updateWebhookStatus(int $loanId, string $status): bool
    {
        $sql = "UPDATE loan_requests SET status = ? WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$status, $loanId]);
    }
    public function findByConversationId(string $conversationId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM loan_requests WHERE conversation_id = ? LIMIT 1");
        $stmt->execute([$conversationId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }
}
