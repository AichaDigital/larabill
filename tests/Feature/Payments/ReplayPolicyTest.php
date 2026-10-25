<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Services\Payments\ReceiveRequest;
use AichaDigital\Larabill\Services\Payments\ReplayPolicy;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Carbon\CarbonImmutable;

mutates(PaymentService::class);

/*
 * PaymentService::replayMatches() (AID-962, spec §5.1/§5.2): Legacy compares
 * payer, currency, amount and the distinct invoice ids of the direct
 * allocations; Full adds receivedAt (to the second, UTC), method, reference,
 * creditKind (only when the payment created an entry) and the per-invoice map.
 */

/** @return array{0: GroupedPayment, 1: string, 2: CarbonImmutable} */
function aid962PersistedPayment(): array
{
    $inv = PaymentFixtures::sentInvoice(12100);
    $at  = CarbonImmutable::now()->subDay()->startOfSecond();

    $payment = app(PaymentService::class)->receive(
        TestCase::USER_UUID_2, cents(15000), 'EUR', $at, PaymentMethod::BankTransfer, 'TRF-1', TestCase::USER_UUID_1,
        [new Allocation($inv->id, cents(12100))], CreditMovementKind::SURPLUS, 'k-1',
    );

    return [$payment, (string) $inv->id, $at];
}

function aid962Request(string $invoiceId, CarbonImmutable $at, array $variant = []): ReceiveRequest
{
    return ReceiveRequest::fromReceive(
        TestCase::USER_UUID_2,
        cents(15000),
        'EUR',
        $at->addSeconds($variant['shift'] ?? 0),
        $variant['method'] ?? PaymentMethod::BankTransfer,
        array_key_exists('reference', $variant) ? $variant['reference'] : 'TRF-1',
        TestCase::USER_UUID_1,
        [new Allocation($invoiceId, cents($variant['alloc'] ?? 12100))],
        $variant['kind'] ?? CreditMovementKind::SURPLUS,
        'k-1',
    );
}

it('matches an identical request under both policies', function () {
    [$payment, $invoiceId, $at] = aid962PersistedPayment();
    $service                    = app(PaymentService::class);
    $request                    = aid962Request($invoiceId, $at);

    expect($service->replayMatches($payment, $request, ReplayPolicy::Legacy))->toBeTrue()
        ->and($service->replayMatches($payment, $request, ReplayPolicy::Full))->toBeTrue();
});

dataset('replay-distinguishing-fields', [
    'date'               => [['shift' => 1]],
    'reference'          => [['reference' => 'TRF-2']],
    'null reference'     => [['reference' => null]],
    'method'             => [['method' => PaymentMethod::Card]],
    'creditKind'         => [['kind' => CreditMovementKind::TOP_UP]],
    'per-invoice amount' => [['alloc' => 12000]],
]);

it('lets Legacy ignore a field Full distinguishes', function (array $variant) {
    [$payment, $invoiceId, $at] = aid962PersistedPayment();
    $service                    = app(PaymentService::class);
    $request                    = aid962Request($invoiceId, $at, $variant);

    expect($service->replayMatches($payment, $request, ReplayPolicy::Legacy))->toBeTrue()
        ->and($service->replayMatches($payment, $request, ReplayPolicy::Full))->toBeFalse();
})->with('replay-distinguishing-fields');

it('rejects a different payer, currency, amount or invoice set under both policies', function () {
    [$payment, $invoiceId, $at] = aid962PersistedPayment();
    $service                    = app(PaymentService::class);
    $other                      = PaymentFixtures::sentInvoice(12100);

    $requests = [
        ReceiveRequest::fromReceive(TestCase::USER_UUID_3, cents(15000), 'EUR', $at, PaymentMethod::BankTransfer, 'TRF-1', null, [new Allocation($invoiceId, cents(12100))], CreditMovementKind::SURPLUS, 'k-1'),
        ReceiveRequest::fromReceive(TestCase::USER_UUID_2, cents(15000), 'USD', $at, PaymentMethod::BankTransfer, 'TRF-1', null, [new Allocation($invoiceId, cents(12100))], CreditMovementKind::SURPLUS, 'k-1'),
        ReceiveRequest::fromReceive(TestCase::USER_UUID_2, cents(15001), 'EUR', $at, PaymentMethod::BankTransfer, 'TRF-1', null, [new Allocation($invoiceId, cents(12100))], CreditMovementKind::SURPLUS, 'k-1'),
        ReceiveRequest::fromReceive(TestCase::USER_UUID_2, cents(15000), 'EUR', $at, PaymentMethod::BankTransfer, 'TRF-1', null, [new Allocation((string) $other->id, cents(12100))], CreditMovementKind::SURPLUS, 'k-1'),
    ];

    foreach ($requests as $request) {
        expect($service->replayMatches($payment, $request, ReplayPolicy::Legacy))->toBeFalse()
            ->and($service->replayMatches($payment, $request, ReplayPolicy::Full))->toBeFalse();
    }
});
