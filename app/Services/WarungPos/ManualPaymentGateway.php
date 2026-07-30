<?php

namespace App\Services\WarungPos;

use App\Contracts\PaymentGateway;
use Illuminate\Support\Str;

class ManualPaymentGateway implements PaymentGateway
{
    public function createPayment(array $payload): array
    {
        return [
            'status' => $payload['status'] ?? 'paid',
            'reference_number' => $payload['reference_number'] ?? 'MAN-'.now()->format('YmdHis'),
            'provider_transaction_id' => $payload['provider_transaction_id'] ?? (string) Str::uuid(),
            'raw' => [
                'method' => $payload['method'] ?? 'manual',
                'amount' => $payload['amount'] ?? 0,
                'confirmed_by' => $payload['confirmed_by'] ?? null,
            ],
        ];
    }

    public function getPaymentStatus(string $reference): array
    {
        return ['reference' => $reference, 'status' => 'paid'];
    }

    public function cancelPayment(string $reference, string $reason = ''): array
    {
        return ['reference' => $reference, 'status' => 'cancelled', 'reason' => $reason];
    }

    public function refundPayment(string $reference, float $amount, string $reason = ''): array
    {
        return ['reference' => $reference, 'status' => 'refunded', 'amount' => $amount, 'reason' => $reason];
    }

    public function verifyWebhook(string $payload, string $signature, ?string $secret = null): bool
    {
        $secret = $secret ?: (string) env('PAYMENT_WEBHOOK_SECRET', '');

        return $secret !== '' && hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }
}
