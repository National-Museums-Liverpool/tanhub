<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;

/**
 * Discover configured attribute definitions using stable deployment keys.
 */
class TaxonAttributes extends ApiResourceController
{
    /**
     * Expose definition metadata, excluding local primary keys.
     *
     * @param array<string, bool> $includes Unused expansion map.
     * @return array<string, string> Response and predicate fields.
     */
    protected function getAllowedFields(array $includes = []): array
    {
        return ['key' => 'a.attribute_key', 'label' => 'a.label', 'type' => 'a.data_type',
            'multi_value' => 'a.multi_value', 'source_id' => 'a.source_id', 'termlist_id' => 'a.termlist_id'];
    }

    /**
     * Limit discovery to attributes enabled on this installation.
     *
     * @param object $db Active database connection.
     * @param array<string, bool> $includes Unused expansion map.
     * @return BaseBuilder Definition query.
     */
    protected function getBuilder(object $db, array $includes = []): BaseBuilder
    {
        $keys = array_keys(config(\Config\TaxonAttributes::class)->definitions());

        return $db->table('taxon_attributes a')->select($this->getFieldSql($includes), false)
            ->whereIn('a.attribute_key', $keys === [] ? [''] : $keys);
    }

    /**
     * Use the stable configuration key for item lookup.
     *
     * @return string Trusted natural-key column.
     */
    protected function getDefaultKeyColumn(): string
    {
        return 'a.attribute_key';
    }

    /**
     * Order discovery by its display label.
     *
     * @return string Public sortable field.
     */
    protected function getDefaultSortColumn(): string
    {
        return 'label';
    }

    /**
     * Keep equally labelled definitions in repeatable discovery pages.
     *
     * @param BaseBuilder $builder Definition discovery query.
     * @param list<array{column: string, direction: string}> $sorts Validated sort descriptors.
     */
    protected function applySorts(BaseBuilder $builder, array $sorts): void
    {
        parent::applySorts($builder, $sorts);
        $builder->orderBy('a.attribute_key', 'ASC', false);
    }

    /**
     * Preserve JSON boolean semantics across database drivers.
     *
     * @param list<array<string, mixed>> $data Definition rows.
     * @param array<string, bool> $includes Unused expansions.
     */
    protected function augmentResponseData(array &$data, array $includes = []): void
    {
        foreach ($data as &$row) {
            $row['multi_value'] = (bool) $row['multi_value'];
            $row['source_id'] = (int) $row['source_id'];
            $row['termlist_id'] = $row['termlist_id'] === null ? null : (int) $row['termlist_id'];
        }
    }
}
