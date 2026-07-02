<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\MembershipTier;
use Exception;

class MembershipTierController extends Controller
{
    private MembershipTier $tier;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->tier = $container->get(MembershipTier::class);
    }

    /**
     * Fetch all membership tiers.
     * Route: GET /api/v1/membership-tiers
     */
    public function index(Request $request, Response $response): Response
    {
        try {
            $tiers = $this->tier->findAll();

            return $this->jsonResponse($response, [
                "status" => "success",
                "tiers" => $tiers
            ], 200);
        } catch (Exception $e) {
            return $this->jsonResponse($response, [
                "status" => "error", 
                "message" => "Server Error: " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get details for a single specific membership tier.
     * Route: GET /api/v1/membership-tiers/{id}
     * FIXED: Changed 'array $args' to '$id' to match PHP-DI mapping.
     */
    public function show(Request $request, Response $response, $id): Response
    {
        $id = (int)$id;

        try {
            $foundTier = $this->tier->findById($id);

            if (!$foundTier) {
                return $this->jsonResponse($response, [
                    "status" => "error", 
                    "message" => "Membership tier not found."
                ], 404);
            }

            return $this->jsonResponse($response, [
                "status" => "success",
                "tier" => $foundTier
            ], 200);
        } catch (Exception $e) {
            return $this->jsonResponse($response, [
                "status" => "error", 
                "message" => "Server Error: " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new membership tier.
     * Route: POST /api/v1/membership-tiers
     */
    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $name = trim($data["name"] ?? '');
        $annualFee = $data["annual_fee"] ?? null;

        if (empty($name) || $annualFee === null || !is_numeric($annualFee)) {
            return $this->jsonResponse($response, [
                "status" => "error", 
                "message" => "Missing or invalid parameters. 'name' and numeric 'annual_fee' are required."
            ], 400);
        }

        try {
            if ($this->tier->findByName($name)) {
                return $this->jsonResponse($response, [
                    "status" => "error", 
                    "message" => "A membership tier named '{$name}' already exists."
                ], 409);
            }

            $success = $this->tier->create($name, (float)$annualFee);

            if (!$success) {
                throw new Exception("Failed to save membership tier data.");
            }

            return $this->jsonResponse($response, [
                "status" => "success",
                "message" => "Membership tier '{$name}' created successfully."
            ], 201);

        } catch (Exception $e) {
            return $this->jsonResponse($response, [
                "status" => "error", 
                "message" => "Server Error: " . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Update an existing membership tier.
     * Route: PUT /api/v1/membership-tiers/{id}
     * FIXED: Changed 'array $args' to '$id' to match PHP-DI mapping.
     */
    public function update(Request $request, Response $response, $id): Response
    {
        $id = (int)$id;
        $data = $request->getParsedBody();
        
        $name = isset($data["name"]) ? trim((string)$data["name"]) : null;
        $annualFee = $data["annual_fee"] ?? null;

        try {
            $existingTier = $this->tier->findById($id);
            if (!$existingTier) {
                return $this->jsonResponse($response, [
                    "status" => "error", 
                    "message" => "Membership tier not found."
                ], 404);
            }

            $finalName = $name ?? $existingTier['name'];
            $finalFee = ($annualFee !== null && is_numeric($annualFee)) ? (float)$annualFee : (float)$existingTier['annual_fee'];

            if (empty($finalName)) {
                return $this->jsonResponse($response, [
                    "status" => "error", 
                    "message" => "Tier name cannot be empty."
                ], 400);
            }

            $duplicateCheck = $this->tier->findByName($finalName);
            if ($duplicateCheck && (int)$duplicateCheck['id'] !== $id) {
                return $this->jsonResponse($response, [
                    "status" => "error", 
                    "message" => "Another membership tier named '{$finalName}' already exists."
                ], 409);
            }

            $success = $this->tier->update($id, $finalName, $finalFee);
            if (!$success) {
                throw new Exception("Failed to execute database record update.");
            }

            return $this->jsonResponse($response, [
                "status" => "success",
                "message" => "Membership tier updated successfully."
            ], 200);

        } catch (Exception $e) {
            return $this->jsonResponse($response, [
                "status" => "error", 
                "message" => "Server Error: " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a membership tier.
     * Route: DELETE /api/v1/membership-tiers/{id}
     * FIXED: Changed 'array $args' to '$id' to match PHP-DI mapping.
     */
    public function delete(Request $request, Response $response, $id): Response
    {
        $id = (int)$id;

        try {
            $existingTier = $this->tier->findById($id);
            if (!$existingTier) {
                return $this->jsonResponse($response, [
                    "status" => "error", 
                    "message" => "Membership tier not found."
                ], 404);
            }

            if ($id === 1 || strtolower($existingTier['name']) === 'digital') {
                return $this->jsonResponse($response, [
                    "status" => "error", 
                    "message" => "Protected Resource: Baseline 'Digital' fallback tier cannot be deleted."
                ], 403);
            }

            $this->tier->delete($id);

            return $this->jsonResponse($response, [
                "status" => "success",
                "message" => "Membership tier removed successfully."
            ], 200);

        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'Integrity constraint violation')) {
                return $this->jsonResponse($response, [
                    "status" => "error", 
                    "message" => "Cannot delete tier. Active members are currently assigned to this package subscription tier."
                ], 400);
            }

            return $this->jsonResponse($response, [
                "status" => "error", 
                "message" => "Server Error: " . $e->getMessage()
            ], 500);
        }
    }
}