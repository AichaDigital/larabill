<?php

declare(strict_types=1);

use AichaDigital\Larabill\DataTransferObjects\AuditEntry;
use AichaDigital\Larabill\DataTransferObjects\BillingDetails;
use AichaDigital\Larabill\DataTransferObjects\CustomData;
use AichaDigital\Larabill\DataTransferObjects\InvoiceItemMetadata;
use AichaDigital\Larabill\DataTransferObjects\PricingDetails;
use AichaDigital\Larabill\DataTransferObjects\SourceReference;
use Carbon\Carbon;

it('can create an invoice item metadata DTO', function () {
    $sourceRef = new SourceReference(
        type: 'article_service',
        articleId: 123
    );

    $pricingDetails = new PricingDetails(
        basePrice: 29.00,
        appliedPrice: 24.00
    );

    $dto = new InvoiceItemMetadata(
        sourceReference: $sourceRef,
        pricingDetails: $pricingDetails
    );

    expect($dto->sourceReference)->toBeInstanceOf(SourceReference::class)
        ->and($dto->pricingDetails)->toBeInstanceOf(PricingDetails::class)
        ->and($dto->billingDetails)->toBeNull()
        ->and($dto->auditTrail)->toBeNull()
        ->and($dto->customData)->toBeNull();
});

it('can create invoice item metadata from array', function () {
    $data = [
        'source_reference' => [
            'type'       => 'article_service',
            'article_id' => 123,
        ],
        'pricing_details' => [
            'base_price'    => 29.00,
            'applied_price' => 24.00,
        ],
        'billing_details' => [
            'billing_cycle' => 'monthly',
            'period_start'  => '2024-01-01',
            'period_end'    => '2024-01-31',
        ],
        'audit_trail' => [
            [
                'timestamp' => '2024-01-15 10:30:00',
                'action'    => 'created',
            ],
        ],
        'custom_data' => [
            'key' => 'value',
        ],
    ];

    $dto = InvoiceItemMetadata::fromArray($data);

    expect($dto->sourceReference)->toBeInstanceOf(SourceReference::class)
        ->and($dto->pricingDetails)->toBeInstanceOf(PricingDetails::class)
        ->and($dto->billingDetails)->toBeInstanceOf(BillingDetails::class)
        ->and($dto->auditTrail)->toBeArray()
        ->and($dto->auditTrail)->toHaveCount(1)
        ->and($dto->auditTrail[0])->toBeInstanceOf(AuditEntry::class)
        ->and($dto->customData)->toBeInstanceOf(CustomData::class);
});

it('can convert invoice item metadata to array', function () {
    $sourceRef = new SourceReference(
        type: 'article_service',
        articleId: 123
    );

    $pricingDetails = new PricingDetails(
        basePrice: 29.00,
        appliedPrice: 24.00
    );

    $billingDetails = new BillingDetails(
        billingCycle: 'monthly',
        periodStart: Carbon::parse('2024-01-01'),
        periodEnd: Carbon::parse('2024-01-31')
    );

    $auditEntry = new AuditEntry(
        timestamp: Carbon::parse('2024-01-15 10:30:00'),
        action: 'created'
    );

    $customData = new CustomData(data: ['key' => 'value']);

    $dto = new InvoiceItemMetadata(
        sourceReference: $sourceRef,
        pricingDetails: $pricingDetails,
        billingDetails: $billingDetails,
        auditTrail: [$auditEntry],
        customData: $customData
    );

    $array = $dto->toArray();

    expect($array)->toHaveKeys([
        'source_reference',
        'pricing_details',
        'billing_details',
        'audit_trail',
        'custom_data',
    ])
        ->and($array['source_reference'])->toBeArray()
        ->and($array['pricing_details'])->toBeArray()
        ->and($array['billing_details'])->toBeArray()
        ->and($array['audit_trail'])->toBeArray()
        ->and($array['custom_data'])->toBeArray();
});

it('can add audit entries to invoice item metadata', function () {
    $dto = new InvoiceItemMetadata;

    $entry1 = new AuditEntry(
        timestamp: now(),
        action: 'created'
    );

    $entry2 = new AuditEntry(
        timestamp: now(),
        action: 'updated'
    );

    $dtoWithEntry1 = $dto->withAuditEntry($entry1);
    $dtoWithEntry2 = $dtoWithEntry1->withAuditEntry($entry2);

    expect($dto->auditTrail)->toBeNull()
        ->and($dtoWithEntry1->auditTrail)->toHaveCount(1)
        ->and($dtoWithEntry2->auditTrail)->toHaveCount(2)
        ->and($dtoWithEntry2->auditTrail[0])->toBeInstanceOf(AuditEntry::class)
        ->and($dtoWithEntry2->auditTrail[1])->toBeInstanceOf(AuditEntry::class);
});

it('handles empty invoice item metadata', function () {
    $dto = new InvoiceItemMetadata;

    expect($dto->sourceReference)->toBeNull()
        ->and($dto->pricingDetails)->toBeNull()
        ->and($dto->billingDetails)->toBeNull()
        ->and($dto->auditTrail)->toBeNull()
        ->and($dto->customData)->toBeNull();

    $array = $dto->toArray();

    expect($array['source_reference'])->toBeNull()
        ->and($array['pricing_details'])->toBeNull()
        ->and($array['billing_details'])->toBeNull()
        ->and($array['audit_trail'])->toBeNull()
        ->and($array['custom_data'])->toBeNull();
});
