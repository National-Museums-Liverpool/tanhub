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

    protected function tearDown(): void
    {
        putenv('import.taxonRanks');
        unset($_ENV['import.taxonRanks'], $_SERVER['import.taxonRanks']);

        parent::tearDown();
    }
}
