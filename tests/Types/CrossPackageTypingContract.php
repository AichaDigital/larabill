<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Types;

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\InvoiceNumberingService;
use Illuminate\Database\Eloquent\Builder;

function articleServiceStatusExposesEnumStatus(ArticleServiceStatus $service): int
{
    return $service->status->value;
}

function invoiceNumberingAcceptsUuidUserId(InvoiceNumberingService $service): string
{
    return (string) $service->generateNumber(
        'FAC',
        InvoiceSerieType::INVOICE->value,
        '0194a000-0000-7000-8000-000000000001',
    );
}

function invoiceDraftHelperIsPublicContract(Invoice $invoice): bool
{
    return $invoice->isDraft();
}

function articleInvoiceItemsRelationIsPublicContract(Article $article): bool
{
    return $article->invoiceItems()->exists();
}

function userTaxProfileInvoicesRelationIsPublicContract(UserTaxProfile $profile): bool
{
    return $profile->invoices()->exists();
}

/**
 * @param  Builder<UserTaxProfile>  $query
 */
function acceptsUserTaxProfileBuilder(Builder $query): void {}

function userTaxProfileActiveScopeKeepsConcreteBuilder(): void
{
    acceptsUserTaxProfileBuilder(UserTaxProfile::query()->active());
}
