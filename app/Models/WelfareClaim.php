<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class WelfareClaim extends Model
{
    /**
     * Inserts a new claim. 
     * Note: Your database expects a unique tracking_number.
     */
    public function create(int $memberId, string $claimType, string $relationship = 'self'): bool
    {
        $trackingNumber = $this->generateTrackingNumber($claimType);
        
        $sql = "INSERT INTO welfare_claims 
                (member_id, claim_type, relationship, tracking_number, status, amount_eligible, notes) 
                VALUES (?, ?, ?, ?, 'pending_docs', 0.00, '')";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$memberId, $claimType, $relationship, $trackingNumber]);
    }

    /**
     * Fetches all claims for a specific member ID.
     */
    public function findByMemberId(int $memberId): array
    {
        $sql = "SELECT tracking_number, status FROM welfare_claims WHERE member_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$memberId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Generates a unique tracking number format (e.g., MED-XXXXX)
     */
    private function generateTrackingNumber(string $claimType): string
    {
        $prefix = ($claimType === 'medical') ? 'MED' : 'BER';
        $random = strtoupper(bin2hex(random_bytes(3))); // Generates 6-char random string
        return $prefix . '-' . $random;
    }
}