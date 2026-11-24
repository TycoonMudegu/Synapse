<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\AccountRepository;
use App\Repositories\PaymentRepository;
use App\Services\Billing\MpesaService;
use App\Services\Billing\PromoCodeService;
use App\Services\Security\Auth;

final class BillingController
{
    public function index(Request $request): void
    {
        View::render(
            'pages/plans',
            [
                'title' => 'Plans',
                'active' => 'plans',
                'membership' => (new AccountRepository())->membership(Auth::id()),
                'payment' => (new PaymentRepository())->latest(Auth::id()),
                'checkoutReady' => MpesaService::configured(),
            ],
            empty(Auth::user()['onboarded_at']) ? 'onboarding' : 'app',
        );
    }

    public function redeem(Request $request): void
    {
        (new \App\Repositories\RateLimitRepository())->check(Auth::id(), 'promo_codes', 5, 300);
        (new PromoCodeService())->redeem(Auth::id(), $request->string('code'));
        Response::json([
            'ok' => true,
            'message' => 'Lifetime Synapse Plus is active. No payment is required.',
            'redirect' => '/plans',
        ]);
    }

    public function start(Request $request): void
    {
        (new \App\Repositories\RateLimitRepository())->check(Auth::id(), 'payments', 5);
        $id = (new MpesaService())->start(
            Auth::id(),
            $request->string('plan'),
            $request->string('phone'),
        );
        Response::json([
            'ok' => true,
            'message' => 'Check your phone and enter your M-Pesa PIN.',
            'redirect' => '/plans?payment=' . $id,
        ]);
    }

    public function check(Request $request, int $id): void
    {
        $result = (new MpesaService())->check((new PaymentRepository())->get(Auth::id(), $id));
        Response::json([
            'ok' => true,
            'payment' => $result,
            'message' =>
                $result['status'] === 'paid'
                    ? 'Payment confirmed. Synapse Plus is ready.'
                    : ($result['status'] === 'failed'
                        ? 'Payment was not completed. You can try again.'
                        : 'Still waiting for M-Pesa confirmation.'),
            'reload' => in_array($result['status'], ['paid', 'failed'], true),
        ]);
    }

    public function callback(Request $request): void
    {
        try {
            (new MpesaService())->callback(
                $request->all(),
                is_string($_GET['token'] ?? null) ? $_GET['token'] : '',
            );
        } catch (\Throwable) {
            // Browser status checks can reconcile a temporarily unavailable query.
            error_log('M-Pesa callback verification deferred.');
        }
        Response::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
