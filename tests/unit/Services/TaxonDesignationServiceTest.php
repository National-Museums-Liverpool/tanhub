<?php

namespace Tests;

use App\Services\TaxonDesignationService;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * Verify canonical designation edits and portable SQL/PHP fallback semantics.
 *
 * @internal
 */
final class TaxonDesignationServiceTest extends CIUnitTestCase
{
    /**
     * Canonical lists remove empty tokens and duplicates without changing abbreviation case.
     */
    public function testNormalizationAndByteBoundary(): void
    {
        $this->assertSame('LC;VU', TaxonDesignationService::normalize(" VU ; LC;\tVU;; "));
        $this->assertNull(TaxonDesignationService::normalize(" ;\n;\t"));
        $this->assertNull(TaxonDesignationService::normalize(null));
        $boundary = str_repeat('é', 32767) . 'a';
        $this->assertSame($boundary, TaxonDesignationService::normalize($boundary));
        $this->expectException(InvalidArgumentException::class);
        TaxonDesignationService::normalize($boundary . 'a');
    }

    /**
     * Invalid form/import values fail explicitly rather than becoming strings or partial data.
     */
    public function testInvalidValuesAreRejected(): void
    {
        foreach ([['LC'], 1, "LC\0", "\xFF"] as $value) {
            try {
                TaxonDesignationService::normalize($value);
                $this->fail('Invalid designation input was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('valid UTF-8 strings without NULs', $exception->getMessage());
            }
        }
    }

    /**
     * SQL and details-page resolution agree even for whitespace manually written to the database.
     */
    public function testSqlAndPhpResolveEachOverrideIndependently(): void
    {
        $db = \Config\Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBDebug' => true,
        ], false);
        try {
            $db->query('CREATE TABLE taxa (gb_red_list TEXT, gb_red_list_override TEXT)');
            foreach ([null, '', ' ', "\t \n\r\v", 'VU', ' VU '] as $override) {
                foreach ([null, 'LC'] as $source) {
                    $db->table('taxa')->emptyTable();
                    $db->table('taxa')->insert(['gb_red_list' => $source, 'gb_red_list_override' => $override]);
                    $row = $db->table('taxa t')
                        ->select(TaxonDesignationService::effectiveSql('gb_red_list') . ' AS value', false)
                        ->get()->getRowArray();
                    $expected = $override === null || trim($override) === '' ? $source : $override;
                    $this->assertSame($expected, $row['value']);
                    $this->assertSame($expected, TaxonDesignationService::resolve($source, $override));
                }
            }
        } finally {
            $db->close();
        }
        $this->expectException(InvalidArgumentException::class);
        TaxonDesignationService::effectiveSql('gb_status; DROP TABLE taxa');
    }
}
