<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\SessionController;
use App\Controllers\MemberController;
use App\Controllers\LoanController;
use App\Controllers\WelfareClaimController;
use App\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {

    // Grouping all API routes under /api/v1
    $app->group('/api/v1', function (RouteCollectorProxy $group) {

        // 1. AUTHENTICATION & REGISTRATION
        $group->post('/auth/register', [AuthController::class, 'register']);
        $group->post('/auth/request-otp', [AuthController::class, 'requestOtp']);
        $group->post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
        $group->post('/auth/set-pin', [AuthController::class, 'setPin']);
        $group->post('/auth/login', [AuthController::class, 'login']);

        // 2. SESSION & USSD INBOX MANAGEMENT
        $group->post('/session/create', [SessionController::class, 'create']);
        $group->post('/session/update-state', [SessionController::class, 'updateState']);
        $group->post('/session/update-input', [SessionController::class, 'updateInput']);
        $group->get('/session/get-level/{phone}', [SessionController::class, 'getLevel']);
        // New route added below
        $group->get('/session/get-inputs/{sessionId}', [SessionController::class, 'getInputs']);

        // 3. PUBLIC MEMBER OPERATIONS
        $group->get('/member/check-registration/{phone}', [MemberController::class, 'checkRegistration']);
        $group->get('/member/customer-care-details', [MemberController::class, 'getCustomerCareDetails']);
        $group->get('/member/balances',[MemberController::class,'getBalances']);
        $group->get('/member/find-by-phone/{phone}', [MemberController::class, 'findByPhone']);

        // 4. SECURED BUSINESS OPERATIONS
        $group->group('/member', function (RouteCollectorProxy $member) {
            $member->get('/dashboard', [MemberController::class, 'getDashboard']);
            // Welfare Operations
           
        })->add(AuthMiddleware::class);

        // 5. LOAN REQUESTS HANDLING
        // Inside the /api/v1/member group...
        $group->group('/loan', function (RouteCollectorProxy $loan) {
            $loan->get('/request', [LoanController::class, 'requestLoan']);
            $loan->get('/loanStatus', [LoanController::class, 'getLoanStatus']);
        });

        // 6.WELFARE REQUESTS HANDLING
          $group->group('/welfare', function (RouteCollectorProxy $welfare) {
             $welfare->get('/claims', [WelfareClaimController::class, 'getClaims']);
             $welfare->post('/claim', [WelfareClaimController::class, 'createClaim']);
             $welfare->post('/deposit', [WelfareClaimController::class, 'deposit']);
          });        
    });
};
