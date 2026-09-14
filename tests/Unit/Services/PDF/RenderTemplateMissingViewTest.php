<?php

declare(strict_types=1);

use AichaDigital\Larabill\Services\PDF\DomPDFService;

/**
 * AID-1305 — DomPDFService::renderTemplate() checks the view exists first.
 *
 * The view name is runtime data (the template registry's `template_path`,
 * ADR-011), and a seeded row once pointed at a blade that never existed
 * (AID-450). The existence check was added so static analysis can prove the
 * name before rendering. These tests pin the failure it now raises: always an
 * InvalidArgumentException — the same class the framework raised — whose
 * message names the view exactly as requested.
 *
 * Measured against the pre-fix body: the plain name already produced this
 * message, but a namespaced one did not. The framework dropped the namespace
 * (`View [pdf.invoice.never-existed] not found.`) or, for an unknown namespace,
 * named only the namespace (`No hint path defined for [no-such-namespace].`).
 * Those two cases go red against the old code; the message reaches consumers
 * through the `error` key of Invoice::generatePDF(), so the change is recorded
 * in the CHANGELOG.
 */
describe('DomPDFService::renderTemplate — view existence (AID-1305)', function () {
    beforeEach(function () {
        $this->render = function (string $view, array $data = []): string {
            $engine = new DomPDFService;
            $method = new ReflectionMethod($engine, 'renderTemplate');
            $method->setAccessible(true);

            return (string) $method->invoke($engine, $view, $data);
        };
    });

    it('rejects a package-namespaced view that does not exist, naming it', function () {
        expect(fn () => ($this->render)('larabill::pdf.invoice.never-existed'))
            ->toThrow(InvalidArgumentException::class, 'View [larabill::pdf.invoice.never-existed] not found.');
    });

    it('rejects a view under an unknown namespace, naming it', function () {
        expect(fn () => ($this->render)('no-such-namespace::pdf.invoice'))
            ->toThrow(InvalidArgumentException::class, 'View [no-such-namespace::pdf.invoice] not found.');
    });

    it('rejects a plain view name that does not exist, naming it', function () {
        expect(fn () => ($this->render)('pdf.invoice.never-existed'))
            ->toThrow(InvalidArgumentException::class, 'View [pdf.invoice.never-existed] not found.');
    });
});
