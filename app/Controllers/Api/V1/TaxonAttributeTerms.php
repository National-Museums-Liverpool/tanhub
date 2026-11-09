<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\RawSql;

/**
 * Discover retained, attribute-scoped terms without leaking blocked or deleted taxa.
 */
class TaxonAttributeTerms extends ApiResourceController
{
    /**
     * Expose public term identity together with its owning attribute.
     *
     * @param array<string, bool> $includes Unused expansions.
     * @return array<string, string> Response and predicate fields.
     */
    protected function getAllowedFields(array $includes = []): array
    {
        return ['attribute_key' => 'a.attribute_key', 'key' => 'atr.term_key', 'label' => 'atr.label'];
    }

    /**
     * Query only terms assigned to publicly visible exact taxa.
     *
     * @param object $db Active database connection.
     * @param array<string, bool> $includes Unused expansions.
     * @return BaseBuilder Deduplicated term dictionary query.
     */
    protected function getBuilder(object $db, array $includes = []): BaseBuilder
    {
        $keys = array_keys(config(\Config\TaxonAttributes::class)->definitions());
        $values = $db->escapeIdentifiers($db->prefixTable('taxon_attribute_values'));
        $taxa = $db->escapeIdentifiers($db->prefixTable('taxa'));

        return $db->table('taxon_attribute_terms atr')->select($this->getFieldSql($includes), false)
            ->join('taxon_attributes a', 'a.id=atr.attribute_id')
            ->whereIn('a.attribute_key', $keys === [] ? [''] : $keys)
            ->where(new RawSql('EXISTS (SELECT 1 FROM ' . $values . ' av JOIN ' . $taxa
                . ' public_taxon ON public_taxon.id=av.taxon_id AND public_taxon.deleted_at IS NULL'
                . ' AND public_taxon.blocked=0 WHERE av.term_id=atr.id)'));
    }

    /**
     * Identify terms within the attribute scope supplied by callers.
     *
     * @return string Trusted term key column.
     */
    protected function getDefaultKeyColumn(): string
    {
        return 'atr.term_key';
    }

    /**
     * Order term discovery using native text collation.
     *
     * @return string Public sortable field.
     */
    protected function getDefaultSortColumn(): string
    {
        return 'label';
    }

    /**
     * Keep equal-label discovery pages stable across distinct attributes and term identities.
     *
     * @param BaseBuilder $builder Term discovery query.
     * @param list<array{column: string, direction: string}> $sorts Validated sort descriptors.
     */
    protected function applySorts(BaseBuilder $builder, array $sorts): void
    {
        parent::applySorts($builder, $sorts);
        $builder->orderBy('a.attribute_key', 'ASC', false)->orderBy('atr.term_key', 'ASC', false);
    }
}
