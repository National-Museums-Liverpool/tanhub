<?php

namespace Tests;

use App\Models\ImportOffsetModel;
use App\Models\ImportRunModel;
use App\Services\Import\AutoImportService;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;

/**
 * Import offset model double for selector tests.
 */
final class AutoImportOffsetModelDouble extends ImportOffsetModel
{
    /** @var array<string, bool> */
    public array $completion = [];

    /**
     * Return configured completion state.
     *
     * @param string $sourceKey Task source key.
     *
     * @return bool Whether the task is complete.
     */
    public function isComplete(string $sourceKey): bool
    {
        return $this->completion[$sourceKey] ?? false;
    }
}

/**
 * Import run model double for selector tests.
 */
final class AutoImportRunModelDouble extends ImportRunModel
{
    /** @var array<string, string|null> */
    public array $finishedAt = [];

    /** @var string */
    private string $sourceKey = '';

    /**
     * Capture a query filter for the test double.
     *
     * @param string $key Query field.
     * @param mixed  $value Query value.
     *
     * @return self
     */
    public function where($key = null, $value = null): self
    {
        if ($key === 'source_key') {
            $this->sourceKey = (string) $value;
        }

        return $this;
    }

    /**
     * Ignore ordering for the single latest row represented by the double.
     *
     * @param string $orderBy Ordering field.
     * @param string $direction Ordering direction.
     *
     * @return self
     */
    public function orderBy($orderBy = '', $direction = ''): self
    {
        return $this;
    }

    /**
     * Return the configured successful run.
     *
     * @return array<string, string>|null Latest run row.
     */
    public function first($unused = null): ?array
    {
        $finishedAt = $this->finishedAt[$this->sourceKey] ?? null;

        return $finishedAt === null ? null : ['finished_at' => $finishedAt];
    }
}

/**
 * @internal
 */
final class AutoImportServiceTest extends CIUnitTestCase
{
    /**
     * Verify incomplete bootstrap tasks follow the configured order.
     */
    public function testIncompleteBootstrapTasksAreSelectedInRequiredOrder(): void
    {
        $offsetModel = new AutoImportOffsetModelDouble();
        $offsetModel->completion = [
            'indicia-taxonomy:recording_schemes' => true,
            'indicia-taxonomy:geographic_regions' => true,
            'indicia-taxonomy:grid_square_stats' => true,
            'indicia-taxonomy:taxon_groups' => true,
            'indicia-taxonomy:taxon_ranks' => false,
        ];

        $service = new AutoImportService($offsetModel, new AutoImportRunModelDouble());
        $task = $service->select(new DateTimeImmutable('2026-07-31 12:00:00'));

        $this->assertSame('indicia-taxonomy:taxon_ranks', $task['source_key']);
        $this->assertSame('entity', $task['kind']);
    }

    /**
     * Verify the oldest stale report task is selected.
     */
    public function testOldestStaleReportTaskIsSelected(): void
    {
        $offsetModel = $this->completeOffsetModel();
        $runModel = new AutoImportRunModelDouble();
        $runModel->finishedAt = [
            'derived-stats:grid_square_stats_counts' => '2026-07-31 11:00:00',
            'derived-stats:taxon_rarity' => '2026-07-31 08:00:00',
            'derived-stats:taxon_stats' => '2026-07-31 10:00:00',
            'derived-stats:taxon_year_stats' => '2026-07-31 09:00:00',
        ];

        $service = new AutoImportService($offsetModel, $runModel);
        $task = $service->select(new DateTimeImmutable('2026-07-31 12:00:00'));

        $this->assertSame('derived-stats:taxon_rarity', $task['source_key']);
        $this->assertSame('derived', $task['kind']);
    }

    /**
     * Verify an unrun report task takes precedence over occurrence imports.
     */
    public function testUnrunReportTaskIsSelectedBeforeOccurrenceImports(): void
    {
        $offsetModel = $this->completeOffsetModel();
        $runModel = new AutoImportRunModelDouble();
        $runModel->finishedAt = [
            'derived-stats:grid_square_stats_counts' => '2026-07-31 11:00:00',
            'derived-stats:taxon_rarity' => '2026-07-31 11:00:00',
            'derived-stats:taxon_stats' => '2026-07-31 11:00:00',
        ];

        $service = new AutoImportService($offsetModel, $runModel);
        $task = $service->select(new DateTimeImmutable('2026-07-31 12:00:00'));

        $this->assertSame('derived-stats:taxon_year_stats', $task['source_key']);
    }

    /**
     * Verify occurrence selection uses the least recent successful source run.
     */
    public function testLeastRecentlyRunOccurrenceSourceIsSelectedWhenStatsAreCurrent(): void
    {
        $offsetModel = $this->completeOffsetModel();
        $runModel = new AutoImportRunModelDouble();
        $runModel->finishedAt = [
            'derived-stats:grid_square_stats_counts' => '2026-07-31 11:00:00',
            'derived-stats:taxon_rarity' => '2026-07-31 11:00:00',
            'derived-stats:taxon_stats' => '2026-07-31 11:00:00',
            'derived-stats:taxon_year_stats' => '2026-07-31 11:00:00',
            'indicia-occurrences:occurrences' => '2026-07-31 09:00:00',
            'nbn-occurrences:occurrences' => '2026-07-31 10:00:00',
        ];

        $service = new AutoImportService($offsetModel, $runModel);
        $task = $service->select(new DateTimeImmutable('2026-07-31 12:00:00'));

        $this->assertSame('indicia-occurrences:occurrences', $task['source_key']);
        $this->assertSame('indicia', $task['source']);
    }

    /**
     * Return a completion model with all bootstrap tasks complete.
     *
     * @return AutoImportOffsetModelDouble Configured completion double.
     */
    private function completeOffsetModel(): AutoImportOffsetModelDouble
    {
        $model = new AutoImportOffsetModelDouble();

        foreach ([
            'recording_schemes',
            'geographic_regions',
            'grid_square_stats',
            'taxon_groups',
            'taxon_ranks',
            'taxa',
            'taxon_names',
        ] as $entity) {
            $model->completion['indicia-taxonomy:' . $entity] = true;
        }

        return $model;
    }
}
