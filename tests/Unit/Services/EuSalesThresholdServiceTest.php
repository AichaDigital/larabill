<?php

declare(strict_types=1);

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

    it('counts a sale to an EU customer toward the threshold via userTaxProfile', function () {
        // AID-245 regression: isEuSale() read $invoice->user_tax_info_encrypted, an
        // orphan with no column → always null → every sale was treated as non-EU and
        // the OSS threshold never moved. The recipient country lives in userTaxProfile.
        $service    = new EuSalesThresholdService;
        $userId     = Str::uuid()->toString();
        $fiscalYear = now()->year;

        $profile = UserTaxProfile::factory()->create([
            'country_code'         => 'FR',
            'is_eu_vat_registered' => true,
        ]);
        $invoice = Invoice::factory()->create([
            'user_id'             => $userId,
            'user_tax_profile_id' => $profile->id,
            'is_roi_taxed'        => false,
            'fiscal_year'         => $fiscalYear,
            'taxable_amount'      => cents(500000), // €5,000.00
        ]);

        $service->processInvoice($invoice);

        $threshold = EuSalesThreshold::findByUserAndYear($userId, $fiscalYear);
        expect($threshold)->not->toBeNull();
        expect($threshold->total_amount->isEqualTo(cents(500000)))->toBeTrue();
    });
});
