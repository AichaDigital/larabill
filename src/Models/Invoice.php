<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Casts\AichaMoneyCast;
use AichaDigital\Larabill\Concerns\HasUserRelation;
use AichaDigital\Larabill\Concerns\HasUuid;
use AichaDigital\Larabill\Database\Factories\InvoiceFactory;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\InvoiceDocumentCoherenceException;
use AichaDigital\Larabill\Services\FiscalIntegrityChecker;
use AichaDigital\Larabill\Services\ModelMappingService;
use AichaDigital\Larabill\Services\PDF\PDFService;
use AichaDigital\LaraPrivacyCore\Contracts\LegallyRetainable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Invoice Model
 *
 * Represents an invoice with fiscal compliance, immutability and encryption features.
 * Uses UUID v7 string (36 chars) for primary key.
 * All monetary amounts are stored as base-100 integers (e.g., €12.34 => 1234).
 *
 * Fiscal data architecture (ADR-001 + ADR-003):
 * - CompanyFiscalConfig: Issuer data with temporal validity
 * - UserTaxProfile: Customer/User fiscal data with temporal validity
 *
 * v1.0: Enhanced for fiscal compliance (CEE/EU):
 * - Correlative numbering with serie separation
 * - Chronological validation with issued_at
 * - Support for proforma→invoice and rectificative invoices
 *
 * @property string $id UUID (string or binary based on config)
 * @property string|null $fiscal_number Complete display number (FAC-2025-000047); NULL on DRAFT/PREPARED/CANCELLED fiscal rows (ADR-016 §8.2 C4)
 * @property string $prefix User customizable prefix
 * @property InvoiceSerieType $serie Invoice series type enum
 * @property int|null $series_number Pure correlative number; NULL until issuance (ADR-016 §8.2 C4)
 * @property int|null $fiscal_year Calculated fiscal year; NULL until issuance (ADR-016 §8.2 C4)
 * @property Carbon|null $invoice_date Legal date on PDF; NULL until issuance (ADR-016 §8.2 C4)
 * @property Carbon|null $issued_at Technical chronological timestamp; NULL until issuance (ADR-016 §8.2 C4)
 * @property Carbon|null $service_date Service/delivery date
 * @property Carbon|null $due_date Payment due date
 * @property Carbon|null $paid_at Actual payment timestamp
 * @property InvoiceStatus $status Invoice status enum
 * @property InvoiceDocumentStatus|null $invoice_document_status Document axis (ADR-016 §5.1): DRAFT/PREPARED/ISSUED/CANCELLED on fiscal rows; NULL on proforma rows
 * @property FiscalSubmissionStatus|null $fiscal_submission_status Fiscal submission axis (ADR-016 §5.3): set at issuance only
 * @property Carbon|null $document_cancelled_at Cancellation instant of the document axis (ADR-016 §8.1 audit trail)
 * @property string|null $document_cancelled_by Actor of the document cancellation (ADR-016 §8.1 audit trail)
 * @property int|string $user_id
 * @property string|null $billable_user_id UUID FK to users - User being billed (ADR-003)
 * @property int|null $company_fiscal_config_id FK to company_fiscal_configs (ADR-001)
 * @property int|null $user_tax_profile_id FK to user_tax_profiles (ADR-003)
 * @property string|null $proforma_id UUID if converted from proforma
 * @property string|null $rectifies_invoice_id UUID if rectificative
 * @property string|null $settlement_operation_key Deterministic settlement operation key (ADR-015 §3.4, guarantee): `guarantee_ops:{registry_row_uuid}`. UNIQUE on this table — the serialisation point of idempotent settlement issuance: a second issuance for the same claim collides on this index and ADOPTS the existing artifact instead of issuing a duplicate fiscal correction. NULL for every document minted outside a guarantee settlement.
 * @property string|null $issuer_snapshot Encrypted issuer data (v0.4.0)
 * @property string|null $customer_snapshot Encrypted customer data (v0.4.0)
 * @property string|null $fiscal_snapshot Encrypted fiscal context (v0.4.0)
 * @property string|null $fiscal_verification_id Verifactu/TicketBAI ID (v0.4.0)
 * @property string|null $fiscal_verification_qr QR code (v0.4.0)
 * @property string|null $fiscal_verification_hash Hash (v0.4.0)
 * @property Carbon|null $fiscal_verified_at (v0.4.0)
 * @property array<string, mixed>|null $fiscal_verification_metadata (v0.4.0)
 * @property bool $is_roi_taxed ROI reverse charge
 * @property bool $is_historical Imported-document flag (ADR-015 §2.5): true on EVERY document migrated from the legacy system
 * @property string $type invoice|proforma|rectificative
 * @property FixedDecimal $taxable_amount Base amount before tax
 * @property float $tax_amount Calculated tax
 * @property FixedDecimal $total_amount Total with tax
 * @property float|null $total Total amount (alias)
 * @property float|null $subtotal Subtotal (alias for taxable_amount)
 * @property FixedDecimal $total_tax_amount Total tax
 * @property string|null $converted_invoice_id UUID of final invoice (if proforma converted)
 * @property bool $is_immutable
 * @property Carbon|null $immutable_at
 * @property string|null $notes
 * @property string|null $payment_terms
 * @property string|null $template_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class Invoice extends Model implements LegallyRetainable
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUserRelation, HasUuid;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }

    /**
     * Boot the model (ADR-001).
     *
     * Auto-load fiscal configs on invoice creation for immutability.
     * Validates fiscal integrity before allowing invoice creation.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Al crear factura, verificar integridad fiscal y snapshot automático
        static::creating(function (Invoice $invoice) {
            // 1. Verificar integridad fiscal antes de crear
            // CRÍTICO: Bloquea facturación si hay configs duplicadas
            $integrityChecker = app(FiscalIntegrityChecker::class);

            // Check global (CompanyFiscalConfig)
            $integrityChecker->assertCanInvoice();

            // Check específico del usuario (UserTaxProfile) si hay billable_user_id
            if ($invoice->billable_user_id) {
                $integrityChecker->assertCanInvoiceUser($invoice->billable_user_id);
            }

            // 2. Solo si no es proforma Y no tiene configs ya asignadas
            if ($invoice->serie !== InvoiceSerieType::PROFORMA && ! $invoice->company_fiscal_config_id) {
                $invoice->snapshotFiscalConfigs();
            }
        });

        // ADR-016 §8.2 (PR-1, AID-1627): the row-level coherence of the document
        // axis is enforced at the application layer on EVERY engine — SQLite
        // cannot ALTER-add a CHECK, and this guard is its only enforcement
        // (MySQL additionally carries the named database CHECK, added by the
        // `backfill_invoice_document_status` migration). The guard evaluates
        // the SAME clause set over the merged (persisted + dirty) attribute
        // state, so an incoherent birth AND an incoherent mutation are both
        // rejected. Bulk/query-builder and external SQL operations remain
        // outside the guarantee (ADR-016 §6.5 / v7 §10 limit).
        static::saving(function (Invoice $invoice): void {
            $invoice->assertDocumentAxisCoherence();
        });

        // ADR-016 §6.5 (PR-2, AID-1627): the attribute-assignment + save()
        // bypass v7 names as D8 is closed at the model-event layer. The guard
        // reads the PERSISTED state (not the dirty copy), so `$row->field =
        // X; $row->save();` cannot smuggle fiscal content past the update()
        // override. The grouped-payment collection path stays deliberate (the
        // named methods below only touch collection fields) and the issuance
        // transition is the one legitimate writer of the five number/issuance
        // fields on an already-protected row.
        static::updating(function (Invoice $invoice): void {
            $invoice->assertFiscalContentLocked();
        });

        // ADR-016 §6.5: the deletion hole (§1.1.2) closes at the model-event
        // layer too — an ISSUED row is a consumed correlative and a PREPARED
        // conversion-born row is released only through cancelPreparedInvoice().
        // Neither is ever deleted. Bulk/query-builder and external SQL remain
        // documented outside the guarantee (v7 §10 limit, verbatim).
        static::deleting(function (Invoice $invoice): void {
            $invoice->assertDeletable();
        });
    }

    /**
     * The issuance-transition authorization (ADR-016 §6.5, implementation-gate
     * round 2): a DRAFT/PREPARED -> ISSUED transition through the public
     * Eloquent API is only legal when the NAMED writer performs it. Shape
     * validation cannot distinguish `issue()`'s write from a forged coherent
     * ISSUED shape (a fabricated number persisted without advancing the
     * counter), so the writer carries this per-process authorization flag.
     *
     * @internal issue() is the only in-package caller.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    public static function withIssuanceAuthorization(callable $write): mixed
    {
        $previous                           = self::$issuanceTransitionAuthorized;
        self::$issuanceTransitionAuthorized = true;

        try {
            return $write();
        } finally {
            self::$issuanceTransitionAuthorized = $previous; // reentrant-safe (gate round 3)
        }
    }

    private static bool $issuanceTransitionAuthorized = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'fiscal_number',
        'prefix',
        'serie',
        'series_number',
        'fiscal_year',
        'invoice_date',
        'issued_at',
        'service_date',
        'due_date',
        'paid_at',
        'status',
        'invoice_document_status',
        'fiscal_submission_status',
        'document_cancelled_at',
        'document_cancelled_by',
        'user_id',
        'billable_user_id',
        'company_fiscal_config_id',
        'user_tax_profile_id',
        'proforma_id',
        'rectifies_invoice_id',
        'settlement_operation_key',
        'issuer_snapshot',
        'customer_snapshot',
        'fiscal_snapshot',
        'fiscal_verification_id',
        'fiscal_verification_qr',
        'fiscal_verification_hash',
        'fiscal_verified_at',
        'fiscal_verification_metadata',
        'is_roi_taxed',
        'is_historical',
        'taxable_amount',
        'total_tax_amount',
        'total_amount',
        'converted_invoice_id',
        'converted_at',
        'is_immutable',
        'immutable_at',
        'notes',
        'payment_terms',
        'template_name',
    ];

    /**
     * Casts for attributes.
     *
     * Uses Base100 cast from lara100 package for monetary values
     * Automatically handles conversion between decimals and base-100 integers
     * Example: €12.34 ↔ 1234 (stored as integer, accessed as decimal)
     *
     * Note: UUID fields (id, proforma_id, rectifies_invoice_id) are handled by HasUuid trait
     */
    public function casts(): array
    {
        return [
            'serie'                        => InvoiceSerieType::class, // PHP Enum
            'status'                       => InvoiceStatus::class,    // PHP Enum
            'invoice_document_status'      => InvoiceDocumentStatus::class, // PHP Enum (ADR-016 §5.1)
            'fiscal_submission_status'     => FiscalSubmissionStatus::class, // PHP Enum (ADR-016 §5.3)
            'document_cancelled_at'        => 'datetime',
            'fiscal_year'                  => 'integer',
            'invoice_date'                 => 'date',
            'issued_at'                    => 'datetime',
            'service_date'                 => 'date',
            'due_date'                     => 'date',
            'paid_at'                      => 'datetime',
            'fiscal_verified_at'           => 'datetime',
            'is_immutable'                 => 'boolean',
            'immutable_at'                 => 'datetime',
            'taxable_amount'               => AichaMoneyCast::class.':2', // €12.34 ↔ 1234 (AEAT domain, AID-1527)
            'total_tax_amount'             => AichaMoneyCast::class.':2',
            'total_amount'                 => AichaMoneyCast::class.':2', // €12.34 ↔ 1234 (AEAT domain, AID-1527)
            'fiscal_verification_metadata' => 'array',
            'is_roi_taxed'                 => 'boolean',
            'is_historical'                => 'boolean',
        ];
    }

    /**
     * The instant until which this invoice must be retained under the ordinary
     * commercial-accounting retention hold (lara-privacy `LegallyRetainable`).
     *
     * Fiscal invoices (INVOICE, SIMPLIFIED, RECTIFICATIVE) are anchored at the
     * end of the fiscal year of their legal date and kept for the configured
     * number of years (larabill.retention.fiscal_years, default 6 — Código de
     * Comercio art. 30). A proforma is not a fiscal document, and a fiscal
     * invoice without a legal date yet has no valid anchor, so both yield null.
     *
     * larabill owns this obligation; lara-privacy only reads it to block or
     * restrict the record while the hold is active — it never decides erasure.
     */
    public function retainedUntil(): ?DateTimeInterface
    {
        if (! $this->serie->isFiscal()) {
            return null;
        }

        if ($this->invoice_date === null) {
            return null;
        }

        $years = (int) config('larabill.retention.fiscal_years', 6);

        return $this->invoice_date->copy()->endOfYear()->addYears($years);
    }

    /**
     * Make the invoice immutable.
     *
     * @api Amber-band contract operation (AID-407): consumers call it only behind an app-side port.
     */
    public function makeImmutable(): void
    {
        $this->is_immutable = true;
        $this->immutable_at = now();
        $this->save();
    }

    /**
     * Assert this row (persisted state merged with the pending changes)
     * satisfies the ADR-016 §8.2 document-axis coherence clause set.
     *
     * Called from the `saving` hook on every engine; the MySQL CHECK enforces
     * the same clauses a second time, over the stored values.
     *
     * @throws InvoiceDocumentCoherenceException
     */
    public function assertDocumentAxisCoherence(): void
    {
        self::assertDocumentAxisCoherentRow(array_merge($this->getRawOriginal(), $this->getAttributes()));
    }

    /**
     * Assert one raw row satisfies the ADR-016 §8.2 clause set — every clause
     * must be TRUE (the set is NULL-safe, so no clause can evaluate UNKNOWN).
     *
     * Clause names reference the spec's §16 gate adjudications (V9, V10, V11,
     * V12, V13, S4-r, S5) so a finding and its landing stay traceable.
     *
     * @param  array<string, mixed>  $row  Raw attributes (DB-shaped values: ints, strings, NULLs)
     *
     * @throws InvoiceDocumentCoherenceException
     */
    public static function assertDocumentAxisCoherentRow(array $row): void
    {
        $violations = self::documentAxisViolations($row);

        if ($violations !== []) {
            throw InvoiceDocumentCoherenceException::violating($violations, $row);
        }
    }

    /**
     * Evaluate the §8.2 clause set over one raw row.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, array{id: string, description: string}> Violated clauses; empty when the row is coherent
     */
    private static function documentAxisViolations(array $row): array
    {
        $violations = [];

        $doc         = self::normalizeAxisValue($row['invoice_document_status'] ?? null);
        $serie       = self::normalizeAxisValue($row['serie'] ?? null);
        $submission  = self::normalizeAxisValue($row['fiscal_submission_status'] ?? null);
        $cancelledAt = $row['document_cancelled_at'] ?? null;
        $cancelledBy = $row['document_cancelled_by'] ?? null;

        $fiveFields  = ['fiscal_number', 'series_number', 'fiscal_year', 'invoice_date', 'issued_at'];
        $fiveNotNull = true;
        $fiveAllNull = true;
        foreach ($fiveFields as $field) {
            $isNull      = ($row[$field] ?? null) === null;
            $fiveNotNull = $fiveNotNull && ! $isNull;
            $fiveAllNull = $fiveAllNull && $isNull;
        }

        // C1 (B4): document-status enum domain.
        if ($doc !== null && ! in_array($doc, InvoiceDocumentStatus::checkDomainValues(), true)) {
            $violations[] = ['id' => 'C1', 'description' => 'invoice_document_status out of domain'];
        }

        // V11: serie enum domain (InvoiceSerieType), derived from the enum —
        // never hand-listed.
        if ($serie !== null && ! in_array($serie, array_map(
            static fn (InvoiceSerieType $type): int => $type->value,
            InvoiceSerieType::cases(),
        ), true)) {
            $violations[] = ['id' => 'V11', 'description' => 'serie out of domain'];
        }

        // C2: axis biconditional — serie = PROFORMA ⇔ document status NULL.
        // Both operands are non-nullable booleans; no UNKNOWN path.
        if (($serie === InvoiceSerieType::PROFORMA->value) !== ($doc === null)) {
            $violations[] = ['id' => 'C2', 'description' => 'the document axis exists only for fiscal rows (serie = PROFORMA ⇔ invoice_document_status IS NULL)'];
        }

        // C3 (V10): proforma fields — one-directional implication: a proforma
        // row's five number/issuance fields are ALL NOT NULL.
        if ($serie === InvoiceSerieType::PROFORMA->value && ! $fiveNotNull) {
            $violations[] = ['id' => 'C3', 'description' => 'proforma rows carry all five number/issuance fields NOT NULL'];
        }

        // C4 (V12): fiscal-row coherence, guarded by the NULL-safe antecedent.
        if ($doc !== null) {
            if (($doc === InvoiceDocumentStatus::ISSUED->value) !== $fiveNotNull) {
                $violations[] = ['id' => 'C4', 'description' => 'fiscal rows bind invoice_document_status = ISSUED to all five number/issuance fields NOT NULL (and every other status to all NULL)'];
            }
            if ((in_array($doc, [InvoiceDocumentStatus::DRAFT->value, InvoiceDocumentStatus::PREPARED->value, InvoiceDocumentStatus::CANCELLED->value], true)) !== $fiveAllNull) {
                $violations[] = ['id' => 'C4', 'description' => 'fiscal rows bind DRAFT/PREPARED/CANCELLED to all five number/issuance fields NULL'];
            }

            // C5: a CANCELLED fiscal row carries its cancellation instant.
            if ($doc === InvoiceDocumentStatus::CANCELLED->value && $cancelledAt === null) {
                $violations[] = ['id' => 'C5', 'description' => 'invoice_document_status = CANCELLED requires document_cancelled_at NOT NULL'];
            }

            // C6 (V12): submission biconditional, fiscal rows only, NULL-safe.
            if (($doc === InvoiceDocumentStatus::ISSUED->value) !== ($submission !== null)) {
                $violations[] = ['id' => 'C6', 'description' => 'fiscal rows bind invoice_document_status = ISSUED to fiscal_submission_status NOT NULL (and every other status to NULL)'];
            }
        }

        // V9: submission enum domain.
        if ($submission !== null && ! in_array($submission, FiscalSubmissionStatus::checkDomainValues(), true)) {
            $violations[] = ['id' => 'V9', 'description' => 'fiscal_submission_status out of domain'];
        }

        // S5 (round 5): proforma hygiene — NULL-safe implication. A doc-NULL row
        // carries NO lifecycle metadata of any axis.
        if ($doc === null && ($submission !== null || $cancelledAt !== null || $cancelledBy !== null)) {
            $violations[] = ['id' => 'S5', 'description' => 'proforma (doc-NULL) rows carry no submission or cancellation metadata'];
        }

        // S4-r (round 5 residual): an actor without a cancellation instant is
        // the rejected misleading shape; the reverse (instant without actor)
        // is the specified direct-draft cancellation and stays valid.
        if ($cancelledBy !== null && $cancelledAt === null) {
            $violations[] = ['id' => 'S4-r', 'description' => 'document_cancelled_by requires document_cancelled_at'];
        }

        // V13 (round 8): the inverse of the cancellation rule — cancellation
        // metadata on a non-CANCELLED row claims a cancellation that never happened.
        if ($cancelledAt !== null && $doc !== InvoiceDocumentStatus::CANCELLED->value) {
            $violations[] = ['id' => 'V13', 'description' => 'document_cancelled_at requires invoice_document_status = CANCELLED'];
        }

        return $violations;
    }

    /**
     * The header fields that constitute FISCAL CONTENT (ADR-016 §6.5): once a
     * row is fiscally protected (persisted `is_immutable` OR persisted
     * document status ISSUED), none of these may change through any public
     * Eloquent operation — the `update()` override covered mass updates, and
     * this guard closes the attribute-assignment + `save()` bypass (v7 D8).
     *
     * Deliberately NOT here: collection state (`status`, `paid_at`), the
     * document/submission axis fields (the lifecycle transitions are their
     * named writers), the fiscal-verification result fields and the conversion
     * mirror fields — those have their own deliberate paths.
     */
    private const FISCAL_CONTENT_FIELDS = [
        'fiscal_number',
        'prefix',
        'serie',
        'series_number',
        'fiscal_year',
        'invoice_date',
        'issued_at',
        'service_date',
        'taxable_amount',
        'total_tax_amount',
        'total_amount',
        'is_roi_taxed',
        'issuer_snapshot',
        'customer_snapshot',
        'fiscal_snapshot',
        'company_fiscal_config_id',
        'user_tax_profile_id',
        'billable_user_id',
        'user_id',
        'proforma_id',
        'rectifies_invoice_id',
        'settlement_operation_key',
    ];

    /**
     * The two number/issuance-eligible persisted states that make a write
     * carrying the five number/issuance fields an ISSUANCE transition — the
     * one legitimate writer of those fields on a fiscally protected row
     * (ADR-016 §6; e.g. `issue()` on a draft sealed with `make_immutable`).
     *
     * @return list<int>
     */
    private static function issuanceEligibleDocumentStatuses(): array
    {
        return [
            InvoiceDocumentStatus::DRAFT->value,
            InvoiceDocumentStatus::PREPARED->value,
        ];
    }

    /**
     * Close the D8 save() bypass (ADR-016 §6.5): reject any mutation of
     * fiscal-content fields on a row whose PERSISTED state is fiscally
     * protected (immutable, or already ISSUED) — except the issuance
     * transition itself, which is the named writer of the five
     * number/issuance fields.
     *
     * Called from the `updating` hook. Reads getRawOriginal(), never the
     * dirty copy, so the persisted state is what governs.
     *
     * @throws \Exception with the house message convention when blocked
     */
    public function assertFiscalContentLocked(): void
    {
        $originalDoc = $this->getRawOriginal('invoice_document_status');
        $protected   = (bool) $this->getRawOriginal('is_immutable')
            || (is_int($originalDoc) && $originalDoc === InvoiceDocumentStatus::ISSUED->value);
        $dirtyKeys   = array_keys($this->getDirty());

        // The issuance transition (DRAFT/PREPARED -> dirty ISSUED) is audited
        // on EVERY row, protected or not: a DRAFT or PREPARED row is not
        // protected, so without this pre-check the transition would bypass the
        // whole guard (implementation-gate finding 1, measured on the tree —
        // the bundled-mutation scenario saved without throwing). The
        // transition authorizes ONLY the issuance write set — the five
        // number/issuance fields plus the two axis fields — never arbitrary
        // fiscal content bundled into the same save (that would mint an
        // issued-looking document with mutated amounts and a bypassed
        // counter). And a transition OUT of anything else (e.g. CANCELLED ->
        // ISSUED) is a forged issuance: issue() is the only writer, and it
        // only ever starts from DRAFT/PREPARED.
        $transitionsToIssued = $this->isDirty('invoice_document_status')
            && ($this->getAttributes()['invoice_document_status'] ?? null) === InvoiceDocumentStatus::ISSUED->value;

        if ($transitionsToIssued) {
            // Round 2: shape is not authorization. Without the named writer's
            // flag, a coherent ISSUED shape could be persisted while the
            // counter never moved — the correlative provenance lives only in
            // issue().
            if (! self::$issuanceTransitionAuthorized) {
                throw new \Exception(sprintf(
                    'Cannot transition invoice [%s] to ISSUED through the public API: issue() is the named writer of the issuance transition (ADR-016 §6.5).',
                    (string) $this->getKey(),
                ));
            }

            if (! in_array($originalDoc, self::issuanceEligibleDocumentStatuses(), true)) {
                throw new \Exception(sprintf(
                    'Cannot transition invoice [%s] to ISSUED from persisted document status [%s]: issue() is the named writer and only ever starts from DRAFT/PREPARED (ADR-016 §6).',
                    (string) $this->getKey(),
                    (string) InvoiceDocumentStatus::tryFrom(is_int($originalDoc) ? $originalDoc : -1)?->name,
                ));
            }

            $mutated = array_intersect($dirtyKeys, self::FISCAL_CONTENT_FIELDS);
            $usurped = array_diff($mutated, self::issuanceTransitionFields());

            if ($usurped !== []) {
                throw new \Exception(sprintf(
                    'Cannot bundle fiscal-content changes (%s) into an issuance transition (ADR-016 §6.5: only the issuance write set may change when a DRAFT/PREPARED row transitions to ISSUED — issue() is its named writer).',
                    implode(', ', $usurped),
                ));
            }

            // Gate round 3, finding 1: the write-set allowlist proves SHAPE,
            // not provenance. The counter is the proof: issue() advances it
            // in the same transaction, so a legitimate transition can only
            // claim a series_number the control row has actually allocated.
            // A forged coherent shape claiming an unallocated number is
            // rejected here (and one claiming an allocated number collides
            // with the fiscal_number UNIQUE). Residual: bulk/external SQL
            // stays outside the guarantee; the R4 gap report is the
            // detective control for anything that slips through.
            $provenance = InvoiceSeriesControl::query()
                ->where('prefix', $this->getAttributes()['prefix'])
                ->where('serie', $this->getAttributes()['serie'])
                ->where('fiscal_year', $this->getAttributes()['fiscal_year'])
                ->where('last_number', '>=', $this->getAttributes()['series_number'])
                ->exists();

            if (! $provenance) {
                throw new \Exception(sprintf(
                    'Cannot persist invoice [%s] as ISSUED with series_number [%s]: the series control has no allocation at or above it — issue() is the only writer that advances the counter (ADR-016 §6, AID-390).',
                    (string) $this->getKey(),
                    (string) $this->getAttributes()['series_number'],
                ));
            }

            return;
        }

        if (! $protected) {
            return;
        }

        $mutated = array_intersect($dirtyKeys, self::FISCAL_CONTENT_FIELDS);

        if ($mutated !== []) {
            throw new \Exception(sprintf(
                'Cannot change fiscal content (%s) of a fiscally protected invoice (ADR-016 §6.5: immutable or ISSUED rows are closed to fiscal mutation through any public operation).',
                implode(', ', $mutated),
            ));
        }
    }

    /**
     * The write set the issuance transition may carry (ADR-016 §6.5,
     * implementation-gate finding 1): the five number/issuance fields plus
     * the two lifecycle axis fields. `issue()` is the named writer.
     *
     * @return list<string>
     */
    private static function issuanceTransitionFields(): array
    {
        return [
            'fiscal_number',
            'series_number',
            'fiscal_year',
            'invoice_date',
            'issued_at',
            'invoice_document_status',
            'fiscal_submission_status',
            // AID-1649: the THREE fiscal-context snapshots are REGENERATED at
            // issuance from the same instant and rows (the birth snapshots
            // froze the draft's context), so they belong to the issuance
            // write set.
            'issuer_snapshot',
            'customer_snapshot',
            'fiscal_snapshot',
        ];
    }

    /**
     * The deletion guard (ADR-016 §6.5): an ISSUED row is a consumed
     * correlative — deleting it would be the unexplained gap of §1.1.2 — and
     * a PREPARED conversion-born row is released only through
     * cancelPreparedInvoice(), never deleted. DRAFT and CANCELLED rows stay
     * deletable (the operator's cleanup surface, unchanged from v6).
     *
     * @throws \Exception with the house message convention when blocked
     */
    public function assertDeletable(): void
    {
        $doc = $this->getRawOriginal('invoice_document_status');

        if ($doc === InvoiceDocumentStatus::ISSUED->value) {
            throw new \Exception(sprintf(
                'Cannot delete invoice [%s]: its document status is ISSUED — the correlative was consumed and no deletion path exists for issued documents (ADR-016 §6.5).',
                (string) $this->getKey(),
            ));
        }

        if ($doc === InvoiceDocumentStatus::PREPARED->value) {
            throw new \Exception(sprintf(
                'Cannot delete invoice [%s]: its document status is PREPARED — release it through cancelPreparedInvoice() instead (ADR-016 §6.5/§10).',
                (string) $this->getKey(),
            ));
        }

        // Implementation-gate finding 2 (2026-10-13): a proforma mid-conversion
        // (CONVERTING) owns a linked PREPARED child. Deleting the parent would
        // null the child's proforma_id (nullOnDelete), stranding it: invisible
        // to stalledPreparations(), un-cancellable, issued without the proforma
        // lock. Cancel the prepared document first — the guard's own path — and
        // the parent returns to DRAFT.
        if ($this->getRawOriginal('status') === InvoiceStatus::CONVERTING->value) {
            throw new \Exception(sprintf(
                'Cannot delete invoice [%s]: it is CONVERTING — cancel its prepared document through cancelPreparedInvoice() first (ADR-016 §6.5/§10).',
                (string) $this->getKey(),
            ));
        }
    }

    /**
     * Normalize one raw axis value for clause evaluation.
     *
     * Raw attributes arrive as ints (DB, mass assignment of enum cases),
     * numeric strings (some DB drivers) or enum instances; anything
     * unrepresentable maps to -1 so it fails every domain clause instead of
     * being silently treated as NULL.
     */
    private static function normalizeAxisValue(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            return (int) $value->value;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return -1;
    }

    /**
     * Mark this invoice PAID as part of a grouped payment (AID-30).
     *
     * Collection state (status + paid_at) is NOT fiscal content, so this is a
     * permitted transition even on immutable invoices. It goes through save()
     * deliberately, bypassing the update() immutability guard, which protects
     * fiscal content (amounts, dates, snapshots) — not payment state.
     *
     * @deprecated since 8.3.0 (AID-962), removal in 9.0: the collection state is derived from the active allocations by recomputeCollectionState(), the single in-package writer; a consumer calling this overwrites the derived state under its own responsibility.
     */
    public function markAsPaidViaGroupedPayment(DateTimeInterface $paidAt): void
    {
        $this->status  = InvoiceStatus::PAID;
        $this->paid_at = Carbon::instance($paidAt);
        $this->save();
    }

    /**
     * Restore this invoice's collection state when its grouped payment is reversed.
     * Same rationale as markAsPaidViaGroupedPayment(): permitted on immutable invoices.
     *
     * @deprecated since 8.3.0 (AID-962), removal in 9.0: the collection state is derived from the active allocations by recomputeCollectionState(), the single in-package writer; a consumer calling this overwrites the derived state under its own responsibility.
     */
    public function restoreStateViaGroupedPaymentReversal(InvoiceStatus $status, ?DateTimeInterface $paidAt): void
    {
        $this->status  = $status;
        $this->paid_at = $paidAt !== null ? Carbon::instance($paidAt) : null;
        $this->save();
    }

    /**
     * Sum of the applied amount of this invoice's ACTIVE allocations (AID-962).
     * Always a fresh query — never the loaded relation.
     *
     * @api New surface (AID-962).
     */
    public function amountPaid(): FixedDecimal
    {
        $paid = DB::table('grouped_payment_invoice')
            ->where('invoice_id', $this->getKey())
            ->where('status', AllocationStatus::ACTIVE->value)
            ->sum('applied_amount');

        return FixedDecimal::ofUnscaled((int) $paid, 2);
    }

    /**
     * `total_amount` minus amountPaid() — negative on rectificatives by
     * design (AID-962, DECISION 6).
     *
     * @api New surface (AID-962).
     */
    public function amountOutstanding(): FixedDecimal
    {
        return $this->total_amount->minus($this->amountPaid());
    }

    /**
     * Whether the active allocations cover part, but not all, of the total
     * (0 < paid < total) — read from the allocations, not from `status`.
     *
     * @api New surface (AID-962).
     */
    public function isPartiallyPaid(): bool
    {
        $paid = $this->amountPaid();

        return $paid->isPositive() && $paid->isLessThan($this->total_amount);
    }

    /**
     * Derive the collection state (`status` + `paid_at`) from the active
     * allocations and save() it (AID-962, spec §4.4) — the single in-package
     * writer of those two attributes.
     *
     * Call it ONLY on the instance re-read with lockForUpdate() inside the
     * operation's transaction: it reads `total_amount`, `due_date` and `status`
     * from $this, and save() writes the dirty attributes without refreshing.
     *
     * Guards (no-op, no exception), because the single writer cannot trust
     * its caller:
     *   1. not issued on the document axis (`! isIssued()`);
     *   2. persisted collection status DRAFT, CANCELLED, CONVERTED or
     *      CONVERTING — document/conversion states, never overwritten here;
     *   3. no allocation row at all (active or reversed): the legacy invoice
     *      that was born PAID, imported or stamped by the consumer stays put.
     *
     * Table, with paid = amountPaid():
     *   - paid = 0           → OVERDUE when due_date is set and strictly
     *                          before today, else SENT; paid_at = null;
     *   - 0 < paid < total   → PARTIALLY_PAID; paid_at = null;
     *   - paid ≥ total       → PAID; paid_at = the latest
     *                          COALESCE(applied_at, payment.paid_at) over the
     *                          ACTIVE allocations.
     *
     * @internal Called by the payment services under the invoice row lock.
     */
    public function recomputeCollectionState(): void
    {
        if (! $this->isIssued()) {
            return;
        }

        if (in_array($this->status, [
            InvoiceStatus::DRAFT,
            InvoiceStatus::CANCELLED,
            InvoiceStatus::CONVERTED,
            InvoiceStatus::CONVERTING,
        ], true)) {
            return;
        }

        $hasAllocations = DB::table('grouped_payment_invoice')
            ->where('invoice_id', $this->getKey())
            ->exists();

        if (! $hasAllocations) {
            return;
        }

        $paid = $this->amountPaid();

        if (! $paid->isPositive()) {
            $this->status = $this->due_date !== null && $this->due_date->lt(Carbon::today())
                ? InvoiceStatus::OVERDUE
                : InvoiceStatus::SENT;
            $this->paid_at = null;
        } elseif ($paid->isLessThan($this->total_amount)) {
            $this->status  = InvoiceStatus::PARTIALLY_PAID;
            $this->paid_at = null;
        } else {
            $this->status  = InvoiceStatus::PAID;
            $this->paid_at = $this->latestActiveSettlementDate();
        }

        $this->save();
    }

    /**
     * The latest COALESCE(allocation.applied_at, payment.paid_at) over the
     * ACTIVE allocations — the date the invoice became settled.
     */
    private function latestActiveSettlementDate(): ?Carbon
    {
        $settledAt = DB::table('grouped_payment_invoice')
            ->join('grouped_payments', 'grouped_payments.id', '=', 'grouped_payment_invoice.grouped_payment_id')
            ->where('grouped_payment_invoice.invoice_id', $this->getKey())
            ->where('grouped_payment_invoice.status', AllocationStatus::ACTIVE->value)
            ->selectRaw('MAX(COALESCE(grouped_payment_invoice.applied_at, grouped_payments.paid_at)) AS settled_at')
            ->value('settled_at');

        return $settledAt !== null ? Carbon::parse((string) $settledAt) : null;
    }

    /**
     * Override update to prevent modifications of immutable invoices.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        // Allow updating conversion-related fields even on immutable invoices (for proforma conversion)
        $conversionFields          = ['is_immutable', 'converted_invoice_id', 'converted_at', 'status'];
        $fiscalVerificationFields  = [
            'fiscal_verification_id',
            'fiscal_verification_qr',
            'fiscal_verification_hash',
            'fiscal_verified_at',
            'fiscal_verification_metadata',
        ];
        $attributeKeys              = array_keys($attributes);
        $isFiscalVerificationUpdate = array_intersect($attributeKeys, $fiscalVerificationFields) !== [];
        $isOnlyConversionFields     = ! empty($attributeKeys) && empty(array_diff($attributeKeys, $conversionFields));
        $isOnlyFiscalFields         = ! empty($attributeKeys) && empty(array_diff($attributeKeys, $fiscalVerificationFields));

        if ($this->is_immutable
            && ! ($isOnlyConversionFields || $isOnlyFiscalFields)) {
            throw new \Exception('Cannot update an immutable invoice');
        }

        return parent::update($attributes, $options);
    }

    // ========================================
    // RELATIONSHIPS
    // ========================================

    /**
     * Get the invoice items.
     *
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        $invoiceItemModel = ModelMappingService::getModelClass('invoice_item');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->hasMany($invoiceItemModel);
    }

    /**
     * Get the user that owns the invoice.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        $userModel = ModelMappingService::getModelClass('user');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->belongsTo($userModel);
    }

    /**
     * Get the billable user (user being billed) for this invoice (ADR-003).
     *
     * @return BelongsTo<User, $this>
     */
    public function billableUser(): BelongsTo
    {
        $userModel = ModelMappingService::getModelClass('user');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->belongsTo($userModel, 'billable_user_id');
    }

    /**
     * Allocation rows of this invoice, active and reversed alike (AID-962).
     *
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'invoice_id');
    }

    /**
     * Grouped payments that settled (or did settle, if reversed) this invoice.
     *
     * @return BelongsToMany<GroupedPayment, $this>
     */
    public function groupedPayments(): BelongsToMany
    {
        return $this->belongsToMany(GroupedPayment::class, 'grouped_payment_invoice', 'invoice_id', 'grouped_payment_id')
            ->withPivot(['applied_amount', 'previous_status', 'previous_paid_at', 'active_invoice_id'])
            ->withTimestamps();
    }

    /**
     * Get the proforma invoice (if this was converted from proforma)
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function proforma(): BelongsTo
    {
        return $this->belongsTo(self::class, 'proforma_id');
    }

    /**
     * Get the original invoice (if this is a rectificative)
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function rectifiesInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rectifies_invoice_id');
    }

    /**
     * Get rectificative invoices for this invoice
     *
     * @return HasMany<Invoice, $this>
     */
    public function rectificatives(): HasMany
    {
        return $this->hasMany(self::class, 'rectifies_invoice_id');
    }

    /**
     * Get invoices converted from this proforma
     *
     * @return HasMany<Invoice, $this>
     */
    public function convertedInvoices(): HasMany
    {
        return $this->hasMany(self::class, 'proforma_id');
    }

    /**
     * Get the company fiscal config snapshot for this invoice (ADR-001).
     *
     * This maintains a reference to the company's fiscal identity at invoice creation time.
     * IMMUTABLE: Once invoice is created, this relationship NEVER changes.
     *
     * Uses withTrashed(): a soft-deleted config is fiscal-history closure, not erasure.
     * The immutable invoice must keep access to its historical snapshot (AID-222).
     *
     * @return BelongsTo<CompanyFiscalConfig, $this>
     */
    public function companyFiscalConfig(): BelongsTo
    {
        return $this->belongsTo(CompanyFiscalConfig::class, 'company_fiscal_config_id')->withTrashed();
    }

    /**
     * Get the user tax profile snapshot for this invoice (ADR-003).
     *
     * This maintains a reference to the user's fiscal identity at invoice creation time.
     * IMMUTABLE: Once invoice is created, this relationship NEVER changes.
     *
     * Uses withTrashed(): a soft-deleted profile is fiscal-history closure, not erasure.
     * Without it, validateForVerifactu() wrongly fails on a valid issued invoice (AID-222).
     *
     * @return BelongsTo<UserTaxProfile, $this>
     */
    public function userTaxProfile(): BelongsTo
    {
        return $this->belongsTo(UserTaxProfile::class, 'user_tax_profile_id')->withTrashed();
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Whether this invoice has a tax QR to print.
     *
     * Two axes govern it, and invoice status is not one of them (AID-508):
     *
     * - Document class: only fiscal documents carry a tax QR. A proforma never
     *   does, in any state.
     * - Fiscal registration: the QR is an effect of the billing record — per the
     *   AEAT FAQ, definitive issuance happens when the billing record is generated
     *   and the QR incorporated, not when the PDF is delivered nor when AEAT
     *   accepts the record.
     *
     * Delivery and payment do not make a document fiscal: an invoice does not
     * acquire a QR by being paid.
     */
    public function shouldIncludeQR(): bool
    {
        return $this->serie->isFiscal() && $this->isFiscallyVerified();
    }

    /**
     * Check if this invoice has been fiscally verified (v0.4.0).
     */
    public function isFiscallyVerified(): bool
    {
        return $this->fiscal_verification_id !== null && $this->fiscal_verified_at !== null;
    }

    /**
     * Check the COLLECTION axis (ADR-016 §5.2): whether the billing status is
     * still DRAFT. This is NOT the document axis — the two diverge by design:
     * `issue()` leaves the collection status at DRAFT (the consumer moves it
     * as today), so a numbered, fiscally immutable invoice keeps returning
     * true here until someone stamps SENT or beyond. A PREPARED document and
     * a draft cancelled before issuance also read through this axis, not the
     * document one. For "has this document been issued?" use `isIssued()`.
     */
    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::DRAFT;
    }

    /**
     * Check the DOCUMENT axis (ADR-016 §5.2): whether this fiscal row's
     * document status is ISSUED — numbered, with the five issuance fields
     * stamped (§8.2 C4) and fiscally immutable.
     *
     * A proforma row has no document axis (§8.2 C2: the axis exists only for
     * fiscal rows) and never reads as issued; gate on the serie when a
     * proforma needs separate handling.
     *
     * @api New surface (AID-1687).
     */
    public function isIssued(): bool
    {
        return $this->invoice_document_status === InvoiceDocumentStatus::ISSUED;
    }

    /**
     * Check the DOCUMENT axis (ADR-016 §5.2): whether this row has NOT been
     * issued. True for an unnumbered DRAFT, a conversion-born PREPARED and a
     * document cancelled before issuance — and, by the plain negation
     * contract, for proforma rows whose axis is absent (see `isIssued()`).
     *
     * @api New surface (AID-1687).
     */
    public function isUnissued(): bool
    {
        return ! $this->isIssued();
    }

    /**
     * Get decrypted issuer snapshot (v0.4.0).
     *
     * @return array<string, mixed>|null
     */
    public function getIssuerSnapshotData(): ?array
    {
        if (! $this->issuer_snapshot) {
            return null;
        }

        return json_decode(Crypt::decryptString($this->issuer_snapshot), true);
    }

    /**
     * Get decrypted customer snapshot (v0.4.0).
     *
     * @return array<string, mixed>|null
     */
    public function getCustomerSnapshotData(): ?array
    {
        if (! $this->customer_snapshot) {
            return null;
        }

        return json_decode(Crypt::decryptString($this->customer_snapshot), true);
    }

    /**
     * Get decrypted fiscal snapshot (v0.4.0).
     *
     * @return array<string, mixed>|null
     */
    public function getFiscalSnapshotData(): ?array
    {
        if (! $this->fiscal_snapshot) {
            return null;
        }

        return json_decode(Crypt::decryptString($this->fiscal_snapshot), true);
    }

    /**
     * Get the invoice type for PDF generation
     *
     * @return string Invoice type
     */
    public function getInvoiceType(): string
    {
        return $this->serie->label();
    }

    /**
     * Generate PDF for this invoice.
     *
     * The consumer's entry point to the PDF subsystem (ADR-011, AID-502) —
     * the services behind it (`PDFService`, `DomPDFService`) are `@internal`
     * and may change without a major version. The result shape IS the
     * contract:
     *
     * - success: `['success' => true, 'pdf_path' => string, 'pdf_url' => null,
     *   'qr_data' => array, 'connector_used' => string, 'generated_at' => string]`.
     *   `pdf_url` is always null — invoices are private; delivery belongs to
     *   the consumer via an authorised controller (AID-508).
     * - failure: `['success' => false, 'error' => string,
     *   'connector_used' => string|null, 'generated_at' => string]`. The
     *   subsystem never throws to the caller: the frontier translates every
     *   failure — including a consumer template dropping mandatory fiscal
     *   content (`FiscalContentMissingException`, ADR-011) — into this shape
     *   with a single log line (AID-535).
     *
     * @api Supported public surface (AID-413; see docs/api-surface.md).
     *
     * @return array<string, mixed> PDF generation result
     */
    public function generatePDF(): array
    {
        $pdfService = app(PDFService::class);

        return $pdfService->generatePDF($this);
    }

    /**
     * The invoice PDF's filename. Single source of the name, shared with the
     * writer (DomPDFService::savePDF) so both sides always agree (AID-508).
     */
    public function pdfFilename(): string
    {
        return 'invoice_'.$this->id.'_'.$this->getInvoiceType().'.pdf';
    }

    /**
     * Get PDF path for this invoice
     *
     * @return string|null PDF file path
     */
    public function getPDFPath(): ?string
    {
        $pdfPath = storage_path('app/invoices/'.$this->pdfFilename());

        return file_exists($pdfPath) ? $pdfPath : null;
    }

    /**
     * Get PDF URL for this invoice
     *
     * Always null: larabill does not publish invoices. Delivery is the consumer's
     * responsibility, through an authorised controller. The old
     * url('storage/invoices/...') was an UNAUTHORISED public link that bypassed the
     * consumer's policy — and a URL leaks through logs, history and Referer headers,
     * so protection cannot rest on a UUID being hard to guess (AID-508).
     *
     * @return string|null Always null
     */
    public function getPDFUrl(): ?string
    {
        return null;
    }

    // ========================================
    // SCOPES
    // ========================================

    /**
     * Scope for specific serie
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSerie(Builder $query, InvoiceSerieType $serie): Builder
    {
        return $query->where('serie', $serie->value);
    }

    /**
     * Scope for specific fiscal year
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFiscalYear(Builder $query, int $year): Builder
    {
        return $query->where('fiscal_year', $year);
    }

    /**
     * Scope for specific status
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStatus(Builder $query, InvoiceStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Scope for proforma invoices
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeProformas(Builder $query): Builder
    {
        return $query->where('serie', InvoiceSerieType::PROFORMA->value);
    }

    /**
     * Scope for final invoices (not proformas or rectificatives)
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFinal(Builder $query): Builder
    {
        return $query->where('serie', InvoiceSerieType::INVOICE->value);
    }

    /**
     * Scope for rectificative invoices
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRectificatives(Builder $query): Builder
    {
        return $query->where('serie', InvoiceSerieType::RECTIFICATIVE->value);
    }

    /**
     * Scope for the stalled preparations of the ADR-016 lifecycle (§11.3):
     * conversion-born documents sitting at PREPARED — the state
     * `convertAndIssue()` leaves behind when its issuance transaction fails
     * and the caller never resumed it. Every row here awaits either `issue()`
     * or `cancelPreparedInvoice()`; the deeper deadline reporting is R4's
     * (AID-1631). The artisan report `larabill:stalled-preparations` reads
     * this scope.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStalledPreparations(Builder $query): Builder
    {
        return $query
            ->where('invoice_document_status', InvoiceDocumentStatus::PREPARED->value)
            ->whereNotNull('proforma_id');
    }

    // ========================================
    // TAX CALCULATION METHODS
    // ========================================

    /**
     * Check if this invoice requires VAT to be applied.
     *
     * An invoice requires VAT when it's NOT using reverse charge mechanism.
     * Reverse charge (ROI) is used for B2B intra-community transactions where
     * the customer (not the issuer) is responsible for paying VAT.
     *
     * @return bool True if VAT should be applied by the issuer
     */
    public function requiresVAT(): bool
    {
        return ! $this->is_roi_taxed;
    }

    /**
     * Whether this document actually carries tax.
     *
     * Single owner of the definition (AID-929). It lived as a private helper
     * inside `VerifactuAdapter`, which needs it to enforce AEAT rule 1237 (an
     * N2 breakdown cannot carry VAT); the issuance guard needs the SAME
     * question answered before sealing, and two copies of a fiscal predicate
     * is how they drift apart.
     *
     * Two sources, because either can hold the figure: the header total, and
     * the per-line `taxes_applied` breakdown — a line may state a zero total
     * while its breakdown carries an amount.
     *
     * Non-zero, NOT positive (AID-929 D8): the inherited implementation tested
     * `> 0`, so a credit line with negative tax and no breakdown slipped
     * through unnoticed. A negative amount is as invalid under reverse charge
     * as a positive one.
     */
    public function hasRealTax(): bool
    {
        if (($this->total_tax_amount?->unscaledValue() ?? 0) !== 0) {
            return true;
        }

        foreach ($this->items as $item) {
            foreach ($item->taxes_applied ?? [] as $tax) {
                if ((int) ($tax['amount'] ?? 0) !== 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Snapshot fiscal configs at invoice creation (ADR-001).
     *
     * CRITICAL: This method loads and stores the fiscal identities
     * of both company (issuer) and customer (recipient) at the moment
     * of invoice creation, ensuring IMMUTABILITY.
     *
     * Includes:
     * - FK references to CompanyFiscalConfig and UserTaxProfile
     * - Encrypted snapshots for complete audit trail
     *
     * Called automatically on invoice creation (boot::creating).
     *
     * @api Amber-band contract operation (AID-407): consumers call it only behind an app-side port.
     *
     * @param  bool  $generateEncrypted  Whether to generate encrypted snapshots (default: true)
     */
    public function snapshotFiscalConfigs(bool $generateEncrypted = true): void
    {
        $invoiceDate = $this->invoice_date ?? now();

        // 1. Company fiscal config (emisor)
        $companyConfig = CompanyFiscalConfig::getValidAt($invoiceDate);
        if ($companyConfig) {
            $this->company_fiscal_config_id = $companyConfig->id;

            // Generate encrypted issuer snapshot (ADR-001)
            if ($generateEncrypted && ! $this->issuer_snapshot) {
                $this->issuer_snapshot = $this->generateIssuerSnapshot($companyConfig);
            }
        }

        // 2. User tax profile (receptor - billable user, ADR-003)
        $userTaxProfile = null;
        if ($this->billable_user_id) {
            $userTaxProfile = UserTaxProfile::getValidForOwnerAt($this->billable_user_id, $invoiceDate);
            if ($userTaxProfile) {
                $this->user_tax_profile_id = $userTaxProfile->id;
            }

            // Generate encrypted customer snapshot (ADR-001)
            if ($generateEncrypted && ! $this->customer_snapshot) {
                $billableUser = $this->billableUser;
                if ($billableUser) {
                    $this->customer_snapshot = $this->generateBillableUserSnapshot($billableUser, $userTaxProfile);
                }
            }
        }

        // 3. Generate fiscal context snapshot (ADR-001)
        if ($generateEncrypted && ! $this->fiscal_snapshot && $companyConfig) {
            $this->fiscal_snapshot = $this->generateFiscalContextSnapshot($companyConfig, $userTaxProfile);
        }
    }

    /**
     * Generate encrypted issuer snapshot from CompanyFiscalConfig (ADR-001).
     *
     * @param  CompanyFiscalConfig  $config  Active company config at invoice date
     * @return string Encrypted JSON snapshot
     */
    /** @internal Called by issue() to freeze the issuer context at the issuance instant (ADR-001). */
    public function generateIssuerSnapshot(CompanyFiscalConfig $config): string
    {
        $data = [
            'config_id'         => $config->id,
            'business_name'     => $config->business_name,
            'tax_id'            => $config->tax_id,
            'legal_entity_type' => $config->legal_entity_type,
            'address'           => $config->address,
            'city'              => $config->city,
            'state'             => $config->state,
            'zip_code'          => $config->zip_code,
            'country_code'      => $config->country_code,
            'is_oss'            => $config->is_oss,
            'is_roi'            => $config->is_roi,
            'currency'          => $config->currency,
            'valid_from'        => $config->valid_from->toIso8601String(),
            'valid_until'       => $config->valid_until?->toIso8601String(),
            'snapshot_at'       => now()->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Aggregate the lines' immutable taxes_applied entries into the frozen
     * per-rate base/amount pairs the fiscal snapshot carries (AID-534).
     *
     * @return array<int, array{source_rate_id: mixed, name: mixed, rate: mixed, base: int, amount: int}>
     */
    private function aggregateTaxRulesApplied(): array
    {
        $rules = [];

        foreach ($this->items as $item) {
            $entries = is_array($item->taxes_applied) ? $item->taxes_applied : [];

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $sourceRateId = $entry['source_rate_id'] ?? null;
                $name         = $entry['name']           ?? null;
                $rate         = $entry['rate']           ?? null;
                $key          = (string) json_encode([$sourceRateId, $name, $rate]);

                $rules[$key] ??= [
                    'source_rate_id' => $sourceRateId,
                    'name'           => $name,
                    'rate'           => $rate,
                    'base'           => 0,
                    'amount'         => 0,
                ];

                $rules[$key]['base']   += $item->taxable_amount->unscaledValue();
                $rules[$key]['amount'] += (int) ($entry['amount'] ?? 0);
            }
        }

        return array_values($rules);
    }

    /**
     * Generate encrypted billable user snapshot (ADR-001 + ADR-003).
     *
     * @param  Model  $billableUser  The user being billed
     * @param  UserTaxProfile|null  $taxProfile  User's tax profile at invoice date
     * @return string Encrypted JSON snapshot
     */
    /** @internal Called by issue() to freeze the customer context at the issuance instant (ADR-001). */
    public function generateBillableUserSnapshot(Model $billableUser, ?UserTaxProfile $taxProfile): string
    {
        $data = [
            'billable_user_id'     => $billableUser->id,
            'user_name'            => $billableUser->display_name              ?? $billableUser->name ?? null,
            'user_email'           => $billableUser->email                     ?? null,
            'relationship_type'    => $billableUser->relationship_type?->value ?? 0,
            'parent_user_id'       => $billableUser->parent_user_id            ?? null,
            'legal_entity_code'    => $billableUser->legal_entity_type_code    ?? null,
            'tax_profile_id'       => $taxProfile?->id,
            'fiscal_name'          => $taxProfile?->fiscal_name ?? $billableUser->display_name ?? $billableUser->name ?? null,
            'tax_id'               => $taxProfile?->tax_id,
            'legal_entity_type'    => $taxProfile?->legal_entity_type_code ?? $billableUser->legal_entity_type_code ?? null,
            'address'              => $taxProfile?->address,
            'city'                 => $taxProfile?->city,
            'state'                => $taxProfile?->state,
            'zip_code'             => $taxProfile?->zip_code,
            'country_code'         => $taxProfile?->country_code,
            'is_company'           => $taxProfile?->is_company           ?? false,
            'is_eu_vat_registered' => $taxProfile?->is_eu_vat_registered ?? false,
            'is_exempt_vat'        => $taxProfile?->is_exempt_vat        ?? false,
            'profile_valid_from'   => $taxProfile?->valid_from?->toIso8601String(),
            'profile_valid_until'  => $taxProfile?->valid_until?->toIso8601String(),
            'snapshot_at'          => now()->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Generate encrypted fiscal context snapshot (ADR-001).
     *
     * Contains fiscal rules and context at time of invoice creation.
     *
     * @param  CompanyFiscalConfig  $companyConfig  Active company config
     * @param  UserTaxProfile|null  $customerProfile  Customer's tax profile
     * @return string Encrypted JSON snapshot
     */
    /**
     * The ONE fiscal-context snapshot generator (ADR-016 AID-1649 resolution):
     * both birth paths (the creating hook and the service's row builder) and
     * issue()'s regeneration call this, so an issued document owns the same
     * key set whichever way it was born (AID-328: one vocabulary per snapshot;
     * the AID-929/1649 drift history is the proof of why).
     *
     * @internal issue() and the service's row builder are the external callers.
     */
    public function generateFiscalContextSnapshot(CompanyFiscalConfig $companyConfig, ?UserTaxProfile $customerProfile): string
    {
        $data = [
            'tax_rules_applied'        => $this->aggregateTaxRulesApplied(),
            'fiscal_year'              => $this->fiscal_year   ?? now()->year,
            'serie'                    => $this->serie?->value ?? $this->serie,
            'series_number'            => $this->series_number,
            'fiscal_number'            => $this->fiscal_number,
            'invoice_date'             => ($this->invoice_date ?? now())->toIso8601String(),
            'issuer_country'           => $companyConfig->country_code,
            'customer_country'         => $customerProfile?->country_code,
            'is_intra_community'       => $this->isIntraCommunityTransaction($companyConfig, $customerProfile),
            'is_roi_applied'           => $this->is_roi_taxed ?? false,
            'issuer_is_oss'            => $companyConfig->is_oss,
            'customer_is_vat_reg'      => $customerProfile?->is_eu_vat_registered ?? false,
            'currency'                 => $companyConfig->currency                ?? 'EUR',
            'exchange_rate'            => 1.0, // TODO: Implement multi-currency
            'company_fiscal_config_id' => $companyConfig->id,
            'user_tax_profile_id'      => $customerProfile?->id,
            'snapshot_at'              => now()->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Check if transaction is intra-community (EU cross-border B2B).
     *
     * @param  CompanyFiscalConfig  $issuer  Issuer's fiscal config
     * @param  UserTaxProfile|null  $customer  Customer's tax profile
     * @return bool True if intra-community transaction
     */
    protected function isIntraCommunityTransaction(CompanyFiscalConfig $issuer, ?UserTaxProfile $customer): bool
    {
        if (! $customer) {
            return false;
        }

        // Both must be in EU
        $euCountries = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

        $issuerInEU   = in_array($issuer->country_code, $euCountries, true);
        $customerInEU = in_array($customer->country_code, $euCountries, true);

        // Different countries, both in EU, customer is VAT registered
        return $issuerInEU
            && $customerInEU
            && $issuer->country_code !== $customer->country_code
            && $customer->is_eu_vat_registered;
    }

    /**
     * Get full company fiscal identity at invoice creation.
     *
     * Returns the CompanyFiscalConfig snapshot.
     * IMMUTABLE: Always returns the config valid at invoice_date.
     */
    public function getCompanyFiscalSnapshot(): ?CompanyFiscalConfig
    {
        return $this->companyFiscalConfig;
    }

    /**
     * Get full user fiscal identity at invoice creation.
     *
     * Returns the UserTaxProfile snapshot.
     * IMMUTABLE: Always returns the data valid at invoice_date.
     */
    public function getUserTaxProfileSnapshot(): ?UserTaxProfile
    {
        return $this->userTaxProfile;
    }

    /**
     * Check if invoice has fiscal reference FKs loaded.
     *
     * @param  bool  $includeEncrypted  Also check encrypted snapshots (default: false)
     */
    public function hasFiscalSnapshots(bool $includeEncrypted = false): bool
    {
        $hasReferences = $this->company_fiscal_config_id !== null && $this->user_tax_profile_id !== null;

        if (! $includeEncrypted) {
            return $hasReferences;
        }

        return $hasReferences && $this->hasEncryptedSnapshots();
    }

    /**
     * Check if invoice has all encrypted snapshots (ADR-001).
     *
     * All three snapshots are required for full fiscal audit trail:
     * - issuer_snapshot: Company fiscal identity
     * - customer_snapshot: Billable user identity
     * - fiscal_snapshot: Fiscal context and rules
     */
    public function hasEncryptedSnapshots(): bool
    {
        return $this->issuer_snapshot   !== null
            && $this->customer_snapshot !== null
            && $this->fiscal_snapshot   !== null;
    }

    /**
     * Regenerate encrypted snapshots (ADR-001).
     *
     * Forces regeneration of all encrypted snapshots.
     * Use with caution - only for draft/pending invoices.
     *
     * @throws \Exception If invoice is immutable
     */
    public function regenerateEncryptedSnapshots(): void
    {
        if ($this->is_immutable) {
            throw new \Exception('Cannot regenerate snapshots on immutable invoice');
        }

        // Clear existing snapshots
        $this->issuer_snapshot   = null;
        $this->customer_snapshot = null;
        $this->fiscal_snapshot   = null;

        // Regenerate
        $this->snapshotFiscalConfigs(true);
    }

    /**
     * Check if this invoice uses reverse charge mechanism (ROI).
     *
     * Reverse charge applies to:
     * - B2B intra-community transactions (EU)
     * - When both issuer and customer are VAT registered
     * - Customer is responsible for paying VAT in their country
     *
     * @return bool True if reverse charge applies
     */
    public function isReverseCharge(): bool
    {
        // Cast: an in-memory invoice with the attribute unset yields null (Eloquent
        // skips the boolean cast on an absent key), which would violate `: bool`.
        return (bool) $this->is_roi_taxed;
    }

    /**
     * Calculate totals from invoice items.
     *
     * Recalculates taxable_amount, total_tax_amount, and total_amount
     * based on the sum of all invoice items. Updates the model but does not save.
     */
    public function calculateTotals(): self
    {
        // Items persist integer cents, so the SQL SUM is exact (sum of per-line
        // rounded amounts — the EU/Spain aggregation rule). Wrap back into a
        // scale-2 FixedDecimal for the cast.
        $this->taxable_amount   = FixedDecimal::ofUnscaled((int) $this->items()->sum('taxable_amount'), 2);
        $this->total_tax_amount = FixedDecimal::ofUnscaled((int) $this->items()->sum('total_tax_amount'), 2);
        $this->total_amount     = FixedDecimal::ofUnscaled((int) $this->items()->sum('total_amount'), 2);

        return $this;
    }
}
