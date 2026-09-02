<?php

namespace Tests;

use App\Services\Import\Adapter\ImportSourceAdapterFactory;
use App\Services\Import\Adapter\IndiciaImportAdapter;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Import as ImportConfig;
use InvalidArgumentException;

/**
 * @internal
 */
final class ImportSourceAdapterFactoryTest extends CIUnitTestCase
{
    /**
     * Verify Indicia source keys are case-insensitive and produce an adapter.
     */
    public function testMakeCreatesIndiciaAdapter(): void
    {
        $adapter = (new ImportSourceAdapterFactory(new ImportConfig()))->make('INDICIA');

        $this->assertInstanceOf(IndiciaImportAdapter::class, $adapter);
    }

    /**
     * Verify configured taxonomy source abbreviations are returned.
     */
    public function testSourceAbbrReturnsConfiguredAbbreviation(): void
    {
        $config = new ImportConfig();
        $config->taxonomySources['indicia']['abbr'] = 'TAXONOMY';

        $abbr = (new ImportSourceAdapterFactory($config))->sourceAbbr('INDICIA');

        $this->assertSame('TAXONOMY', $abbr);
    }

    /**
     * Verify unknown taxonomy sources are rejected before adapter creation.
     */
    public function testMakeRejectsUnknownSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown import source: missing');

        (new ImportSourceAdapterFactory(new ImportConfig()))->make('missing');
    }

    /**
     * Verify a source without an abbreviation cannot be resolved.
     */
    public function testSourceAbbrRejectsMissingAbbreviation(): void
    {
        $config = new ImportConfig();
        $config->taxonomySources['indicia']['abbr'] = null;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Import source abbreviation is not configured for: indicia');
        (new ImportSourceAdapterFactory($config))->sourceAbbr('indicia');
    }
}