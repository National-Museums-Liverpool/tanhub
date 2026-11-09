<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;

/**
 * Select installation-specific Indicia attributes without changing the taxonomy schema.
 */
class TaxonAttributes extends BaseConfig
{
    /** @var array<string, array{id: int, label: string, type: string, multiValue: bool}> Imported definitions. */
    public array $attributes = [];

    /**
     * Load the optional JSON object from the environment.
     */
    public function __construct()
    {
        parent::__construct();
        $json = env('taxonAttributes.attributes');
        if (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new InvalidArgumentException('taxonAttributes.attributes must be a JSON object.');
            }
            $this->attributes = $decoded;
        }
        $this->definitions();
    }

    /**
     * Validate configured keys and identities before using them in import or query contracts.
     *
     * @return array<string, array{id: int, label: string, type: string, multiValue: bool}> Definitions.
     * @throws InvalidArgumentException If a definition is malformed or an ID is reused.
     */
    public function definitions(): array
    {
        $ids = [];
        foreach ($this->attributes as $key => $definition) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1
                || ! is_array($definition)
                || ! is_int($definition['id'] ?? null) || $definition['id'] < 1
                || isset($ids[$definition['id']])
                || ! is_string($definition['label'] ?? null)
                || trim($definition['label']) === '' || mb_strlen($definition['label']) > 200
                || ! mb_check_encoding($definition['label'], 'UTF-8')
                || str_contains($definition['label'], "\0")
                || ! in_array($definition['type'] ?? null, ['integer', 'term'], true)
                || ! is_bool($definition['multiValue'] ?? null)) {
                throw new InvalidArgumentException('Invalid or duplicate taxon attribute definition: ' . $key);
            }
            $ids[$definition['id']] = true;
        }

        return $this->attributes;
    }
}
