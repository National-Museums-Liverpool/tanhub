<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Presentation settings for staff taxon and occurrence searches.
 *
 * These settings only select existing filters/columns; they do not add rank columns
 * or change import scope. {@see \App\Services\Search\TaxonSearchService} validates them.
 */
class TaxonSearch extends BaseConfig
{
    /**
     * Ordered reporting ranks shown as classification filters and columns.
     *
     * Accepts a list or an environment CSV value such as Family,Genus. Empty hides
     * classification controls. Family is optional when using the default; other
     * unavailable settings fail explicitly rather than silently widening the search.
     *
     * @var list<string>|string
     */
    public array|string $classificationRanks = ['Family'];
}
