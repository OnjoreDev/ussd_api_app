<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class MembershipTier extends Model
{
    private string $dbtable = 'membership_tiers';

    /**
     * Create a new membership tier.
     */
    public function create(string $name, float $annual_fee): bool
    {
        $sql = "INSERT INTO " . $this->dbtable . " (name, annual_fee) VALUES (?, ?)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$name, $annual_fee]);
    }

    /**
     * Find all registered membership tiers.
     */
    public function findAll(): array
    {
        $sql = "SELECT * FROM " . $this->dbtable . " ORDER BY id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $res ?: [];
    }

    /**
     * Find a tier by its primary ID.
     */
    public function findById(int $id): ?array
    {
        $sql = "SELECT * FROM " . $this->dbtable . " WHERE id = ? LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);

        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    /**
     * Find a tier by its exact name (e.g., 'Premium', 'Digital').
     */
    public function findByName(string $name): ?array
    {
        $sql = "SELECT * FROM " . $this->dbtable . " WHERE name = ? LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$name]);

        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    /**
     * Update an existing membership tier.
     */
    public function update(int $id, string $name, float $annual_fee): bool
    {
        $sql = "UPDATE " . $this->dbtable . " SET name = ?, annual_fee = ? WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$name, $annual_fee, $id]);
    }

    /**
     * Remove a membership tier.
     * Note: This will fail cleanly if restricted by a foreign key constraint (members assigned to it)
     */
    public function delete(int $id): bool
    {
        $sql = "DELETE FROM " . $this->dbtable . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$id]);
    }
}
