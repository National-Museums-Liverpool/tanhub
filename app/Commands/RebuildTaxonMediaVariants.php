<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Rebuilds taxon media variants from original files.
 */
class RebuildTaxonMediaVariants extends BaseCommand
{
    /**
     * @var string
     */
    protected $group = 'tanhub';

    /**
     * @var string
     */
    protected $name = 'media:rebuild-taxon-media-variants';

    /**
     * @var string
     */
    protected $description = 'Regenerate taxon media variants for all media records or a filtered subset.';

    /**
     * @var string
     */
    protected $usage = 'media:rebuild-taxon-media-variants [options]';

    /**
     * @var array<string, string>
     */
    protected $options = [
        '--media-id' => 'Optional single media row ID to rebuild.',
        '--taxon-id' => 'Optional taxon ID to rebuild media for.',
        '--dry-run' => 'Preview target rows without writing files or DB updates.',
    ];

    /**
     * Execute the command.
     *
     * @param array<string, mixed> $params
     * @return void
     */
    public function run(array $params)
    {
        $mediaIdOption = $params['media-id'] ?? CLI::getOption('media-id');
        $taxonIdOption = $params['taxon-id'] ?? CLI::getOption('taxon-id');
        $dryRun = array_key_exists('dry-run', $params) || (bool) CLI::getOption('dry-run');

        $mediaId = is_numeric($mediaIdOption) ? (int) $mediaIdOption : null;
        $taxonId = is_numeric($taxonIdOption) ? (int) $taxonIdOption : null;

        if ($mediaId !== null && $mediaId <= 0) {
            CLI::error('Invalid --media-id value. Must be a positive integer.');
            return;
        }

        if ($taxonId !== null && $taxonId <= 0) {
            CLI::error('Invalid --taxon-id value. Must be a positive integer.');
            return;
        }

        $title = 'Rebuilding taxon media variants';
        if ($dryRun) {
            $title .= ' (dry run)';
        }

        CLI::write($title . '.', 'yellow');

        try {
            /** @var \App\Services\TaxonMediaUploadService $service */
            $service = service('taxonMediaUploadService');
            $result = $service->rebuildExistingVariants($mediaId, $taxonId, $dryRun);

            CLI::write('Processed: ' . (int) $result['processed'], 'green');
            CLI::write('Updated: ' . (int) $result['updated'], 'green');
            CLI::write('Errors: ' . (int) $result['errors'], ((int) $result['errors'] > 0) ? 'red' : 'green');

            foreach (($result['messages'] ?? []) as $message) {
                CLI::error((string) $message);
            }
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());
            $this->showError($exception);
        }
    }
}
