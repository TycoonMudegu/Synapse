<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Core\Env;
use App\Core\HttpException;
use App\Http\HttpClient;
use App\Repositories\AccountRepository;
use App\Repositories\PaymentRepository;
use App\Services\Security\UrlGuard;

final class MpesaService
{
    public const PLANS = ['monthly' => 200, 'lifetime' => 1500];

    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    public static function configured(): bool
    {
        foreach (
            [
                'MPESA_CONSUMER_KEY',
                'MPESA_CONSUMER_SECRET',
                'MPESA_SHORTCODE',
                'MPESA_PASSKEY',
                'MPESA_CALLBACK_URL',
            ]
            as $key
        ) {
            if (Env::get($key) === '') {
                return false;
            }
        }
        return str_starts_with(Env::get('MPESA_CALLBACK_URL'), 'https://') &&
            (Env::get('MPESA_ENV', 'sandbox') === 'production' ||
                Env::get('APP_ENV', 'production') !== 'production');
    }

    public static function phone(string $phone): string
    {
        $phone = preg_replace('/[\s()+-]/', '', $phone);
        if (preg_match('/^0[17]\d{8}$/', $phone)) {
            $phone = '254' . substr($phone, 1);
        } elseif (preg_match('/^[17]\d{8}$/', $phone)) {
            $phone = '254' . $phone;
        }
        if (!preg_match('/^254[17]\d{8}$/', $phone)) {
            throw new HttpException('Enter a Kenyan M-Pesa number, such as 0712 345 678.');
        }
        return $phone;
    }

    private function base(): string
    {
        return Env::get('MPESA_ENV', 'sandbox') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    private function token(): string
    {
        $response = $this->http->json(
            'GET',
            $this->base() . '/oauth/v1/generate?grant_type=client_credentials',
            headers: [
                'Authorization: Basic ' .
                base64_encode(
                    Env::get('MPESA_CONSUMER_KEY') . ':' . Env::get('MPESA_CONSUMER_SECRET'),
                ),
            ],
        );
        return $response['access_token'] ??
            throw new HttpException('M-Pesa could not connect. Please try again later.', 502);
    }

    private function credentials(): array
    {
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Nairobi')))->format(
            'YmdHis',
        );
        return [
            'BusinessShortCode' => Env::get('MPESA_SHORTCODE'),
            'Password' => base64_encode(
                Env::get('MPESA_SHORTCODE') . Env::get('MPESA_PASSKEY') . $timestamp,
            ),
            'Timestamp' => $timestamp,
        ];
    }

    public function start(int $userId, string $plan, string $phone): int
    {
        if (!isset(self::PLANS[$plan])) {
            throw new HttpException('Choose a valid plan.');
        }
        if (!self::configured()) {
            throw new HttpException(
                'M-Pesa checkout is being set up. You can continue with the free plan.',
                503,
            );
        }
        if ((new AccountRepository())->membership($userId)['plan'] === 'lifetime') {
            throw new HttpException('Your account already has lifetime access.');
        }
        $phone = self::phone($phone);
        $callback = Env::get('MPESA_CALLBACK_URL');
        UrlGuard::validate($callback);
        if (parse_url($callback, PHP_URL_QUERY) || parse_url($callback, PHP_URL_FRAGMENT)) {
            throw new HttpException(
                'The M-Pesa callback URL needs to be configured without query parameters.',
                503,
            );
        }
        $token = $this->token();
        $secret = bin2hex(random_bytes(32));
        $repository = new PaymentRepository();
        $id = $repository->create(
            $userId,
            $plan,
            self::PLANS[$plan],
            $phone,
            Env::get('MPESA_ENV', 'sandbox'),
            $secret,
        );
        try {
            $response = $this->http->json(
                'POST',
                $this->base() . '/mpesa/stkpush/v1/processrequest',
                [
                    ...$this->credentials(),
                    'TransactionType' => Env::get(
                        'MPESA_TRANSACTION_TYPE',
                        'CustomerPayBillOnline',
                    ),
                    'Amount' => self::PLANS[$plan],
                    'PartyA' => $phone,
                    'PartyB' => Env::get('MPESA_SHORTCODE'),
                    'PhoneNumber' => $phone,
                    'CallBackURL' => $callback . '?token=' . $secret,
                    'AccountReference' => 'JF' . $id,
                    'TransactionDesc' => 'Synapse Plus',
                ],
                ['Authorization: Bearer ' . $token],
            );
            if (
                (string) ($response['ResponseCode'] ?? '') !== '0' ||
                empty($response['CheckoutRequestID']) ||
                empty($response['MerchantRequestID'])
            ) {
                throw new HttpException(
                    'M-Pesa did not accept the request. Check your phone and try again.',
                );
            }
            $repository->started(
                $id,
                $response['CheckoutRequestID'],
                $response['MerchantRequestID'],
            );
            return $id;
        } catch (\Throwable $exception) {
            $repository->failed($id);
            throw $exception;
        }
    }

    public function check(array $payment): array
    {
        $repository = new PaymentRepository();
        if ($payment['status'] === 'pending' && $repository->claimCheck((int) $payment['id'])) {
            if ($payment['environment'] !== Env::get('MPESA_ENV', 'sandbox')) {
                throw new HttpException(
                    'This payment belongs to a different M-Pesa environment. Contact the site owner.',
                );
            }
            $response = $this->http->json(
                'POST',
                $this->base() . '/mpesa/stkpushquery/v1/query',
                [...$this->credentials(), 'CheckoutRequestID' => $payment['checkout_id']],
                ['Authorization: Bearer ' . $this->token()],
            );
            if (
                (string) ($response['ResponseCode'] ?? '') === '0' &&
                isset($response['ResultCode']) &&
                hash_equals(
                    $payment['checkout_id'],
                    (string) ($response['CheckoutRequestID'] ?? ''),
                ) &&
                hash_equals(
                    $payment['merchant_id'],
                    (string) ($response['MerchantRequestID'] ?? ''),
                )
            ) {
                $repository->settle((int) $payment['id'], (string) $response['ResultCode']);
            }
        }
        $fresh = $repository->get((int) $payment['user_id'], (int) $payment['id']);
        return [
            'id' => $fresh['id'],
            'status' => $fresh['status'],
            'plan' => $fresh['plan'],
            'amount' => $fresh['amount'],
        ];
    }

    public function callback(array $input, string $secret): void
    {
        $callback = $input['Body']['stkCallback'] ?? [];
        if (
            !is_array($callback) ||
            !is_string($callback['CheckoutRequestID'] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/', $secret)
        ) {
            return;
        }
        $payment = (new PaymentRepository())->byCheckout($callback['CheckoutRequestID']);
        if (!$payment || !hash_equals($payment['callback_hash'], hash('sha256', $secret))) {
            return;
        }
        // Callback content alone never grants access. Independently query Daraja.
        $this->check($payment);
    }
}
