<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\LaraPrivacyCore\Contracts\LegallyRetainable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Attach a fiscal invoice with the given legal date to a profile.
 */
function fiscalInvoiceFor(TestUser $user, UserTaxProfile $profile, string $date, InvoiceSerieType $serie = InvoiceSerieType::INVOICE): Invoice
{
    return Invoice::factory()->create([
        'user_id'             => $user->id,
        'user_tax_profile_id' => $profile->id,
        'serie'               => $serie->value,
        'invoice_date'        => $date,
    ]);
}

describe('UserTaxProfile retention contract', function () {
    beforeEach(function () {
        $this->user    = TestUser::factory()->create();
        $this->profile = UserTaxProfile::factory()->create(['owner_user_id' => $this->user->id]);
    });

    it('implements the LegallyRetainable privacy contract', function () {
        expect($this->profile)->toBeInstanceOf(LegallyRetainable::class);
    });

    it('is retained until the latest hold among its fiscal invoices', function () {
        fiscalInvoiceFor($this->user, $this->profile, '2021-03-10'); // -> 2027-12-31
        fiscalInvoiceFor($this->user, $this->profile, '2023-05-15'); // -> 2029-12-31

        expect($this->profile->retainedUntil()?->format('Y-m-d'))->toBe('2029-12-31');
    });

    it('ignores proforma invoices, which carry no hold', function () {
        fiscalInvoiceFor($this->user, $this->profile, '2023-05-15'); // -> 2029-12-31
        // A later proforma must NOT extend the hold (it is not a fiscal document).
        fiscalInvoiceFor($this->user, $this->profile, '2025-01-01', InvoiceSerieType::PROFORMA);

        expect($this->profile->retainedUntil()?->format('Y-m-d'))->toBe('2029-12-31');
    });

    it('carries no hold when it has never backed an invoice (decision C)', function () {
        expect($this->profile->retainedUntil())->toBeNull();
    });

    it('still computes its hold when the profile itself is soft-deleted', function () {
        fiscalInvoiceFor($this->user, $this->profile, '2023-05-15'); // -> 2029-12-31

        $this->profile->delete(); // soft delete — the hold must ignore deleted_at

        $trashed = UserTaxProfile::withTrashed()->findOrFail($this->profile->id);

        expect($trashed->trashed())->toBeTrue()
            ->and($trashed->retainedUntil()?->format('Y-m-d'))->toBe('2029-12-31');
    });
});
