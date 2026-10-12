<?php

namespace Tests;

use App\Services\Search\SearchCriteria;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Cover submitted filter validation and inclusive year/date boundary parsing.
 *
 * @internal
 */
final class SearchCriteriaTest extends CIUnitTestCase
{
    /**
     * Year syntax must produce the exact inclusive date boundaries.
     *
     * @dataProvider yearBounds
     * @param string $year Input year expression.
     * @param list<string> $lower Expected lower boundaries.
     * @param list<string> $upper Expected upper boundaries.
     */
    public function testYearBounds(string $year, array $lower, array $upper): void
    {
        $criteria = new SearchCriteria(['year' => $year], [], true);

        $this->assertSame([], $criteria->errors);
        $this->assertSame($lower, $criteria->lowerDates);
        $this->assertSame($upper, $criteria->upperDates);
    }

    /**
     * Provide single, closed and open year ranges.
     *
     * @return array<string, array{string, list<string>, list<string>}>
     */
    public static function yearBounds(): array
    {
        return [
            'single' => ['2010', ['2010-01-01'], ['2010-12-31']],
            'range' => ['2010-2012', ['2010-01-01'], ['2012-12-31']],
            'lower' => ['2010-', ['2010-01-01'], []],
            'upper' => ['-2012', [], ['2012-12-31']],
            'blank' => ['', [], []],
        ];
    }

    /**
     * Invalid inputs must never be accepted as empty, successful searches.
     */
    public function testInvalidInputsAreExplicit(): void
    {
        foreach ([
            ['year' => '-'], ['year' => '2012-2010'], ['year' => '0000'],
            ['year' => '2010,2012'], ['year' => ['2010']], ['from_date' => '2010-02-30'],
            ['taxon_id' => 'no'], ['taxon_id' => '0'], ['blocked' => 'yes'],
            ['q' => ['text']], ['q' => str_repeat('x', 201)],
            ['year' => '2010', 'from_date' => '2011-01-01'],
        ] as $input) {
            $criteria = new SearchCriteria($input, [], true);
            $this->assertNotEmpty($criteria->errors, json_encode($input));
        }
    }

    /**
     * Taxonomic status alone must not require records, but occurrence status must.
     */
    public function testOccurrenceEvidenceIsExplicit(): void
    {
        $this->assertFalse((new SearchCriteria(['taxon_blocked' => '0'], [], true))->needsOccurrences());
        $this->assertTrue((new SearchCriteria(['blocked' => '0'], [], true))->needsOccurrences());
    }
}
