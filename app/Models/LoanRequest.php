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
        $stmt = $this->pdo->prepare("
            INSERT INTO loan_requests (member_id, wallet_type_id, amount, status, approved_by, created_at) 
            VALUES (?, ?, ?, 'pending', 0, NOW())
        ");

        return $stmt->execute([$memberId, $walletTypeId, $amount]);
    }

    /**
     * Check if a member already has a pending loan request
     */
    // public function hasPendingRequest(int $memberId): bool
    // {
    //     $stmt = $this->pdo->prepare("
    //         SELECT COUNT(*) FROM loan_requests 
    //         WHERE member_id = ? AND status = 'pending'
    //     ");
    //     $stmt->execute([$memberId]);

    //     return (int) $stmt->fetchColumn() > 0;
    // }
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
}
