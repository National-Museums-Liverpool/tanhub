<?php

namespace App\Services\Import;

use App\Models\ImportOffsetModel;

/**
 * Resolves completion dependencies shared by the imports UI and automation.
 */
class ImportTaskDependencyService
{
    /**
     * Task dependency graph.
     *
     * @var array<string, array<int, string>>
     */
    private const DEPENDENCIES = [
        'indicia-taxonomy:grid_square_stats' => ['indicia-taxonomy:geographic_regions'],
        'indicia-taxonomy:taxa' => [
            'indicia-taxonomy:recording_schemes',
            'indicia-taxonomy:geographic_regions',
            'indicia-taxonomy:taxon_groups',
            'indicia-taxonomy:taxon_ranks',
        ],
        'indicia-taxonomy:taxon_names' => ['indicia-taxonomy:taxa'],
        'indicia-occurrences:occurrences' => [
            'indicia-taxonomy:recording_schemes',
            'indicia-taxonomy:geographic_regions',
            'indicia-taxonomy:grid_square_stats',
            'indicia-taxonomy:taxon_groups',
            'indicia-taxonomy:taxon_ranks',
            'indicia-taxonomy:taxa',
            'indicia-taxonomy:taxon_names',
        ],
        'nbn-occurrences:occurrences' => [
            'indicia-taxonomy:recording_schemes',
            'indicia-taxonomy:geographic_regions',
            'indicia-taxonomy:grid_square_stats',
            'indicia-taxonomy:taxon_groups',
            'indicia-taxonomy:taxon_ranks',
            'indicia-taxonomy:taxa',
            'indicia-taxonomy:taxon_names',
        ],
        'derived-stats:taxon_stats' => [
            'indicia-occurrences:occurrences',
            'nbn-occurrences:occurrences',
            'derived-stats:taxon_year_stats',
        ],
        'derived-stats:taxon_year_stats' => [
            'indicia-occurrences:occurrences',
            'nbn-occurrences:occurrences',
        ],
        'derived-stats:grid_square_stats_counts' => ['indicia-taxonomy:grid_square_stats'],
        'derived-stats:taxon_rarity' => ['indicia-taxonomy:taxa'],
    ];

    /**
     * Create the dependency resolver.
     *
     * @param ImportOffsetModel|null $importOffsetModel Completion state model.
     */
    public function __construct(private readonly ?ImportOffsetModel $importOffsetModel = null)
    {
    }

    /**
     * Return incomplete dependency source keys for a task.
     *
     * @param string $sourceKey Task source key.
     * @return array<int, string> Incomplete dependency source keys.
     */
    public function blockedBy(string $sourceKey): array
    {
        $offsetModel = $this->importOffsetModel ?? model(ImportOffsetModel::class);

        return array_values(array_filter(
            self::DEPENDENCIES[$sourceKey] ?? [],
            static fn (string $dependency): bool => ! $offsetModel->isComplete($dependency),
        ));
    }

    /**
     * Determine whether a task's completion dependencies are satisfied.
     *
     * @param string $sourceKey Task source key.
     * @return bool True when the task is not blocked.
     */
    public function isRunnable(string $sourceKey): bool
    {
        return $this->blockedBy($sourceKey) === [];
    }
}
