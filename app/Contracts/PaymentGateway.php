<?php

namespace App\Contracts;

interface PaymentGateway
{
    public function createPayment(array $payload): array;

    public function getPaymentStatus(string $reference): array;

    public function cancelPayment(string $reference, string $reason = ''): array;

    public function refundPayment(string $reference, float $amount, string $reason = ''): array;

    public function verifyWebhook(string $payload, string $signature, ?string $secret = null): bool;
}
