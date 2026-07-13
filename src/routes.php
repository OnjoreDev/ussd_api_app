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
use App\Controllers\MpesaResponseController;
use App\Controllers\MpesaController;
use App\Controllers\MembershipTierController;
use App\Middleware\AgentMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {

    //mpesa response callback outside the auth group
    $app->post('/api/v1/payment-hook', [MpesaResponseController::class, 'handleCallback']);

    $app->group('/api/v1', function (RouteCollectorProxy $group) {

        // 1. AUTHENTICATION (Public)
        $group->post('/auth/register', [AuthController::class, 'register']);
        $group->post('/auth/request-otp', [AuthController::class, 'requestOtp']);
        $group->post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
        $group->post('/auth/set-pin', [AuthController::class, 'setPin']);
        $group->post('/auth/login', [AuthController::class, 'login']);
        $group->post('/auth/change-pin', [AuthController::class, 'changePin']);
        $group->post('/auth/verify-pin', [AuthController::class, 'verifyPin']);


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
            $secure->get('/member/has-role', [MemberController::class, 'checkRole']);
            $secure->get('/member/balance/welfare', [MemberController::class, 'getMemberWelfareBalance']);
            $secure->get('/member/balance/main', [MemberController::class, 'getMemberMainAccountBalance']);
            $secure->get('/member/balance/loan', [MemberController::class, 'getMemberLoanBalance']);
            $secure->get('/member/balance/chama', [MemberController::class, 'getMemberChamaPointsBalance']);

            // Membership Tiers CRUD Actions
            $secure->get('/membership-tiers', [MembershipTierController::class, 'index']);
            $secure->get('/membership-tiers/{id}', [MembershipTierController::class, 'show']);
            $secure->post('/membership-tiers', [MembershipTierController::class, 'create']);
            $secure->put('/membership-tiers/{id}', [MembershipTierController::class, 'update']);
            $secure->delete('/membership-tiers/{id}', [MembershipTierController::class, 'delete']);

            // Loan Operations
            $secure->post('/loan/request', [LoanController::class, 'requestLoan']);
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
            $secure->get('/main/main-balance', [MainAccountController::class, 'getMainWalletBalance']);

            // Chama Points Operations
            $secure->get('/chama/points/balance/{member_id}', [ChamaPointsController::class, 'getBalanceAction']);
            $secure->post('/chama/points/redeem', [ChamaPointsController::class, 'redeemAction']);
            $secure->post('/chama/points/add', [ChamaPointsController::class, 'addPoints'])
                ->add(AgentMiddleware::class);
            $secure->post('/chama/points/withdraw', ChamaPointsController::class . 'withdrawPoints')
                ->add(AgentMiddleware::class);

        })->add(AuthMiddleware::class);
    });
};
