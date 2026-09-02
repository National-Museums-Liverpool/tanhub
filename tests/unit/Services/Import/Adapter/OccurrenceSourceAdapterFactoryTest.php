<?php

namespace Tests;

use App\Services\Import\Adapter\IndiciaOccurrencesAdapter;
use App\Services\Import\Adapter\NbnAtlasOccurrencesAdapter;
use App\Services\Import\Adapter\OccurrenceSourceAdapterFactory;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Import as ImportConfig;
use InvalidArgumentException;

/**
 * @internal
 */
final class OccurrenceSourceAdapterFactoryTest extends CIUnitTestCase
{
    /**
     * Verify NBN source keys are case-insensitive and produce an NBN adapter.
     */
    public function testMakeCreatesNbnAdapterWithResolvedConfiguration(): void
    {
        $config = new ImportConfig();
        $config->taxonRanks = ['Species'];
        $config->geographicRegions = ['Cheshire'];
        $config->nbnMinTaxonRankId = 123;
        $config->nbnApiFilterQuery = 'kingdom:Animalia';

        $adapter = (new OccurrenceSourceAdapterFactory($config))->make('NBN');

        $this->assertInstanceOf(NbnAtlasOccurrencesAdapter::class, $adapter);
    }

    /**
     * Verify Indicia source keys are case-insensitive and produce an Indicia adapter.
     */
    public function testMakeCreatesIndiciaAdapter(): void
    {
        $adapter = (new OccurrenceSourceAdapterFactory(new ImportConfig()))->make('INDICIA');

        $this->assertInstanceOf(IndiciaOccurrencesAdapter::class, $adapter);
    }

    /**
     * Verify configured source abbreviations are returned case-insensitively.
     */
    public function testSourceAbbrReturnsConfiguredAbbreviation(): void
    {
        $config = new ImportConfig();
        $config->occurrenceSources['nbn']['abbr'] = 'NBN-CUSTOM';

        $abbr = (new OccurrenceSourceAdapterFactory($config))->sourceAbbr('NBN');

        $this->assertSame('NBN-CUSTOM', $abbr);
    }

    /**
     * Verify unknown and malformed occurrence sources are rejected.
     */
    public function testUnknownOrMalformedSourceIsRejected(): void
    {
        $config = new ImportConfig();
        $factory = new OccurrenceSourceAdapterFactory($config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown occurrence source: missing');
        $factory->make('missing');
    }

    /**
     * Verify a source without an abbreviation cannot be resolved.
     */
    public function testSourceAbbrRejectsMissingAbbreviation(): void
    {
        $config = new ImportConfig();
        $config->occurrenceSources['nbn']['abbr'] = null;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source abbreviation is not configured for: nbn');
        (new OccurrenceSourceAdapterFactory($config))->sourceAbbr('nbn');
    }
}