<?php
/**
 * One editable AND condition, including invalid values for explicit correction.
 *
 * @var array<string, mixed> $page Catalogue, choices, row errors and taxon matches.
 * @var int|string $index Row position or template placeholder.
 * @var array<string, mixed> $row Submitted condition; values are untrusted.
 */
$key = is_string($row['field'] ?? null) ? $row['field'] : '';
$field = $page['searchFields'][$key] ?? null;
$operators = $field['operators'] ?? ['' => 'Choose a field'];
$operator = is_string($row['operator'] ?? null) ? $row['operator'] : '';
if ($operator !== '' && ! isset($operators[$operator]) && ! $page['editingFilters']) {
    $operators[$operator] = 'Unavailable: ' . $operator;
}
$operator = isset($operators[$operator]) ? $operator : array_key_first($operators);
$type = $field['type'] ?? 'text';
$value = $row['value'] ?? '';
$scalar = is_scalar($value) ? (string) $value : '';
if ($type === 'textlist' && is_array($value)) {
    $scalar = implode(', ', array_filter($value, 'is_scalar'));
}
$selected = is_array($value) ? array_filter($value, 'is_scalar') : [$scalar];
$selected = array_map('strval', $selected);
$choices = $type === 'flag' ? ['1' => 'Yes', '0' => 'No'] : ($page['fieldChoices'][$key] ?? []);
foreach ($page['rowMatches'][$index] ?? [] as $match) {
    $choices[$match['id']] = $match['scientific_name']
        . (empty($match['vernacular_name']) ? '' : ' - ' . $match['vernacular_name']);
}
$empty = in_array($operator, ['empty', 'not_empty'], true);
$error = $page['errors']['condition_' . $index] ?? null;
?>
<div class="row g-2 align-items-start border-bottom pb-3 mb-3" data-condition-row id="condition_<?= esc((string) $index) ?>">
    <div class="col-12 col-md-4">
        <label class="form-label" for="field_<?= esc((string) $index) ?>">Field</label>
        <select class="form-select" id="field_<?= esc((string) $index) ?>" name="conditions[<?= esc((string) $index) ?>][field]" data-condition-field>
            <option value="">Choose a field</option>
            <?php foreach (['taxon' => 'Taxon', 'classification' => 'Classification', 'attribute' => 'Attributes', 'occurrence' => 'Occurrence', 'statistics' => 'Statistics'] as $domain => $label): ?>
                <optgroup label="<?= esc($label) ?>">
                    <?php foreach ($page['searchFields'] as $availableKey => $availableField): ?>
                        <?php if ($availableField['domain'] === $domain): ?>
                            <option value="<?= esc($availableKey) ?>" <?= $key === $availableKey ? 'selected' : '' ?>><?= esc($availableField['label']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
            <?php if ($key !== '' && $field === null): ?>
                <option selected value="<?= esc($key) ?>">Unavailable: <?= esc($key) ?></option>
            <?php endif; ?>
        </select>
        <div class="form-text" data-condition-help <?= empty($field['help']) ? 'hidden' : '' ?>><?= esc($field['help'] ?? '') ?></div>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label" for="operator_<?= esc((string) $index) ?>">Operator</label>
        <select class="form-select" id="operator_<?= esc((string) $index) ?>" name="conditions[<?= esc((string) $index) ?>][operator]" data-condition-operator>
            <?php foreach ($operators as $code => $label): ?>
                <option value="<?= esc($code) ?>" <?= $operator === $code ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-4" data-condition-value>
        <?php if (! $empty): ?>
            <label class="form-label" for="value_<?= esc((string) $index) ?>">Value</label>
            <?php if ($type === 'taxon'): ?>
                <div data-taxon-row>
                    <input class="form-control mb-2" type="search" maxlength="200" autocomplete="off" name="conditions[<?= esc((string) $index) ?>][text]" value="<?= esc(is_scalar($row['text'] ?? '') ? (string) ($row['text'] ?? '') : '') ?>" aria-label="Taxon name" placeholder="Type a name to find taxa..." data-taxon-input>
                    <ul class="list-group overflow-auto" style="max-height: 20rem" data-taxon-suggestions hidden></ul>
                    <div class="form-text" role="status" aria-live="polite" data-taxon-status></div>
            <?php endif; ?>
            <?php if (in_array($type, ['choice', 'tokens', 'reference', 'flag', 'taxon'], true)): ?>
                <select class="form-select" id="value_<?= esc((string) $index) ?>" name="conditions[<?= esc((string) $index) ?>][value]<?= $operator === 'in' ? '[]' : '' ?>" <?= $operator === 'in' ? 'multiple' : '' ?> <?= $type === 'taxon' ? 'data-taxon-selection' : '' ?>>
                    <?php if ($operator !== 'in'): ?><option value="">Choose a value</option><?php endif; ?>
                    <?php foreach ($choices as $choice => $label): ?>
                        <option value="<?= esc((string) $choice) ?>" <?= in_array((string) $choice, $selected, true) ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach; ?>
                    <?php foreach ($selected as $part): ?>
                        <?php if ($part !== '' && ! array_key_exists($part, $choices)): ?>
                            <option value="<?= esc($part) ?>" selected>Unavailable: <?= esc($part) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input class="form-control" id="value_<?= esc((string) $index) ?>" name="conditions[<?= esc((string) $index) ?>][value]" value="<?= esc($scalar) ?>" type="<?= in_array($type, ['integer', 'decimal'], true) ? 'number' : ($type === 'date' ? 'date' : 'text') ?>" <?= $type === 'integer' ? 'step="1"' : ($type === 'decimal' ? 'step="0.0001"' : 'maxlength="200"') ?> <?= isset($field['min']) ? 'min="' . esc((string) $field['min']) . '"' : '' ?> <?= isset($field['max']) ? 'max="' . esc((string) $field['max']) . '"' : '' ?>>
                <?php if ($operator === 'between'): ?>
                    <label class="form-label mt-2" for="upper_<?= esc((string) $index) ?>">Maximum</label>
                    <input class="form-control" id="upper_<?= esc((string) $index) ?>" name="conditions[<?= esc((string) $index) ?>][upper]" value="<?= esc(is_scalar($row['upper'] ?? '') ? (string) ($row['upper'] ?? '') : '') ?>" type="<?= $type === 'date' ? 'date' : 'number' ?>" step="<?= $type === 'decimal' ? '0.0001' : '1' ?>">
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($type === 'taxon'): ?></div><?php endif; ?>
            <?php if ($operator === 'in'): ?><div class="form-text">Match any selected value<?= $type === 'tokens' ? ' (whole abbreviation)' : '' ?>.</div><?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="col-12 col-md-auto">
        <button class="btn btn-outline-danger mt-md-4" type="submit" name="filter_action" value="remove:<?= esc((string) $index) ?>" data-remove-filter formnovalidate aria-label="Remove condition <?= esc((string) $index) ?>">Remove</button>
    </div>
    <?php if ($error !== null): ?><div class="col-12 text-danger" role="alert"><?= esc($error) ?></div><?php endif; ?>
</div>
