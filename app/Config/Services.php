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
    public static function occurrenceImportSourceAdapterFactory(bool $getShared = true): \App\Services\Import\Adapter\OccurrenceSourceAdapterFactory
    {
        if ($getShared) {
            return static::getSharedInstance('occurrenceImportSourceAdapterFactory');
        }

        return new \App\Services\Import\Adapter\OccurrenceSourceAdapterFactory(new \Config\Import());
    }

    /**
     * Occurrence import orchestrator service.
     */
    public static function occurrenceImportOrchestrator(bool $getShared = true): \App\Services\Import\ImportOrchestrator
    {
        if ($getShared) {
            return static::getSharedInstance('occurrenceImportOrchestrator');
        }

        return new \App\Services\Import\ImportOrchestrator(
            new \Config\Import(),
            static::occurrenceImportSourceAdapterFactory(false),
            new \App\Services\Import\Persistence\OccurrenceImportService(),
            model(\App\Models\ImportRunModel::class),
            model(\App\Models\DataSourceModel::class),
            model(\App\Models\ImportOffsetModel::class),
        );
    }

    /**
     * Taxonomy import adapter factory service.
     */
    public static function importSourceAdapterFactory(bool $getShared = true): \App\Services\Import\Adapter\ImportSourceAdapterFactory
    {
        if ($getShared) {
            return static::getSharedInstance('importSourceAdapterFactory');
        }

        return new \App\Services\Import\Adapter\ImportSourceAdapterFactory(new \Config\Import());
    }

    /**
     * Taxonomy import orchestrator service.
     */
    public static function importOrchestrator(bool $getShared = true): \App\Services\Import\EntityImportOrchestrator
    {
        if ($getShared) {
            return static::getSharedInstance('importOrchestrator');
        }

        return new \App\Services\Import\EntityImportOrchestrator(
            new \Config\Import(),
            static::importSourceAdapterFactory(false),
            new \App\Services\Import\Persistence\EntityImportService(),
            model(\App\Models\ImportRunModel::class),
            model(\App\Models\DataSourceModel::class),
            model(\App\Models\ImportOffsetModel::class),
        );
    }
}
