<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Support\UpgradePath;

use AichaDigital\Larabill\Console\LarabillInstallCommand;
use ReflectionClass;
use RuntimeException;

/**
 * Base-set resolution for slice upgrade-path tests (AID-1619).
 *
 * A slice test simulates a sequential consumer standing JUST BEFORE the
 * slice migration runs. That state is defined by $migrationOrder — every
 * migration ordered before the slice — and NOT by the manifest's current
 * `in_base` set: the release-time stamp (AID-398) flips past slices into
 * the base on every cut, which breaks any test that seeds legacy-shaped
 * data against `in_base`.
 */
final class ManifestBase
{
    /**
     * Manifest migration file names a sequential consumer has already run
     * before the given `$migrationOrder` slice name.
     *
     * @return list<string>
     */
    public static function migrationsBefore(string $sliceOrderName): array
    {
        $order = (new ReflectionClass(LarabillInstallCommand::class))
            ->getDefaultProperties()['migrationOrder'] ?? [];
        $byName = array_flip($order);

        if (! isset($byName[$sliceOrderName])) {
            throw new RuntimeException("Unknown migrationOrder name: {$sliceOrderName}");
        }

        $sliceKey = (int) $byName[$sliceOrderName];
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/Contract/release-migration-manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $before = [];

        foreach ($manifest['migrations'] as $entry) {
            $base = basename((string) $entry['name'], '.php');

            // A manifest filename is `<timestamp>_<orderName>` for every
            // shipped migration EXCEPT the AID-1617 divergence, where the
            // order name is a PREFIX of the file's name-part
            // (add_modality_to_article_service_status → ..._status_table).
            // Accept both, but ONLY unambiguously.
            $matches = [];
            foreach ($order as $key => $orderName) {
                if (preg_match('/^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{4,6}_'.preg_quote((string) $orderName, '/').'($|_)/', $base) === 1) {
                    $matches[$key] = true;
                }
            }

            if (count($matches) !== 1) {
                throw new RuntimeException(
                    "Manifest migration {$entry['name']} matched ".count($matches).' $migrationOrder names — resolve it by hand.'
                );
            }

            if ((int) array_key_first($matches) < $sliceKey) {
                $before[] = (string) $entry['name'];
            }
        }

        return $before;
    }
}
