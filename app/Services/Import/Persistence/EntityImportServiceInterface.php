<?php

namespace App\Services\Import\Persistence;

/**
 * Contract for import entity persistence services.
 *
 * Implementors persist a batch of normalized rows for a single lookup/taxonomy
 * entity (e.g. recording schemes, taxon groups, taxa) into its local table,
 * upserting by whatever natural/external key identifies the entity. Each
 * implementation must:
 * - Determine an upsert key from the row (typically an `external_key` or
 *   identifier field) and skip rows where that key (or other required fields)
 *   is missing, incrementing both `skipped` and `processed` for each skip.
 * - Insert new rows and update existing rows matched by that key, incrementing
 *   `inserted` or `updated` accordingly.
 * - Never throw for a single bad row; catch and log per-row failures, count
 *   them in `errors`, and stop processing remaining rows (fail-fast per batch).
 * - Honour `$dryRun` by computing the same counts without writing to the
 *   database.
 */
interface EntityImportServiceInterface
{
    /**
     * Persist a batch of normalized rows for this entity.
     *
     * @param array<int, array<string, mixed>> $rows   Normalized source rows to
     *                                                  upsert; expected keys vary
     *                                                  by entity but always
     *                                                  include enough fields to
     *                                                  resolve the upsert key.
     * @param bool                             $dryRun When true, compute counts
     *                                                  without writing changes.
     *
     * @return array<string, int> Result counts. Must include at least
     *                            `fetched`, `processed`, `inserted`, `updated`,
     *                            `skipped`, and `errors`.
     */
    public function import(array $rows, bool $dryRun = false): array;
}