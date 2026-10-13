<?php
/**
 * Shared occurrence/taxa table supplied by TaxonSearchService::page().
 *
 * Column keys match the service's sort allowlist; only the multivalued regions column
 * is deliberately unsortable. Display labels are hydrated separately from stored values.
 *
 * @var array<string, mixed> $page Search rows, columns, filter state and pagination.
 */
$taxa = $page['isTaxa'];
$path = $taxa ? 'taxa' : 'occurrences';
$title = $taxa ? 'Taxa' : 'Occurrences';
$filters = $page['filters'];
$columns = $page['columnLabels'];
$rows = $page[$path];
?>
<section class="page-section">
    <div class="auth-card p-4 p-lg-5">
        <div class="mb-4">
            <span class="eyebrow"><?= $taxa ? 'Taxonomy' : 'Occurrences' ?></span>
            <h1 class="section-heading mb-0"><?= esc($title) ?></h1>
        </div>

        <?= $this->include('search/filters') ?>

        <p class="text-muted"><?= esc((string) $page['total']) ?> <?= $taxa ? 'taxa' : 'occurrences' ?> found.</p>
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                <tr>
                    <?php foreach ($columns as $key => $label): ?>
                        <th scope="col">
                            <?php if ($key === 'regions'): ?>
                                <?= esc($label) ?>
                            <?php else: ?>
                                <?php
                                $nextDirection = $page['sort'] === $key && $page['direction'] === 'asc' ? 'desc' : 'asc';
                                $url = site_url($path) . '?' . http_build_query(array_merge($filters, [
                                    'sort' => $key, 'direction' => $nextDirection,
                                ]));
                                $indicator = $page['sort'] === $key
                                    ? ($page['direction'] === 'asc' ? ' (ascending)' : ' (descending)') : '';
                                ?>
                                <a href="<?= esc($url) ?>"><?= esc($label . $indicator) ?></a>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                    <th scope="col">Links</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="<?= count($columns) + 1 ?>" class="text-muted">
                            <?= $page['errors'] === [] ? 'No ' . esc(strtolower($title)) . ' found.' : 'Correct the search errors to see results.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $key => $label): ?>
                                <?php
                                $value = match ($key) {
                                    'from_date' => $row['date_label'],
                                    'taxon_group_title' => $row['taxon_group_label'],
                                    'regions' => implode('; ', $row['regions']),
                                    'blocked' => ! empty($row['blocked']) ? 'Yes' : 'No',
                                    default => (string) ($row[$key] ?? ''),
                                };
                                ?>
                                <td><?= esc($value) ?></td>
                            <?php endforeach; ?>
                            <td><a class="btn btn-sm btn-outline-brand" href="<?= esc(site_url($path . '/' . $row['id'])) ?>">Details</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            <?= $page['pager']->only(array_merge(array_keys($filters), ['sort', 'direction']))->links() ?>
        </div>
    </div>
</section>
