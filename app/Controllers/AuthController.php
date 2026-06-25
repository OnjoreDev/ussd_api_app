<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Member;
use App\Services\SmsService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController extends Controller
{
    private Member $member;
    private SmsService $smsService;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        // Resolve Member model and the new SmsService from the container
        $this->member = $container->get(Member::class);
        $this->smsService = $container->get(SmsService::class);
    }

    // 1. REGISTER: Create initial record


    // In your backend AuthController.php, update the register method:
    public function register(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $phone = $data['phone'] ?? '';
        $name = $data['name'] ?? '';
        $vocation = $data['vocation'] ?? '';

        // GUARD: Block numeric-only names
        if (empty($name) || is_numeric($name) || strlen($name) < 3) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Invalid name format.'], 400);
        }

        // Generate OTP
        $otp = str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $hashedOtp = password_hash($otp, PASSWORD_BCRYPT);

        // 1. Call the model method to save the member
        $isCreated = $this->member->insertWithOtp($name, $phone, $vocation, $hashedOtp, $expiry);

        if ($isCreated) {
            // 2. Fetch the newly created member to get the ID for wallet initialization
            $newMember = $this->member->findByPhone($phone);

            if ($newMember) {
                // 3. Initialize the 4 wallets for this member
                $this->member->initializeWallets((int)$newMember['id']);
            }

            // 4. Send SMS
            $this->smsService->sendSMS($phone, "Your OTP is: $otp");
            return $this->jsonResponse($response, ['status' => 'success']);
        }

        return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Failed to save record'], 500);
    }

    // 2. REQUEST OTP: Trigger SMS and update DB
    public function requestOtp(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $otp = (string)random_int(1000, 9999);
        $hashedOtp = password_hash($otp, PASSWORD_BCRYPT);
        $expiry = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        $this->member->updateOtp($data['phone'], $hashedOtp, $expiry);

        // Use the injected SmsService to send the message
        $this->smsService->sendSMS($data['phone'], "Your verification code is: $otp");

        return $this->jsonResponse($response, ['status' => 'success', 'message' => 'OTP sent']);
    }

    // 3. VERIFY OTP: Validate and generate Auth Token


    public function verifyOtp(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $phone = $data['phone'] ?? '';
        $inputOtp = $data['otp'] ?? '';

        $user = $this->member->findByPhone($phone);

        // 1. Check if user exists
        if (!$user) {
            $this->logger->error("OTP Verification Failed: User not found", ['phone' => $phone]);
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'User not found'], 404);
        }

        // 2. Check if OTP is expired
        if (strtotime($user['otp_expires_at']) < time()) {
            $this->logger->warning("OTP Verification Failed: Code expired", ['phone' => $phone, 'expires_at' => $user['otp_expires_at']]);
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'OTP expired'], 401);
        }

        // 3. Verify Hash
        if (password_verify($inputOtp, $user['otp_code'])) {
            $token = bin2hex(random_bytes(32));
            $this->member->verifyAndActivate($phone, $token);

            $this->logger->info("OTP Verification Success", ['phone' => $phone]);
            return $this->jsonResponse($response, ['status' => 'success', 'token' => $token]);
        }

        // 4. Handle hash mismatch
        $this->logger->error("OTP Verification Failed: Hash mismatch", ['phone' => $phone, 'input_attempt' => $inputOtp]);
        return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Invalid OTP'], 401);
    }
    // 4. SET PIN: Capture user's chosen PIN after registration
    // In AuthController.php -> setPin()
    public function setPin(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $phone = $data['phone'] ?? '';
        $pin = $data['pin'] ?? '';

        if (empty($phone) || empty($pin)) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Missing data'], 400);
        }

        $hashedPin = password_hash($pin, PASSWORD_BCRYPT);
        // Fix: Ensure updatePin exists in Member model and actually executes the SQL
        $this->member->updatePin($phone, $hashedPin);

        return $this->jsonResponse($response, ['status' => 'success']);
    }

    // 5. LOGIN: Authenticate using existing phone and PIN
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $user = $this->member->findByPhone($data['phone']);

        if ($user && password_verify($data['pin'], $user['pin_hash'])) {
            $token = bin2hex(random_bytes(32));
            $this->member->updateToken($data['phone'], $token);

            return $this->jsonResponse($response, ['status' => 'success', 'token' => $token]);
        }

        return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Invalid PIN'], 401);
    }
}
