<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\SessionController;
use App\Controllers\MemberController;
use App\Controllers\LoanController;
use App\Controllers\WelfareClaimController;
use App\Controllers\WithdrawalController;
use App\Controllers\MainAccountController;
use App\Controllers\ChamaPointsController;
use App\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {

    $app->group('/api/v1', function (RouteCollectorProxy $group) {

        // 1. AUTHENTICATION (Public)
        $group->post('/auth/register', [AuthController::class, 'register']);
        $group->post('/auth/request-otp', [AuthController::class, 'requestOtp']);
        $group->post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
        $group->post('/auth/set-pin', [AuthController::class, 'setPin']);
        $group->post('/auth/login', [AuthController::class, 'login']);

        // 2. SESSION (Public)
        $group->post('/session/create', [SessionController::class, 'create']);
        $group->post('/session/update-state', [SessionController::class, 'updateState']);
        $group->post('/session/update-input', [SessionController::class, 'updateInput']);
        $group->get('/session/get-level/{phone}', [SessionController::class, 'getLevel']);
        $group->get('/session/get-inputs/{sessionId}', [SessionController::class, 'getInputs']);

        // 3. PUBLIC MEMBER OPERATIONS
        $group->get('/member/check-registration/{phone}', [MemberController::class, 'checkRegistration']);
        $group->get('/member/customer-care-details', [MemberController::class, 'getCustomerCareDetails']);

        // 4. PROTECTED FINANCIAL OPERATIONS (Requires AuthMiddleware)
        $group->group('', function (RouteCollectorProxy $secure) {

            // Member Dashboard & Balances
            $secure->get('/member/dashboard', [MemberController::class, 'getDashboard']);
            $secure->get('/member/balances', [MemberController::class, 'getBalances']);
            $secure->get('/member/find-by-phone/{phone}', [MemberController::class, 'findByPhone']);

            // Loan Operations
            $secure->post('/loan/request', [LoanController::class, 'requestLoan']);
            $secure->post('/loan/disburse', [LoanController::class, 'disburseLoan']);
            $secure->get('/loan/status', [LoanController::class, 'getLoanStatus']);

            // Welfare Operations
            $secure->get('/welfare/claims', [WelfareClaimController::class, 'getClaims']);
            $secure->post('/welfare/claim', [WelfareClaimController::class, 'createClaim']);
            $secure->post('/welfare/deposit', [WelfareClaimController::class, 'deposit']);
            $secure->get('/welfare/status', [WelfareClaimController::class, 'getStatus']);

            // Withdrawal Operations
            $secure->post('/withdraw', [WithdrawalController::class, 'withdraw']);

            // Main Account & Chama
            $secure->post('/main/deposit', [MainAccountController::class, 'deposit']);
            $secure->post('/chama/points/add', [ChamaPointsController::class, 'addPoints']);

        })->add(AuthMiddleware::class);
    });
};