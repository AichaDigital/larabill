<?php

declare(strict_types=1);

use AichaDigital\Larabill\Exceptions\MissingInvoiceOwnerException;
use AichaDigital\Larabill\Models\EuSalesThreshold;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\EuSalesThresholdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('EuSalesThresholdService', function () {
    it('can be instantiated', function () {
        $service = new EuSalesThresholdService;

        expect($service)->toBeInstanceOf(EuSalesThresholdService::class);
    });

    it('returns threshold status', function () {
        $service    = new EuSalesThresholdService;
        $userId     = Str::uuid()->toString();
        $fiscalYear = now()->year;

        $status = $service->getThresholdStatus($userId, $fiscalYear);

        expect($status)->toBeArray();
        expect($status)->toHaveKeys([
            'current_amount',
            'threshold',
            'exceeded',
            'needs_notification',
            'is_oss_registered',
            'fiscal_year',
        ]);
        expect($status['fiscal_year'])->toBe($fiscalYear);
    });

    it('creates threshold record when getting status', function () {
        $service    = new EuSalesThresholdService;
        $userId     = Str::uuid()->toString();
        $fiscalYear = now()->year;

        EuSalesThreshold::getOrCreateForUser($userId, $fiscalYear);

        $status = $service->getThresholdStatus($userId, $fiscalYear);

        expect($status['current_amount']->isZero())->toBeTrue();
        expect($status['exceeded'])->toBeFalse();
    });

    it('returns zero amount for non-existent threshold', function () {
        $service = new EuSalesThresholdService;
        $userId  = Str::uuid()->toString();

        $status = $service->getThresholdStatus($userId, 2020);

        expect($status['current_amount']->isZero())->toBeTrue();
    });

    it('resets for new fiscal year', function () {
        $service = new EuSalesThresholdService;
        $userId  = Str::uuid()->toString();
        $oldYear = now()->year - 1;
        $newYear = now()->year;

        // Create old year threshold with some amount
        $oldThreshold = EuSalesThreshold::getOrCreateForUser($userId, $oldYear);
        $oldThreshold->addAmount(cents(50000));

        // Reset for new year
        $service->resetForNewFiscalYear($userId, $oldYear, $newYear);

        // New year should exist and be at 0
        $newStatus = $service->getThresholdStatus($userId, $newYear);
        expect($newStatus['current_amount']->isZero())->toBeTrue();
    });

    it('determines notification needed when threshold exceeded', function () {
        $service    = new EuSalesThresholdService;
        $userId     = Str::uuid()->toString();
        $fiscalYear = now()->year;

        // Create threshold and simulate exceeding
        $threshold = EuSalesThreshold::getOrCreateForUser($userId, $fiscalYear);
        $threshold->update([
            'threshold_exceeded' => true,
            'notification_sent'  => false,
        ]);

        $shouldNotify = $service->shouldSendNotification($userId, $fiscalYear);

        expect($shouldNotify)->toBeTrue();
    });

    it('does not notify when notification already sent', function () {
        $service    = new EuSalesThresholdService;
        $userId     = Str::uuid()->toString();
        $fiscalYear = now()->year;

        $threshold = EuSalesThreshold::getOrCreateForUser($userId, $fiscalYear);
        $threshold->update([
            'threshold_exceeded' => true,
            'notification_sent'  => true,
        ]);

        $shouldNotify = $service->shouldSendNotification($userId, $fiscalYear);

        expect($shouldNotify)->toBeFalse();
    });

    it('does not notify when threshold not exceeded', function () {
        $service    = new EuSalesThresholdService;
        $userId     = Str::uuid()->toString();
        $fiscalYear = now()->year;

        $threshold = EuSalesThreshold::getOrCreateForUser($userId, $fiscalYear);
        $threshold->update([
            'threshold_exceeded' => false,
            'notification_sent'  => false,
        ]);

        $shouldNotify = $service->shouldSendNotification($userId, $fiscalYear);

        expect($shouldNotify)->toBeFalse();
    });

    it('sends threshold notification and marks as sent', function () {
        $service    = new EuSalesThresholdService;
        $userId     = Str::uuid()->toString();
        $fiscalYear = now()->year;

        $threshold = EuSalesThreshold::getOrCreateForUser($userId, $fiscalYear);
        $threshold->update([
            'threshold_exceeded' => true,
            'notification_sent'  => false,
        ]);

        $service->sendThresholdNotification($userId, $fiscalYear);

        $threshold->refresh();
        expect($threshold->notification_sent)->toBeTrue();
    });

    it('reads the recipient country from userTaxProfile to detect an EU sale', function () {
        // AID-245 regression: isEuSale() read $invoice->user_tax_info_encrypted, an
        // orphan with no column → always null → every sale was treated as non-EU and
        // the OSS threshold never moved. The recipient country lives in userTaxProfile.
        // Exercised on an in-memory Invoice (no persistence, no creating-hook side
        // effects) so it stays isolated from the PDF suite.
        $service  = new EuSalesThresholdService;
        $isEuSale = (new ReflectionMethod($service, 'isEuSale'));
        $isEuSale->setAccessible(true);

        $frInvoice = new Invoice;
        $frInvoice->setRelation('userTaxProfile', UserTaxProfile::factory()->make(['country_code' => 'FR']));
        expect($isEuSale->invoke($service, $frInvoice))->toBeTrue();

        $esInvoice = new Invoice;
        $esInvoice->setRelation('userTaxProfile', UserTaxProfile::factory()->make(['country_code' => 'ES']));
        expect($isEuSale->invoke($service, $esInvoice))->toBeFalse(); // domestic, not intra-EU

        $noProfile = new Invoice;
        $noProfile->setRelation('userTaxProfile', null);
        expect($isEuSale->invoke($service, $noProfile))->toBeFalse();
    });

    it('accumulates under the invoice owner and the invoice fiscal year (AID-391)', function () {
        $service = new EuSalesThresholdService;
        $ownerId = Str::uuid()->toString();

        $invoice = new Invoice([
            'user_id'        => $ownerId,
            'fiscal_year'    => 2025,
            'taxable_amount' => cents(10000),
        ]);
        $invoice->setRelation('userTaxProfile', UserTaxProfile::factory()->make(['country_code' => 'FR']));

        $service->processInvoice($invoice);

        $row = EuSalesThreshold::where('user_id', $ownerId)->where('fiscal_year', 2025)->first();
        expect($row)->not->toBeNull()
            ->and($row->total_amount->unscaledValue())->toBe(10000);
    });

    it('falls back to the REGIONAL fiscal year, honouring a non-January start (AID-391)', function () {
        // Sensitivity: fiscal year starts in July and "today" is March 2026 —
        // the old date('Y') fallback would attribute to 2026; the fiscal year
        // is still 2025. Exercised with fiscal_year null (unsaved row shape).
        config(['larabill.fiscal_year.start_month' => 7]);
        $this->travelTo('2026-03-15');

        $service = new EuSalesThresholdService;
        $ownerId = Str::uuid()->toString();

        $invoice = new Invoice([
            'user_id'        => $ownerId,
            'fiscal_year'    => null,
            'taxable_amount' => cents(5000),
        ]);
        $invoice->setRelation('userTaxProfile', UserTaxProfile::factory()->make(['country_code' => 'DE']));

        $service->processInvoice($invoice);
        $this->travelBack();

        expect(EuSalesThreshold::where('user_id', $ownerId)->where('fiscal_year', 2025)->exists())->toBeTrue()
            ->and(EuSalesThreshold::where('user_id', $ownerId)->where('fiscal_year', 2026)->exists())->toBeFalse();
    });

    it('fails loud when the invoice has no owner instead of attributing to a fabricated one (AID-391)', function () {
        // The old fallback was config('larabill.company.id', '1') — a key that
        // does not exist and a non-UUID value: silent threshold-ledger corruption.
        $service = new EuSalesThresholdService;

        $invoice = new Invoice([
            'user_id'        => null,
            'fiscal_year'    => 2026,
            'taxable_amount' => cents(10000),
        ]);
        $invoice->setRelation('userTaxProfile', UserTaxProfile::factory()->make(['country_code' => 'FR']));

        expect(fn () => $service->processInvoice($invoice))
            ->toThrow(MissingInvoiceOwnerException::class);

        expect(EuSalesThreshold::count())->toBe(0);
    });
});
