<?php

namespace App\Services\Import\Persistence;

/**
 * Persists normalized taxa rows and accepted taxon names.
 */
class TaxaImportService implements TaxonomyEntityImportServiceInterface
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    public function import(array $rows, bool $dryRun = false): array
    {
        $counts = [
            'fetched' => count($rows),
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        if ($rows === []) {
            return $counts;
        }

        $db = db_connect();

        if (! $dryRun) {
            $db->transStart();
        }

        try {
            $orderMap = $this->lookupByTaxonIdentifier('orders');
            $superfamilyMap = $this->lookupByTaxonIdentifier('superfamilies');
            $familyMap = $this->lookupByTaxonIdentifier('families');
            $groupMap = $this->lookupByExternalKey('taxon_groups');
            $schemeMap = $this->lookupByExternalKey('recording_schemes');

            foreach ($rows as $row) {
                $taxonIdentifier = trim((string) ($row['taxon_identifier'] ?? ''));
                $sciNameIdentifier = trim((string) ($row['scientific_name_identifier'] ?? ''));
                $scientificName = trim((string) ($row['scientific_name'] ?? ''));
                $vernacularName = trim((string) ($row['vernacular_name'] ?? ''));
                $orderIdentifier = trim((string) ($row['order_taxon_identifier'] ?? ''));
                $familyIdentifier = trim((string) ($row['family_taxon_identifier'] ?? ''));
                $superfamilyIdentifier = trim((string) ($row['superfamily_taxon_identifier'] ?? ''));
                $groupExternalKey = trim((string) ($row['taxon_group_external_key'] ?? ''));
                $schemeExternalKey = trim((string) ($row['recording_scheme_external_key'] ?? ''));

                if ($taxonIdentifier === '' || $sciNameIdentifier === '' || $scientificName === '' || $vernacularName === '') {
                    $counts['skipped']++;
                    continue;
                }

                $orderId = $orderMap[$orderIdentifier] ?? null;
                $familyId = $familyMap[$familyIdentifier] ?? null;
                $superfamilyId = $superfamilyMap[$superfamilyIdentifier] ?? null;
                $groupId = $groupMap[$groupExternalKey] ?? null;
                $schemeId = $schemeMap[$schemeExternalKey] ?? null;

                if ($orderId === null || $familyId === null || $groupId === null) {
                    $counts['skipped']++;
                    continue;
                }

                $taxaPayload = [
                    'taxon_identifier' => substr($taxonIdentifier, 0, 100),
                    'scientific_name_identifier' => substr($sciNameIdentifier, 0, 100),
                    'scientific_name' => substr($scientificName, 0, 200),
                    'scientific_name_authorship' => $this->nullableString($row['scientific_name_authorship'] ?? null, 100),
                    'vernacular_name' => substr($vernacularName, 0, 200),
                    'order_id' => $orderId,
                    'superfamily_id' => $superfamilyId,
                    'family_id' => $familyId,
                    'taxon_group_id' => $groupId,
                    'recording_scheme_id' => $schemeId,
                    'conservation_status' => $this->nullableString($row['conservation_status'] ?? null, 10),
                    'rarity_group_name' => 'Unassigned',
                    'blocked' => 0,
                    'blocked_reason' => null,
                    'deleted_at' => null,
                ];

                $existingTaxa = $db->table('taxa')->where('taxon_identifier', $taxonIdentifier)->get()->getRowArray();
                $taxaId = null;

                if ($existingTaxa === null) {
                    $counts['inserted']++;

                    if (! $dryRun) {
                        $db->table('taxa')->insert($taxaPayload);
                        $taxaId = (int) $db->insertID();
                    }
                } else {
                    $counts['updated']++;
                    $taxaId = (int) $existingTaxa['id'];

                    if (! $dryRun) {
                        $db->table('taxa')->where('id', $existingTaxa['id'])->update($taxaPayload);
                    }
                }

                if ($dryRun) {
                    continue;
                }

                if ($taxaId === null && $existingTaxa !== null) {
                    $taxaId = (int) $existingTaxa['id'];
                }

                if ($taxaId === null) {
                    $counts['errors']++;
                    continue;
                }

                $taxonNamePayload = [
                    'uuid' => $this->stableUuid('taxon:' . $taxonIdentifier),
                    'taxon_id' => $taxaId,
                    'name' => substr($scientificName, 0, 200),
                    'scientific_name_identifier' => substr($sciNameIdentifier, 0, 100),
                    'accepted' => 1,
                    'scientific' => 1,
                    'deleted_at' => null,
                ];

                $existingTaxonName = $db->table('taxon_names')
                    ->where('scientific_name_identifier', $sciNameIdentifier)
                    ->where('taxon_id', $taxaId)
                    ->get()
                    ->getRowArray();

                if ($existingTaxonName === null) {
                    $counts['inserted']++;
                    $db->table('taxon_names')->insert($taxonNamePayload);
                    continue;
                }

                $counts['updated']++;
                $db->table('taxon_names')->where('id', $existingTaxonName['id'])->update($taxonNamePayload);
            }

            if (! $dryRun) {
                $db->transComplete();

                if (! $db->transStatus()) {
                    throw new \RuntimeException('Taxa import transaction failed.');
                }
            }
        } catch (\Throwable $exception) {
            $counts['errors']++;

            if (! $dryRun && $db->transStatus()) {
                $db->transRollback();
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function lookupByTaxonIdentifier(string $table): array
    {
        $rows = db_connect()->table($table)
            ->select(['id', 'taxon_identifier'])
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['taxon_identifier']] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function lookupByExternalKey(string $table): array
    {
        $rows = db_connect()->table($table)
            ->select(['id', 'external_key'])
            ->where('deleted_at', null)
            ->where('external_key IS NOT NULL', null, false)
            ->get()
            ->getResultArray();

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['external_key']] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * @param mixed $value
     */
    private function nullableString($value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '') {
            return null;
        }

        return substr($string, 0, $maxLength);
    }

    private function stableUuid(string $seed): string
    {
        $hex = md5($seed);

        return sprintf(
            '%s-%s-4%s-%s%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 13, 3),
            dechex((hexdec(substr($hex, 16, 1)) & 0x3) | 0x8),
            substr($hex, 17, 3),
            substr($hex, 20, 12),
        );
    }
}
