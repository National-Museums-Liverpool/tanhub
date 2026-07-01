<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /**
     * Occurrence import adapter factory service.
     */
    public static function occurrenceSourceAdapterFactory(bool $getShared = true): \App\Services\Import\Adapter\OccurrenceSourceAdapterFactory
    {
        if ($getShared) {
            return static::getSharedInstance('occurrenceSourceAdapterFactory');
        }

        return new \App\Services\Import\Adapter\OccurrenceSourceAdapterFactory(config(\Config\Import::class));
    }

    /**
     * Occurrence import orchestrator service.
     */
    public static function importOrchestrator(bool $getShared = true): \App\Services\Import\ImportOrchestrator
    {
        if ($getShared) {
            return static::getSharedInstance('importOrchestrator');
        }

        return new \App\Services\Import\ImportOrchestrator(
            config(\Config\Import::class),
            static::occurrenceSourceAdapterFactory(false),
            new \App\Services\Import\Persistence\OccurrenceImportService(),
            model(\App\Models\ImportRunModel::class),
            model(\App\Models\DataSourceModel::class),
        );
    }

    /**
     * Taxonomy import adapter factory service.
     */
    public static function taxonomySourceAdapterFactory(bool $getShared = true): \App\Services\Import\Adapter\TaxonomySourceAdapterFactory
    {
        if ($getShared) {
            return static::getSharedInstance('taxonomySourceAdapterFactory');
        }

        return new \App\Services\Import\Adapter\TaxonomySourceAdapterFactory(config(\Config\Import::class));
    }

    /**
     * Taxonomy import orchestrator service.
     */
    public static function taxonomyImportOrchestrator(bool $getShared = true): \App\Services\Import\TaxonomyImportOrchestrator
    {
        if ($getShared) {
            return static::getSharedInstance('taxonomyImportOrchestrator');
        }

        return new \App\Services\Import\TaxonomyImportOrchestrator(
            config(\Config\Import::class),
            static::taxonomySourceAdapterFactory(false),
            new \App\Services\Import\Persistence\TaxonomyImportService(),
            model(\App\Models\ImportRunModel::class),
            model(\App\Models\DataSourceModel::class),
            model(\App\Models\ImportOffsetModel::class),
        );
    }
}
