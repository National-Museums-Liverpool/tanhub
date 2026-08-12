<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Import;
use RuntimeException;

/**
 * @internal
 */
final class ImportConfigTest extends CIUnitTestCase
{
    public function testThrowsWhenSpeciesRankMissing(): void
    {
        putenv('import.taxonRanks=Order,Family,Genus');
        $_ENV['import.taxonRanks'] = 'Order,Family,Genus';
        $_SERVER['import.taxonRanks'] = 'Order,Family,Genus';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('import.taxonRanks must include Species');

        new Import();
    }

    public function testAllowsSpeciesRankCaseInsensitive(): void
    {
        putenv('import.taxonRanks=order,family,species');
        $_ENV['import.taxonRanks'] = 'order,family,species';
        $_SERVER['import.taxonRanks'] = 'order,family,species';

        $config = new Import();

        $this->assertNotEmpty((array) $config->taxonRanks);
    }

    public function testDefaultRankMappingTargetsSpecies(): void
    {
        $config = new Import();

        $this->assertSame('Species', $config->taxonRankMappings['Species aggregate']);
    }

    public function testRejectsRankMappingToUnconfiguredRank(): void
    {
        putenv('import.taxonRankMappings={"Species aggregate":"Not configured"}');
        $_ENV['import.taxonRankMappings'] = '{"Species aggregate":"Not configured"}';
        $_SERVER['import.taxonRankMappings'] = '{"Species aggregate":"Not configured"}';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mapping target must be a configured reporting rank');

        new Import();
    }

    protected function tearDown(): void
    {
        putenv('import.taxonRanks');
        unset($_ENV['import.taxonRanks'], $_SERVER['import.taxonRanks']);
        putenv('import.taxonRankMappings');
        unset($_ENV['import.taxonRankMappings'], $_SERVER['import.taxonRankMappings']);

        parent::tearDown();
    }
}
