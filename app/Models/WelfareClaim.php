<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class WelfareClaim extends Model
{
    /**
     * Check if the member has an active (non-disbursed/non-rejected) claim.
     */
    public function hasActiveClaim(int $memberId): bool
    {
        $sql = "SELECT COUNT(*) FROM welfare_claims 
                WHERE member_id = ? 
                AND status NOT IN ('disbursed', 'rejected')";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$memberId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Create a new welfare claim.
     */
    public function create(int $memberId, string $claimType, string $trackingNumber): bool
    {
        $sql = "INSERT INTO welfare_claims (member_id, claim_type, tracking_number, status, amount_eligible, notes) 
                VALUES (?, ?, ?, 'pending_docs', 0.00, 'New claim submitted')";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$memberId, $claimType, $trackingNumber]);
    }

    /**
     * Find claims by member ID.
     */
    public function findByMemberId(int $memberId): array
    {
        $sql = "SELECT * FROM welfare_claims WHERE member_id = ? ORDER BY created_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$memberId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the latest welfare claim for a member
     */
    public function findLatestByMember(int $memberId): ?array
    {
        $sql = "SELECT * FROM welfare_claims 
            WHERE member_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$memberId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

}