<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Member extends Model
{
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM members WHERE id=?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByPhone(string $phone): ?array
    {
        $stmt = $this->pdo->prepare("
        SELECT 
            m.*, 
            t.name as tier_name, 
            t.annual_fee as tier_fee
        FROM members m
        JOIN membership_tiers t ON m.membership_tier_id = t.id
        WHERE m.phone = ? 
        LIMIT 1
    ");
        $stmt->execute([$phone]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(string $name, string $phone, string $vocation): bool
    {
        $default_membership_tier = 1; //Digital Member default for all members
        $stmt = $this->pdo->prepare("INSERT INTO members (name, membership_tier_id,phone, vocation) VALUES (?,?, ?, ?)");
        return $stmt->execute([$name, $default_membership_tier, $phone, $vocation]);
    }

    public function updateOtp(string $phone, string $hashedOtp, string $expiry): void
    {
        $stmt = $this->pdo->prepare("UPDATE members SET otp_code = ?, otp_expires_at = ? WHERE phone = ?");
        $stmt->execute([$hashedOtp, $expiry, $phone]);
    }

    public function updatePin(string $phone, string $hashedPin): void
    {
        $stmt = $this->pdo->prepare("UPDATE members SET pin_hash = ? WHERE phone = ?");
        $stmt->execute([$hashedPin, $phone]);
    }

    public function verifyAndActivate(string $phone, string $token): bool
    {
        $expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $stmt = $this->pdo->prepare("UPDATE members SET is_verified = 1, api_token = ?, token_expires_at = ? WHERE phone = ?");
        return $stmt->execute([$token, $expiry, $phone]);
    }

    public function updateToken(string $phone, string $token): bool
    {
        $expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $stmt = $this->pdo->prepare("UPDATE members SET api_token = ?, token_expires_at = ? WHERE phone = ?");
        return $stmt->execute([$token, $expiry, $phone]);
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, is_verified, token_expires_at FROM members WHERE api_token = ? AND token_expires_at > NOW()");
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function registerMember(array $data): bool
    {
        $stmt = $this->pdo->prepare("INSERT INTO members (phone, name, vocation, created_at) VALUES (?, ?, ?, NOW())");
        return $stmt->execute([$data['phone'], $data['name'], $data['vocation']]);
    }

    public function insertWithOtp(string $name, string $phone, string $vocation, string $hashedOtp, string $expiry): bool
    {
        // Standard insert: If phone exists, this will return false (due to UNIQUE constraint)
        $stmt = $this->pdo->prepare("
        INSERT INTO members (name, phone, vocation, otp_code, otp_expires_at) 
        VALUES (?, ?, ?, ?, ?)
    ");
        return $stmt->execute([$name, $phone, $vocation, $hashedOtp, $expiry]);
    }

    //set member balances to 0
    public function initializeWallets(int $memberId): void
    {
        // You have 4 placeholders (one for each wallet_type_id)
        $stmt = $this->pdo->prepare("
        INSERT INTO wallets (member_id, wallet_type_id, balance) 
        VALUES (?, 1, 0), (?, 2, 0), (?, 3, 0), (?, 4, 0)
    ");

        // You MUST pass $memberId 4 times to match the 4 placeholders
        $stmt->execute([$memberId, $memberId, $memberId, $memberId]);
    }

    /**
     * Gets all wallets for a specific member with their type names.
     */
    public function getWalletsByMemberId(int $memberId): array
    {
        $sql = "SELECT w.balance, wt.name as wallet_name, w.wallet_type_id
        FROM wallets w
        JOIN wallet_types wt ON w.wallet_type_id = wt.id
        WHERE w.member_id = ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$memberId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    //check the roles a member has

    public function hasRole(int $memberId, string $roleName): bool
    {
        $sql = "SELECT COUNT(*) 
            FROM member_roles mr
            JOIN roles r ON mr.role_id = r.id
            WHERE mr.member_id = ? AND r.name = ? LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$memberId, $roleName]);

        // Returns true if count is greater than 0
        return (int)$stmt->fetchColumn() > 0;
    }
}
