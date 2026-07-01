<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class SessionModel extends Model
{
    public function getLevel(string $sessionId): ?string
    {
        // Now accurately fetches the state for the unique session
        $stmt = $this->pdo->prepare("SELECT temp_level FROM ussd_inbox WHERE session_id = ? LIMIT 1");
        $stmt->execute([$sessionId]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $row['temp_level'] : null;
    }

    public function setLevel(string $sessionId, string $level): void
    {
        // Atomically updates the current state of the existing session
        $stmt = $this->pdo->prepare("
            UPDATE ussd_inbox 
            SET temp_level = ? 
            WHERE session_id = ?
        ");
        $stmt->execute([$level, $sessionId]);
    }

    // public function createSession(string $sessionId, string $msisdn, string $ussdCode): bool
    // {
    //     // Using standard INSERT. Since we have a unique index on session_id,
    //     // this will safely create a new session tracking row.
    //     $stmt = $this->pdo->prepare("
    //         INSERT INTO ussd_inbox (session_id, msisdn, shortcode, temp_level, message) 
    //         VALUES (:session_id, :msisdn, :shortcode, :temp_level, :message)
    //     ");
    //     return $stmt->execute([
    //         ':session_id' => $sessionId,
    //         ':msisdn'     => $msisdn,
    //         ':shortcode'  => $ussdCode,
    //         ':temp_level' => 'InitialGateway', // Changed from 'MemberMainMenu' to secure entry
    //         ':message'    => $ussdCode
    //     ]);
    // }
    public function createSession(string $sessionId, string $msisdn, string $ussdCode): bool
    {
        // Use ON DUPLICATE KEY UPDATE to handle potential retries gracefully
        $stmt = $this->pdo->prepare("
        INSERT INTO ussd_inbox (session_id, msisdn, shortcode, temp_level, message) 
        VALUES (:session_id, :msisdn, :shortcode, :temp_level, :message)
        ON DUPLICATE KEY UPDATE 
            temp_level = VALUES(temp_level), 
            message = VALUES(message)
    ");

        return $stmt->execute([
            ':session_id' => $sessionId,
            ':msisdn'     => $msisdn,
            ':shortcode'  => $ussdCode,
            ':temp_level' => 'InitialGateway',
            ':message'    => $ussdCode
        ]);
    }

    public function saveInput(string $sessionId, string $input): bool
    {
        // Using IFNULL prevents leading pipes if the message column is empty
        $stmt = $this->pdo->prepare("
            UPDATE ussd_inbox 
            SET message = CONCAT(IFNULL(message, ''), '|', ?) 
            WHERE session_id = ?
        ");
        return $stmt->execute([$input, $sessionId]);
    }

    public function getAllInputs(string $sessionId): array
    {
        // Since each session is now one row, this returns an array containing one string
        $stmt = $this->pdo->prepare("SELECT message FROM ussd_inbox WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? explode('|', $row['message']) : [];
    }
}
