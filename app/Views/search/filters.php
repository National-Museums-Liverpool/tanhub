<?php
/**
 * Shared staff filters supplied by TaxonSearchService::page().
 *
 * The selected ID is submitted separately from its editable name. The server-rendered
 * selection list remains usable without JavaScript and is enhanced by taxon-search.js.
 *
 * @var array<string, mixed> $page Search state, validation errors and reference options.
 */
$filters = $page['filters'];
$taxa = $page['isTaxa'];
$path = $taxa ? 'taxa' : 'occurrences';
$options = [
    'geographic_region_id' => ['label' => 'Geographic region', 'rows' => $page['regions'], 'field' => 'higher_geography'],
    'taxon_group_id' => ['label' => 'Taxon group', 'rows' => $page['groups'], 'field' => 'title'],
];
foreach ($page['classifications'] as $alias => $rank) {
    $options[$alias . '_id'] = ['label' => $rank['rank'], 'rows' => $page['classificationOptions'][$alias], 'field' => 'scientific_name'];
}
if ($taxa) {
    $options['rank_id'] = ['label' => 'Rank', 'rows' => $page['ranks'], 'field' => 'rank'];
}
$selected = $page['selectedTaxon'];
$taxonLabel = $selected === null ? $filters['taxon_name']
    : $selected['scientific_name'] . (empty($selected['vernacular_name']) ? '' : ' - ' . $selected['vernacular_name']);
// Expose active/invalid controls so a collapsed panel cannot conceal restrictive criteria.
$advancedActive = false;
foreach ($filters as $key => $value) {
    if ($key !== 'q') {
        $advancedActive = $advancedActive || $value !== '' || isset($page['errors'][$key]);
    }
}
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
<form class="row g-3 mb-4" method="get" action="<?= esc(site_url($path)) ?>" data-taxon-search="<?= esc(site_url('taxon-search')) ?>">
    <input type="hidden" name="sort" value="<?= esc($page['sort']) ?>">
    <input type="hidden" name="direction" value="<?= esc($page['direction']) ?>">
    <div class="col-12 col-lg-6">
        <label class="form-label" for="q">Search any descriptive field</label>
        <input class="form-control <?= isset($page['errors']['q']) ? 'is-invalid' : '' ?>" id="q" name="q" type="search" maxlength="200" value="<?= esc($filters['q']) ?>" placeholder="Search <?= esc($path) ?>...">
        <?php if (isset($page['errors']['q'])): ?><div class="invalid-feedback"><?= esc($page['errors']['q']) ?></div><?php endif; ?>
    </div>
    <div class="col-12 col-lg-auto d-flex align-items-lg-end gap-2">
        <button class="btn btn-brand" type="submit">Search</button>
        <a class="btn btn-outline-secondary" href="<?= esc(site_url($path)) ?>">Clear</a>
    </div>
    <div class="col-12">
        <details id="advanced-search" <?= $advancedActive ? 'open' : '' ?>>
            <summary class="mb-2">Advanced<?= $advancedActive ? ' (filters active)' : '' ?></summary>
            <div class="row g-3 border rounded-3 bg-body-tertiary p-3 mx-0 mt-0">
                <div class="col-12 col-md-6 col-lg-3">
                    <label class="form-label" for="year">Year</label>
                    <input class="form-control <?= isset($page['errors']['year']) ? 'is-invalid' : '' ?>" id="year" name="year" value="<?= esc($filters['year']) ?>" placeholder="2010 or 2010-2015" aria-describedby="year-help">
                    <div class="form-text" id="year-help">Single year, range, 2010- or -2015. Record dates must be fully contained.</div>
                    <?php if (isset($page['errors']['year'])): ?><div class="invalid-feedback"><?= esc($page['errors']['year']) ?></div><?php endif; ?>
                </div>
                <?php foreach ($options as $key => $option): ?>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label" for="<?= esc($key) ?>"><?= esc($option['label']) ?></label>
                        <select class="form-select <?= isset($page['errors'][$key]) ? 'is-invalid' : '' ?>" id="<?= esc($key) ?>" name="<?= esc($key) ?>">
                            <option value="">All</option>
                            <?php foreach ($option['rows'] as $row): ?>
                                <?php $label = $key === 'taxon_group_id' && ! empty($row['friendly']) ? $row['friendly'] : $row[$option['field']]; ?>
                                <option value="<?= esc((string) $row['id']) ?>" <?= $filters[$key] === (string) $row['id'] ? 'selected' : '' ?>><?= esc($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($page['errors'][$key])): ?><div class="invalid-feedback"><?= esc($page['errors'][$key]) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="col-12 col-lg-6 position-relative">
                    <label class="form-label" for="taxon_name">Taxon (including descendants)</label>
                    <input class="form-control <?= isset($page['errors']['taxon_name']) ? 'is-invalid' : '' ?>" id="taxon_name" name="taxon_name" type="search" maxlength="200" autocomplete="off" value="<?= esc($taxonLabel) ?>" aria-describedby="taxon-help taxon-status">
                    <div class="form-text" id="taxon-help">Search scientific, vernacular or synonymous names and select a taxon.</div>
                    <div id="taxon-status" class="form-text" role="status" aria-live="polite"></div>
                    <ul class="list-group position-absolute start-0 end-0 mx-3 shadow z-3 overflow-auto" id="taxon-suggestions" style="max-height: 20rem" hidden></ul>
                    <?php if (isset($page['errors']['taxon_name'])): ?><div class="invalid-feedback"><?= esc($page['errors']['taxon_name']) ?></div><?php endif; ?>
                    <div data-taxon-fallback>
                        <label class="form-label mt-2" for="taxon_id">Selected taxon</label>
                        <select class="form-select <?= isset($page['errors']['taxon_id']) ? 'is-invalid' : '' ?>" id="taxon_id" name="taxon_id">
                            <option value="">No selection</option>
                            <?php if ($selected !== null): ?>
                                <option value="<?= esc((string) $selected['id']) ?>" selected><?= esc($taxonLabel) ?></option>
                            <?php endif; ?>
                            <?php foreach ($page['taxonMatches'] as $match): ?>
                                <option value="<?= esc((string) $match['id']) ?>"><?= esc($match['scientific_name'] . ' - ' . $match['vernacular_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Without JavaScript: enter a name and Search to load up to 20 choices; select one and Search again to include descendants.</div>
                    </div>
                    <?php if (isset($page['errors']['taxon_id'])): ?><div class="text-danger small"><?= esc($page['errors']['taxon_id']) ?></div><?php endif; ?>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="data_source_id">Data source<?= $taxa ? ' (matching records)' : '' ?></label>
                    <select class="form-select <?= isset($page['errors']['data_source_id']) ? 'is-invalid' : '' ?>" id="data_source_id" name="data_source_id">
                        <option value="">All</option>
                        <?php foreach ($page['dataSources'] as $source): ?>
                            <option value="<?= esc((string) $source['id']) ?>" <?= $filters['data_source_id'] === (string) $source['id'] ? 'selected' : '' ?>><?= esc($source['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($page['errors']['data_source_id'])): ?><div class="invalid-feedback"><?= esc($page['errors']['data_source_id']) ?></div><?php endif; ?>
                </div>
                <?php foreach (['taxon_blocked' => 'Taxon blocked', 'blocked' => 'Occurrence blocked'] as $key => $label): ?>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="<?= esc($key) ?>"><?= esc($label) ?></label>
                        <select class="form-select <?= isset($page['errors'][$key]) ? 'is-invalid' : '' ?>" id="<?= esc($key) ?>" name="<?= esc($key) ?>">
                            <?php foreach (['' => 'All', '0' => 'No', '1' => 'Yes'] as $value => $text): ?>
                                <option value="<?= esc((string) $value) ?>" <?= $filters[$key] === (string) $value ? 'selected' : '' ?>><?= esc($text) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($page['errors'][$key])): ?><div class="invalid-feedback"><?= esc($page['errors'][$key]) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php foreach (['from_date' => 'From date', 'to_date' => 'To date'] as $key => $label): ?>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="<?= esc($key) ?>"><?= esc($label) ?></label>
                        <input class="form-control <?= isset($page['errors'][$key]) ? 'is-invalid' : '' ?>" type="date" id="<?= esc($key) ?>" name="<?= esc($key) ?>" value="<?= esc($filters[$key]) ?>">
                        <?php if (isset($page['errors'][$key])): ?><div class="invalid-feedback"><?= esc($page['errors'][$key]) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="col-12 form-text">Year and precise date filters both apply. Blocked data is included unless excluded above.</div>
            </div>
        </details>
    </div>
</form>
