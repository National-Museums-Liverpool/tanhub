<?php
/**
 * Shared typed staff filters supplied by TaxonSearchService::page().
 *
 * Rows are server rendered so editing and taxon selection remain usable without
 * JavaScript. The browser only enhances this same GET contract.
 *
 * @var array<string, mixed> $page Rows, field metadata, choices and validated search state.
 */
$filters = $page['filters'];
$path = $page['isTaxa'] ? 'taxa' : 'occurrences';
$rows = $page['conditionRows'];
$active = $rows !== [] || $page['errors'] !== [];
$rows = $rows === [] ? [['field' => '', 'value' => '']] : $rows;
?>
<?php if ($page['errors'] !== []): ?>
    <div class="alert alert-danger" role="alert">
        <strong>Check your search filters.</strong>
        <ul class="mb-0">
            <?php foreach ($page['errors'] as $key => $error): ?>
                <li><a href="#<?= esc($key) ?>"><?= esc($key . ': ' . $error) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<?php if ($page['editingFilters']): ?>
    <div class="alert alert-info" role="status">Filters updated. Complete the values and select Search to apply them.</div>
<?php endif; ?>
<form class="row g-3 mb-4" method="get" action="<?= esc(site_url($path)) ?>" data-filter-builder data-taxon-search="<?= esc(site_url('taxon-search')) ?>">
    <input type="hidden" name="filter_form" value="1">
    <input type="hidden" name="sort" value="<?= esc($page['sort']) ?>">
    <input type="hidden" name="direction" value="<?= esc($page['direction']) ?>">
    <div class="col-12 col-lg-6">
        <label class="form-label" for="q">Search any descriptive field</label>
        <input class="form-control" id="q" name="q" type="search" maxlength="200" value="<?= esc($filters['q']) ?>" placeholder="Search <?= esc($path) ?>...">
    </div>
    <div class="col-12 col-lg-auto d-flex align-items-lg-end gap-2">
        <button class="btn btn-brand" type="submit">Search</button>
        <a class="btn btn-outline-secondary" href="<?= esc(site_url($path)) ?>">Clear</a>
        <?php if ($page['errors'] === [] && ! $page['editingFilters']): ?>
            <a class="btn btn-outline-brand" href="<?= esc(site_url('exports/new') . '?' . http_build_query(
                $filters + ['resource' => $path, 'sort' => $page['sort'], 'direction' => $page['direction'],
                    'estimated_total' => $page['total']]
            )) ?>" title="Download the applied search; select Search first after editing.">Download CSV</a>
        <?php endif; ?>
    </div>
    <div class="col-12">
        <details id="advanced-search" <?= $active ? 'open' : '' ?>>
            <summary class="mb-2">Advanced<?= $active ? ' (filters active or being edited)' : '' ?></summary>
            <div class="border rounded-3 bg-body-tertiary p-3">
                <p class="form-text mt-0">All rows must match (AND). Multiple choices within one row match any selected value.</p>
                <div class="mb-3">
                    <label class="form-label" for="stats_scope">Statistics scope</label>
                    <select class="form-select" id="stats_scope" name="stats_scope" aria-describedby="statistics-help">
                        <option value="global" <?= $page['statisticsScope'] === 'global' ? 'selected' : '' ?>>Global</option>
                        <?php foreach ($page['regions'] as $region): ?>
                            <option value="<?= esc((string) $region['id']) ?>" <?= $page['statisticsScope'] === (string) $region['id'] ? 'selected' : '' ?>><?= esc($region['higher_geography']) ?></option>
                        <?php endforeach; ?>
                        <?php if (isset($page['errors']['stats_scope'])): ?>
                            <option selected value="<?= esc($page['statisticsScope']) ?>">Unavailable selection</option>
                        <?php endif; ?>
                    </select>
                    <div class="form-text" id="statistics-help">
                        Stored statistics describe the exact accepted taxon in this scope and exclude blocked evidence.
                        Occurrence year, region and source filters do not recalculate them. Scope alone does not filter rows.
                        Missing statistics are not zero; decision-only rows can have zero counts.
                        Occupancy score is a rarity-group percentile; occupancy percentage measures mapped area.
                        Occupancy and curated rarity status apply to species, without inheritance.
                        <?= esc($page['statisticsHelp']) ?>
                    </div>
                </div>
                <div data-filter-rows id="conditions">
                    <?php foreach ($rows as $index => $row): ?>
                        <?= view('search/condition-row', ['page' => $page, 'index' => $index, 'row' => $row]) ?>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-brand" type="submit" name="filter_action" value="add" data-add-filter formnovalidate>Add condition</button>
                    <noscript>
                        <button class="btn btn-outline-secondary" type="submit" name="filter_action" value="refresh" formnovalidate>Update fields</button>
                    </noscript>
                </div>
                <noscript>
                    <div class="form-text">Choose a field then Update fields to load its controls.
                        For Taxon (including descendants), enter a name and Update fields to load choices,
                        then select one. Select Search to apply your filters.</div>
                </noscript>
                <div class="form-text">Year accepts 2010, 2010-2015, 2010- or -2015; complete record intervals must fit.
                    Blocked data is included unless explicitly excluded. Up to 50 conditions.</div>
                <div class="form-text" data-filter-status role="status" aria-live="polite"></div>
            </div>
        </details>
    </div>
    <script type="application/json" data-filter-schema><?= json_encode(
        ['fields' => $page['searchFields'], 'choices' => $page['fieldChoices']],
        JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?></script>
    <template data-filter-template><?= view('search/condition-row', [
        'page' => $page, 'index' => '__INDEX__', 'row' => ['field' => '', 'value' => ''],
    ]) ?></template>
    <input type="hidden" name="filter_complete" value="1">
</form>
