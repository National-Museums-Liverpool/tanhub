<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Import;
use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class ImportConfigTest extends CIUnitTestCase
{
    /**
     * Preserve source-specific punctuation and normalize surrounding whitespace only.
     */
    public function testLoadsGeographyGroupsWithOptionalNbnNames(): void
    {
        $this->setGeographyEnv(json_encode([
            'lrc' => [
                'indiciaLocationType' => ' LRC Boundary ',
                'nbnField' => 'cl1012',
                'locations' => [['irecordName' => ' RECORD ', 'nbnName' => ' RECORD. ']],
            ],
        ], JSON_THROW_ON_ERROR));

        $group = (new Import())->geographicRegions['lrc'];

        $this->assertSame('LRC Boundary', $group['indiciaLocationType']);
        $this->assertSame(['irecordName' => 'RECORD', 'nbnName' => 'RECORD.'], $group['locations'][0]);
    }

    /**
     * Reject malformed or ambiguous installation scope instead of importing everything.
     *
     * @param string $json Invalid configuration.
     */
    #[DataProvider('invalidGeography')]
    public function testRejectsInvalidGeography(string $json): void
    {
        $this->setGeographyEnv($json);
        $this->expectException(RuntimeException::class);
        new Import();
    }

    /**
     * Supply invalid JSON, legacy scope and invalid structured contracts.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidGeography(): iterable
    {
        yield 'csv' => ['Cheshire,South Lancashire'];
        yield 'malformed' => ['{'];
        yield 'empty' => ['{}'];
        yield 'list' => ['["Cheshire"]'];
        yield 'unsafe key' => ['{"a:b":{"indiciaLocationType":"VC","nbnField":"cl254","locations":[{"irecordName":"X"}]}}'];
        yield 'key exceeds progress column' => ['{"' . str_repeat('a', 30) . '":{"indiciaLocationType":"VC","nbnField":"cl254","locations":[{"irecordName":"X"}]}}'];
        yield 'invalid field' => ['{"vc":{"indiciaLocationType":"VC","nbnField":"cl254 OR *:*","locations":[{"irecordName":"X"}]}}'];
        yield 'empty locations' => ['{"vc":{"indiciaLocationType":"VC","nbnField":"cl254","locations":[]}}'];
        yield 'empty override' => ['{"vc":{"indiciaLocationType":"VC","nbnField":"cl254","locations":[{"irecordName":"X","nbnName":""}]}}'];
        yield 'unknown name property' => ['{"vc":{"indiciaLocationType":"VC","nbnField":"cl254","locations":[{"name":"X"}]}}'];
        yield 'duplicate' => ['{"vc":{"indiciaLocationType":"VC","nbnField":"cl254","locations":[{"irecordName":"X"},{"irecordName":"X"}]}}'];
    }

    /**
     * Make the removed location-type setting an explicit migration error.
     */
    public function testRejectsLegacyLocationType(): void
    {
        putenv('import.geographicRegionLocationType=Vice County');
        $_ENV['import.geographicRegionLocationType'] = 'Vice County';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Your import configuration needs updating before imports can run');
        $this->expectExceptionMessage('docs/configuration-reference.md#geographic-regions');
        new Import();
    }

    /**
     * Point installations still using the former CSV geography list to migration instructions.
     */
    public function testLegacyGeographyCsvErrorLinksToConfigurationGuide(): void
    {
        $this->setGeographyEnv('Cheshire,South Lancashire');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Your import configuration needs updating before imports can run');
        $this->expectExceptionMessage('docs/configuration-reference.md#geographic-regions');
        new Import();
    }

    /**
     * Override every environment lookup surface for a configuration test.
     *
     * @param string $json Geography JSON or intentionally invalid input.
     */
    private function setGeographyEnv(string $json): void
    {
        putenv('import.geographicRegions=' . $json);
        $_ENV['import.geographicRegions'] = $json;
        $_SERVER['import.geographicRegions'] = $json;
    }

    /**
     * Require species in the configured reporting ranks.
     */
    public function testThrowsWhenSpeciesRankMissing(): void
    {
        putenv('import.taxonRanks=Order,Family,Genus');
        $_ENV['import.taxonRanks'] = 'Order,Family,Genus';
        $_SERVER['import.taxonRanks'] = 'Order,Family,Genus';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('import.taxonRanks must include Species');

        new Import();
    }

    /**
     * Accept reporting rank names regardless of case.
     */
    public function testAllowsSpeciesRankCaseInsensitive(): void
    {
        putenv('import.taxonRanks=order,family,species');
        $_ENV['import.taxonRanks'] = 'order,family,species';
        $_SERVER['import.taxonRanks'] = 'order,family,species';

        $config = new Import();

        $this->assertNotEmpty((array) $config->taxonRanks);
    }

    /**
     * Default species aggregates to the Species reporting rank.
     */
    public function testDefaultRankMappingTargetsSpecies(): void
    {
        $config = new Import();

        $this->assertSame('Species', $config->taxonRankMappings['Species aggregate']);
    }

    /**
     * Load the configured cadence for incomplete occurrence processing.
     */
    public function testConfiguresIncompleteOccurrenceCadence(): void
    {
        putenv('import.incompleteOccurrenceRunsPerDerivedRun=12');
        $_ENV['import.incompleteOccurrenceRunsPerDerivedRun'] = '12';
        $_SERVER['import.incompleteOccurrenceRunsPerDerivedRun'] = '12';

        $config = new Import();

        $this->assertSame(12, $config->incompleteOccurrenceRunsPerDerivedRun);
    }

    /**
     * Reject mappings that target a rank absent from reporting configuration.
     */
    public function testRejectsRankMappingToUnconfiguredRank(): void
    {
        putenv('import.taxonRankMappings={"Species aggregate":"Not configured"}');
        $_ENV['import.taxonRankMappings'] = '{"Species aggregate":"Not configured"}';
        $_SERVER['import.taxonRankMappings'] = '{"Species aggregate":"Not configured"}';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mapping target must be a configured reporting rank');

        new Import();
    }

    /**
     * Clear environment overrides so configuration tests remain isolated.
     */
    protected function tearDown(): void
    {
        foreach (['import.geographicRegions', 'import.geographicRegionLocationType'] as $key) {
            putenv($key . '=null');
            $_ENV[$key] = 'null';
            $_SERVER[$key] = 'null';
        }
        putenv('import.taxonRanks');
        unset($_ENV['import.taxonRanks'], $_SERVER['import.taxonRanks']);
        putenv('import.taxonRankMappings');
        unset($_ENV['import.taxonRankMappings'], $_SERVER['import.taxonRankMappings']);
        putenv('import.incompleteOccurrenceRunsPerDerivedRun');
        unset(
            $_ENV['import.incompleteOccurrenceRunsPerDerivedRun'],
            $_SERVER['import.incompleteOccurrenceRunsPerDerivedRun'],
        );

        parent::tearDown();
    }
}
