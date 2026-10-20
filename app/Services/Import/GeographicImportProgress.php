<?php

namespace App\Services\Import;

use App\Models\ImportOffsetModel;

/**
 * Keeps geography streams independent behind the existing public import tasks.
 *
 * A single group uses the public task's progress directly. Multiple groups use
 * child keys; the parent checkpoint names the last attempted group for rotation.
 * Installation scope is fixed, so no checkpoint migration or reconciliation is needed.
 */
class GeographicImportProgress
{
    /**
     * Create a progress coordinator for one grouped import task.
     *
     * @param ImportOffsetModel $offsets Existing progress storage.
     * @param string            $parent Public task key.
     * @param list<string>      $groups Configured geography group keys.
     */
    public function __construct(
        private readonly ImportOffsetModel $offsets,
        private readonly string $parent,
        private readonly array $groups,
    ) {
    }

    /**
     * Prefer unfinished population, then rotate completed streams on recurring runs.
     *
     * @return string Group to process in this bounded invocation.
     */
    public function select(): string
    {
        foreach ($this->groups as $group) {
            if (! $this->offsets->isComplete($this->key($group))) {
                return $group;
            }
        }

        $last = array_search($this->offsets->getCheckpoint($this->parent), $this->groups, true);

        return $this->groups[$last === false ? 0 : ($last + 1) % count($this->groups)];
    }

    /**
     * Resolve the progress key without splitting the external data-source identity.
     *
     * @param string $group Selected geography group.
     *
     * @return string Parent key for one group, otherwise a child progress key.
     */
    public function key(string $group): string
    {
        return count($this->groups) === 1 ? $this->parent : $this->parent . ':' . $group;
    }

    /**
     * Publish stream completion and keep parent dependencies blocked until all finish.
     *
     * @param string $group Selected geography group.
     * @param bool   $complete Whether this stream reached its end without errors.
     *
     * @return bool Whether all configured streams are complete.
     */
    public function finish(string $group, bool $complete): bool
    {
        $this->offsets->setCompletion($this->key($group), $complete);
        if (count($this->groups) === 1) {
            return $complete;
        }

        $allComplete = $this->isCompleteAfter($group, $complete);
        $this->offsets->setCheckpoint($this->parent, $group);
        $this->offsets->setCompletion($this->parent, $allComplete);

        return $allComplete;
    }

    /**
     * Calculate parent completion without writing progress, including for dry runs.
     *
     * @param string $group Selected geography group.
     * @param bool   $complete Whether its current batch reached the end.
     *
     * @return bool Whether this result completes all configured groups.
     */
    public function isCompleteAfter(string $group, bool $complete): bool
    {
        $allComplete = $complete;
        foreach ($this->groups as $other) {
            if ($other !== $group && ! $this->offsets->isComplete($this->key($other))) {
                $allComplete = false;
            }
        }

        return $allComplete;
    }
}
