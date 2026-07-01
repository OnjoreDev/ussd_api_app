<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Member;
use App\Models\SessionModel; // Add this
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\SmsService;
use Slim\Routing\RouteContext;


class MemberController extends Controller
{
    private Member $member;
    private SessionModel $session; // Add this property
    private SmsService $smsService; // Add this property

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->member = $container->get(Member::class);
        $this->session = $container->get(SessionModel::class);
        $this->smsService = $container->get(SmsService::class); // Inject
    }

    // Example endpoint to get profile/balance info
    public function getDashboard(Request $request, Response $response): Response
    {
        // Get the phone from the request (or token)
        $params = $request->getQueryParams();
        $phone = $params['phone'] ?? '';

        $user = $this->member->findByPhone($phone);

        if (!$user) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Member not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Return structured data for the USSD client to render
        $payload = [
            'name' => $user['name'],
            'balance' => '500.00', // You would link this to your Transactions/Ledger logic
            'vocation' => $user['vocation']
        ];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // MemberController.php

    public function checkRegistration(Request $request, Response $response): Response
    {
        // Use getAttribute to safely get the 'phone' from the route placeholder
        $phone = $request->getAttribute('phone');

        if (!$phone) {
            $response->getBody()->write(json_encode(['error' => 'Phone number missing']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $user = $this->member->findByPhone($phone);

        $status = ['registered' => ($user !== null)];

        $response->getBody()->write(json_encode($status));
        return $response->withHeader('Content-Type', 'application/json');
    }
    // In MemberController.php
    public function getCustomerCareDetails(Request $request, Response $response): Response
    {
        // Capture phone from query parameters
        $params = $request->getQueryParams();
        $phone = $params['phone'] ?? '';

        $message = "Hello, your inquiry is being handled. For urgent support, please call 0797047166. - Jua Kali CBO";

        // Trigger the SMS via your existing Service
        $this->smsService->sendSMS($phone, $message);

        return $this->jsonResponse($response, [
            'status' => 'success',
            'phone' => '0797047166'
        ]);
    }

    // Inside App\Controllers\MemberController.php

    public function findByPhone(Request $request, Response $response): Response
    {
        $routeContext = RouteContext::fromRequest($request);
        $args = $routeContext->getRoute()->getArguments();
        $phone = $args['phone'] ?? '';

        $member = $this->member->findByPhone($phone);

        if (!$member) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Return the record directly (flat structure)
        $response->getBody()->write(json_encode($member));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // App\Controllers\MemberController.php

    public function getBalances(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $phone = $params['phone'] ?? '';

        $user = $this->member->findByPhone($phone);
        if (!$user) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Member not found'], 404);
        }

        $wallets = $this->member->getWalletsByMemberId((int)$user['id']);

        if (!empty($wallets)) {
            $msg = "Your Jua Kali CBO Balances:\n";
            foreach ($wallets as $w) {
                // Use strtolower and trim to ensure the comparison works regardless of casing
                $walletName = strtolower(trim((string)$w['wallet_name']));
                $symbol = ($walletName === 'chama points') ? 'Pts' : 'KES';

                $msg .= ucfirst($w['wallet_name']) . ": {$symbol} " . number_format((float)$w['balance'], 0) . "\n";
            }
            // Send SMS
            $this->smsService->sendSMS($phone, $msg);
        }

        return $this->jsonResponse($response, [
            'status' => 'success',
            'wallets' => $wallets
        ]);
    }

    //check if user has a specific role
    public function checkRole(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $phone = $params['phone'] ?? '';
        $roleName = $params['role'] ?? '';

        $member = $this->member->findByPhone($phone);
        if (!$member) {
            return $this->jsonResponse($response, ['has_role' => false]);
        }

        $hasRole = $this->member->hasRole((int)$member['id'], $roleName);
        return $this->jsonResponse($response, ['has_role' => $hasRole]);
    }
}
