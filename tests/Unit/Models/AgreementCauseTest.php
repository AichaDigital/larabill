<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

describe('AgreementCause — ADR-015 §2.2 cause vocabulary', function () {
    it('seeds the exact closed-scope keys after migration', function () {
        $keys = AgreementCause::query()
            ->where('scope', AgreementCause::SCOPE_CLOSED)
            ->orderBy('id')
            ->pluck('key')
            ->all();

        expect($keys)->toBe([
            'unpaid_termination',
            'client_request',
            'fulfilment',
            'fraud',
            'provider_rescission',
            'legacy_cancellation',
        ]);
    });

    it('seeds the exact suspended-scope keys after migration', function () {
        $keys = AgreementCause::query()
            ->where('scope', AgreementCause::SCOPE_SUSPENDED)
            ->orderBy('id')
            ->pluck('key')
            ->all();

        expect($keys)->toBe(['unpaid', 'hacking', 'other']);
    });

    it('seeds every cause active by default', function () {
        expect(AgreementCause::query()->where('is_active', false)->count())->toBe(0)
            ->and(AgreementCause::count())->toBe(9);
    });

    it('findActive resolves a cause by scope and key', function () {
        $cause = AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'legacy_cancellation');

        expect($cause->key)->toBe('legacy_cancellation')
            ->and($cause->scope)->toBe(AgreementCause::SCOPE_CLOSED)
            ->and($cause->is_active)->toBeTrue();
    });

    it('rejects a wrong-scope resolution with a loud typed error (application-enforced invariant)', function () {
        // `unpaid` is a SUSPENSION cause — resolving it as a closure cause
        // must throw, never silently return the row.
        AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'unpaid');
    })->throws(InvalidArgumentException::class);

    it('rejects an unknown key with a loud typed error', function () {
        AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'no_such_cause');
    })->throws(InvalidArgumentException::class);

    it('rejects an inactive cause for new resolution', function () {
        AgreementCause::query()->where('key', 'fraud')->firstOrFail()->update(['is_active' => false]);

        AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'fraud');
    })->throws(InvalidArgumentException::class);

    it('still resolves an inactive cause row for history reads without the helper', function () {
        $cause = AgreementCause::query()->where('key', 'fraud')->firstOrFail();
        $cause->update(['is_active' => false]);

        // The row stays queryable — retirement is is_active, never deletion;
        // history facts pointing at it must keep resolving.
        expect(AgreementCause::query()->find($cause->id)->key)->toBe('fraud');
    });

    it('declares the invariant as application-level only — a raw bulk write bypasses every model guard', function () {
        // DECLARED LIMIT (ADR-015 §2.2): a cross-table CHECK is not expressible
        // here, so the scope invariant is NOT database-enforced — the FK proves
        // existence, never scope. A raw bulk write (which also bypasses model
        // events, per Eloquent Builder::update) CAN persist a wrong-scope
        // cause. The guard is the model helper (`findActive`, and the slice-B
        // close/suspend API built on it), pinned by the wrong-scope tests
        // above — the database deliberately stays permissive.
        $service         = ArticleServiceStatus::factory()->create();
        $suspensionCause = AgreementCause::query()->where('key', 'unpaid')->firstOrFail();

        DB::table('article_service_status')
            ->where('id', $service->id)
            ->update(['closure_cause_id' => $suspensionCause->id]);

        // The wrong-scope cause persisted: existence only, scope unguarded.
        expect($service->fresh()->closure_cause_id)->toBe($suspensionCause->id);
    });
});
