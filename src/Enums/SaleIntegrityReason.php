<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Why a voucher sale is incoherent (AID-1825 minimal scope §3): one reason
 * per detected disagreement between the claim, its payment and its invoice.
 * Carried by SaleIntegrityException and by the `salesIncoherent()` read, so
 * a staff list can show it.
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
enum SaleIntegrityReason: string
{
    case PaymentNotPosted          = 'payment_not_posted';
    case AmountDrift               = 'amount_drift';
    case CurrencyDrift             = 'currency_drift';
    case PayerDrift                = 'payer_drift';
    case ContentDrift              = 'content_drift';
    case CompetingAllocation       = 'competing_allocation';
    case CollectionStateUnexpected = 'collection_state_unexpected';
    case ClaimMissing              = 'claim_missing';
    case DocumentAxisIncoherent    = 'document_axis_incoherent';
    case MarkMissing               = 'mark_missing';
    /** A consumption's persisted exits do not add up to, or are not, what the operation declared. */
    case ConsumptionIncoherent = 'consumption_incoherent';
}
