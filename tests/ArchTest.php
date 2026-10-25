<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

/*
 * AID-962 (spec §4.4): the collection state of an invoice (`status`,
 * `paid_at`) has ONE in-package writer, Invoice::recomputeCollectionState().
 * The two 8.x writers stay public for consumers (contract surface), but no
 * class under src/ other than Invoice may call them. Pest's toUse() sees
 * class and function dependencies, not method calls, so the rule tokenises
 * src/ instead: a call is the method name right after `->`, `?->` or `::`,
 * which keeps docblocks that merely name the methods out of the match.
 * Blind spot: a dynamic call (`$invoice->{$name}()`, call_user_func) is not
 * seen.
 */
function aid962CollectionWriterCalls(): array
{
    $names = ['markAsPaidViaGroupedPayment', 'restoreStateViaGroupedPaymentReversal'];
    $ops   = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];
    $src   = dirname(__DIR__).'/src';
    $hits  = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = $file->getPathname();
        if ($file->getExtension() !== 'php' || $path === $src.'/Models/Invoice.php') {
            continue;
        }

        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($path)),
            fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_STRING && in_array($token[1], $names, true)
                                 && $i > 0 && is_array($tokens[$i - 1]) && in_array($tokens[$i - 1][0], $ops, true)) {
                $hits[] = substr($path, strlen($src) + 1).':'.$token[2].' '.$token[1];
            }
        }
    }

    return $hits;
}

/*
 * AID-962 (spec §4.4), second rule: derive the invariant from the VALUES, not
 * from the two method names above. The collection-state values are
 * InvoiceStatus::PAID, PARTIALLY_PAID and OVERDUE plus the column `paid_at`;
 * only src/Models/Invoice.php (recomputeCollectionState() and the two
 * deprecated writers) and src/Database/Factories/ may write them. Lifecycle
 * statuses (DRAFT, SENT, CONVERTING, CONVERTED, CANCELLED, PENDING) are written
 * by other services and are not collection state.
 *
 * Detection (tokens, whitespace/comments/docblocks skipped):
 *  - status write: `InvoiceStatus::<PAID|PARTIALLY_PAID|OVERDUE>` whose previous
 *    token is a plain `=` (assignment, any receiver) or a `=>` after the
 *    string 'status'/"status" (array write, update([...]), DB::table(...), any
 *    receiver). `===`, `!==`, in_array(), whereIn(), match arms and the payable
 *    lists are different tokens/positions and do not match; `->value` after the
 *    constant is irrelevant.
 *  - paid_at write: `->paid_at` / `?->paid_at` followed by a plain `=`, or the
 *    string 'paid_at'/"paid_at" followed by `=>`.
 *
 * Allowlist for paid_at: a token walk cannot tell the GroupedPayment's own
 * `paid_at` (the payment date) from an invoice's, and a receiver heuristic is
 * brittle, so the legitimate sites are pinned as `path => expected count`.
 * Pinned: Models/GroupedPayment.php (1, the `'paid_at' => 'datetime'` cast) and
 * Services/PaymentService.php (1, the GroupedPayment::create array in process()).
 * A NEW write site anywhere, or a second one in that file, changes a count and
 * goes red.
 *
 * Blind spots: dynamic property names ($i->{$col} = ...), fill()/forceFill()
 * or update() with a variable array, a status chosen via ternary/variable
 * (`'status' => $cond ? InvoiceStatus::PAID : ...` is not preceded by `=>`).
 */
function aid962CollectionStateWrites(): array
{
    $values = ['PAID', 'PARTIALLY_PAID', 'OVERDUE'];
    $src    = dirname(__DIR__).'/src';
    $skip   = [$src.'/Models/Invoice.php', $src.DIRECTORY_SEPARATOR.'Database'.DIRECTORY_SEPARATOR.'Factories'];
    $status = [];
    $paidAt = [];

    $strIs = static fn ($t, string $v): bool => is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && trim($t[1], '\'"') === $v;

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = $file->getPathname();
        if ($file->getExtension() !== 'php' || $path === $skip[0] || str_starts_with($path, $skip[1].DIRECTORY_SEPARATOR)) {
            continue;
        }
        $rel = substr($path, strlen($src) + 1);

        $t = array_values(array_filter(
            token_get_all((string) file_get_contents($path)),
            fn ($x): bool => ! is_array($x) || ! in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        foreach ($t as $i => $tok) {
            if (! is_array($tok)) {
                continue;
            }
            // InvoiceStatus::PAID as the value of a write. Nested single-line
            // conditions, never two consecutive lines carrying `===`/`&&`:
            // pint's `align_single_space` is not stable on those (it grew the
            // padding on every run and the CI job went red).
            if ($tok[0] === T_STRING && in_array($tok[1], $values, true) && $i >= 3) {
                $paamayim = $t[$i - 1];
                $enum     = $t[$i - 2];

                if (is_array($paamayim) && $paamayim[0] === T_DOUBLE_COLON && is_array($enum) && str_ends_with($enum[1], 'InvoiceStatus')) {
                    $before = $t[$i - 3];

                    if ($before === '=' || (is_array($before) && $before[0] === T_DOUBLE_ARROW && $i >= 4 && $strIs($t[$i - 4], 'status'))) {
                        $status[] = $rel.':'.$tok[2].' InvoiceStatus::'.$tok[1];
                    }
                }
            }
            // ->paid_at = ...
            if ($tok[0] === T_STRING && $tok[1] === 'paid_at' && $i >= 1 && is_array($t[$i - 1])) {
                $viaArrow = in_array($t[$i - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);

                if ($viaArrow && ($t[$i + 1] ?? null) === '=') {
                    $paidAt[$rel][] = $tok[2];
                }
            }
            // 'paid_at' => ...
            if ($strIs($tok, 'paid_at') && is_array($t[$i + 1] ?? null) && $t[$i + 1][0] === T_DOUBLE_ARROW) {
                $paidAt[$rel][] = $tok[2];
            }
        }
    }

    ksort($paidAt);

    return ['status' => $status, 'paid_at' => array_map('count', $paidAt)];
}

test('invoice collection state has one in-package writer', function () {
    expect(aid962CollectionWriterCalls())->toBe([]);

    $writes = aid962CollectionStateWrites();

    // One assertion over both rules so a failure names every offending site.
    expect($writes)->toBe([
        'status'  => [],
        'paid_at' => ['Models/GroupedPayment.php' => 1, 'Services/PaymentService.php' => 1],
    ]);
});
