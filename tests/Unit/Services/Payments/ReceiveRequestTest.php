<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Services\Payments\ReceiveRequest;
use AichaDigital\Larabill\Services\Payments\ReplayPolicy;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;

/*
 * Final-review B5: forLegacyReplay() builds a comparison-only request whose
 * allocations are 1-cent placeholders. The replayOnly flag makes process()
 * refuse it structurally, before any read or write.
 */

it('marks forLegacyReplay() requests replay-only and fromReceive() requests not', function () {
    $replay = ReceiveRequest::forLegacyReplay(TestCase::USER_UUID_2, ['a'], cents(100), 'EUR', now(), null, 'k');

    expect($replay->replayOnly)->toBeTrue();

    $real = ReceiveRequest::fromReceive(
        TestCase::USER_UUID_2, cents(100), 'EUR', now(), PaymentMethod::Card, null, null,
        [], CreditMovementKind::TOP_UP, null,
    );

    expect($real->replayOnly)->toBeFalse();
});

it('refuses a replay-only request in process() before any write', function (ReplayPolicy $policy) {
    $inv    = PaymentFixtures::sentInvoice(12100);
    $replay = ReceiveRequest::forLegacyReplay(TestCase::USER_UUID_2, [(string) $inv->id], cents(12100), 'EUR', now(), null, 'k-replay-only');

    expect(fn () => app(PaymentService::class)->process($replay, $policy))
        ->toThrow(LogicException::class, 'forLegacyReplay()');

    expect(GroupedPayment::count())->toBe(0)
        ->and(PaymentAllocation::count())->toBe(0);
})->with([ReplayPolicy::Legacy, ReplayPolicy::Full]);
