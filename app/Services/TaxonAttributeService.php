<?php

namespace App\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use Config\TaxonAttributes;
use InvalidArgumentException;
use RuntimeException;

/**
 * Share typed attribute import, exact-taxon predicates and bounded response hydration.
 *
 * This installation has one Indicia taxonomy source. Public term keys retain warehouse identity;
 * local dictionary IDs are deliberately not part of the API contract.
 */
final class TaxonAttributeService
{
    /** @var array<string, array{id: int, label: string, type: string, multiValue: bool}> Configuration. */
    private array $definitions;

    /** @var array<string, array<string, mixed>>|null Local definitions indexed by public key. */
    private ?array $registry = null;

    /** @var array<int, array<int, array<string, mixed>>> Cached terms per definition/source ID. */
    private array $terms = [];

    /**
     * Resolve the installation registry without querying disabled tables.
     *
     * @param BaseConnection|null $db Database used by the caller's transaction.
     * @param TaxonAttributes|null $config Explicit configuration for isolated tests.
     */
    public function __construct(private ?BaseConnection $db = null, ?TaxonAttributes $config = null)
    {
        $this->db ??= db_connect();
        $this->definitions = ($config ?? config(TaxonAttributes::class))->definitions();
    }

    /**
     * Return validated deployment definitions in presentation order.
     *
     * @return array<string, array{id: int, label: string, type: string, multiValue: bool}> Definitions.
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * Identify import/export configuration including the selected warehouse and species list.
     *
     * @return string Configuration signature.
     */
    public function fingerprint(): string
    {
        $import = config('Import');

        return hash('sha256', json_encode([
            $import->indiciaWarehouseUrl, $import->indiciaTaxonListId, $this->definitions,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Validate the report contract and normalize cardinality before local persistence.
     *
     * @param mixed $payload Versioned JSON or decoded warehouse object.
     * @return array<string, array{termlist_id: ?int, values: list<array{value: int, label: ?string}>}> Values.
     * @throws RuntimeException If metadata, types or selected values are invalid.
     */
    public function normalize(mixed $payload): array
    {
        if ($this->definitions === []) {
            return [];
        }
        if (is_string($payload)) {
            $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        }
        if (! is_array($payload) || ($payload['version'] ?? null) !== 1
            || ! is_array($payload['attributes'] ?? null) || ! array_is_list($payload['attributes'])) {
            throw new RuntimeException('Invalid taxon attribute report contract. Deploy the updated taxa.xml.');
        }
        $byId = [];
        foreach ($payload['attributes'] as $attribute) {
            if (! is_array($attribute) || ! is_int($attribute['id'] ?? null)
                || isset($byId[$attribute['id']])) {
                throw new RuntimeException('Invalid or duplicate warehouse attribute metadata.');
            }
            $byId[$attribute['id']] = $attribute;
        }
        $result = [];
        foreach ($this->definitions as $key => $definition) {
            $attribute = $byId[$definition['id']] ?? null;
            $type = $definition['type'] === 'term' ? 'L' : 'I';
            if (! is_array($attribute) || ($attribute['data_type'] ?? null) !== $type
                || ($attribute['multi_value'] ?? null) !== $definition['multiValue']
                || ! is_array($attribute['values'] ?? null) || ! array_is_list($attribute['values'])) {
                throw new RuntimeException('Missing or incompatible warehouse attribute: ' . $key);
            }
            $termlistId = $definition['type'] === 'term'
                ? $this->integer($attribute['termlist_id'] ?? null) : null;
            if ($termlistId !== null && $termlistId < 1) {
                throw new RuntimeException('Invalid attribute vocabulary: ' . $key);
            }
            $values = $definition['multiValue'] ? $attribute['values'] : array_slice($attribute['values'], 0, 1);
            $selected = [];
            foreach ($values as $value) {
                if (! is_array($value)) {
                    throw new RuntimeException('Invalid attribute value: ' . $key);
                }
                $integer = $this->integer($value['value'] ?? null);
                $label = null;
                if ($definition['type'] === 'term') {
                    $label = $value['label'] ?? null;
                    if ($integer < 1 || ! is_string($label) || trim($label) === ''
                        || ! mb_check_encoding($label, 'UTF-8') || str_contains($label, "\0")
                        || mb_strlen($label) > 255) {
                        throw new RuntimeException('Invalid controlled attribute value: ' . $key);
                    }
                }
                $selected[(string) $integer] = ['value' => $integer, 'label' => $label];
            }
            $result[$key] = ['termlist_id' => $termlistId, 'values' => array_values($selected)];
            unset($byId[$definition['id']]);
        }
        if ($byId !== []) {
            throw new RuntimeException('The warehouse returned unrequested taxon attributes.');
        }

        return $result;
    }

    /**
     * Validate a signed whole number without lossy casts or driver coercion.
     *
     * @param mixed $value Integer or canonical decimal string.
     * @return int Validated integer.
     * @throws InvalidArgumentException If the input is outside signed PHP/BIGINT range.
     */
    public function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Attribute values must be valid whole numbers.');
        }

        return (int) $value;
    }

    /**
     * Load dictionary identities once per service instance, preserving caller builder aliases.
     *
     * @return array<string, array<string, mixed>> Configured local definitions.
     */
    public function registry(): array
    {
        if ($this->registry !== null) {
            return $this->registry;
        }
        $this->registry = [];
        if ($this->definitions === []) {
            return [];
        }
        $rows = $this->db->query('SELECT * FROM ' . $this->table('taxon_attributes'))->getResultArray();
        foreach ($rows as $row) {
            $definition = $this->definitions[$row['attribute_key']] ?? null;
            if ($definition === null) {
                continue;
            }
            if ((int) $row['source_id'] !== $definition['id'] || $row['data_type'] !== $definition['type']) {
                throw new RuntimeException('Attribute identity/type changed: ' . $row['attribute_key']);
            }
            $this->registry[$row['attribute_key']] = $row;
        }

        return $this->registry;
    }

    /**
     * Reconcile deployment definitions even when a report page has no taxa.
     *
     * Vocabulary metadata is filled from validated report rows when available.
     */
    public function synchronizeDefinitions(): void
    {
        $this->registry();
        foreach ($this->definitions as $key => $definition) {
            $row = $this->registry[$key] ?? null;
            $payload = ['attribute_key' => $key, 'source_id' => $definition['id'],
                'label' => $definition['label'], 'data_type' => $definition['type'],
                'multi_value' => (int) $definition['multiValue']];
            if ($row === null) {
                $this->write($this->db->table('taxon_attributes')->insert($payload));
                $this->registry[$key] = $payload + ['id' => (int) $this->db->insertID(), 'termlist_id' => null];
            } elseif (array_intersect_key($row, $payload) != $payload) {
                $this->write($this->db->table('taxon_attributes')->where('id', $row['id'])->update($payload));
                $this->registry[$key] = $payload + ['id' => $row['id'], 'termlist_id' => $row['termlist_id']];
            }
        }
    }

    /**
     * Atomically replace one taxon's configured values inside the caller's transaction.
     *
     * @param int $taxonId Persisted exact taxon ID.
     * @param array<string, array{termlist_id: ?int, values: list<array{value: int, label: ?string}>}> $values Normalized data.
     */
    public function replace(int $taxonId, array $values): void
    {
        $this->validateValues($values);
        $this->registry();
        foreach ($this->definitions as $key => $definition) {
            $data = $values[$key] ?? throw new RuntimeException('Missing normalized attribute: ' . $key);
            $row = $this->registry[$key] ?? null;
            $payload = ['attribute_key' => $key, 'source_id' => $definition['id'],
                'label' => $definition['label'], 'data_type' => $definition['type'],
                'multi_value' => (int) $definition['multiValue'], 'termlist_id' => $data['termlist_id']];
            if ($row === null) {
                $this->write($this->db->table('taxon_attributes')->insert($payload));
                $row = $payload + ['id' => (int) $this->db->insertID()];
                $this->registry[$key] = $row;
            } else {
                if (array_intersect_key($row, $payload) != $payload) {
                    $this->write($this->db->table('taxon_attributes')->where('id', $row['id'])->update($payload));
                }
            }
            $this->registry[$key] = $payload + ['id' => $row['id']];
            $attributeId = (int) $row['id'];
            if (! isset($this->terms[$attributeId])) {
                $this->terms[$attributeId] = [];
                $terms = $this->db->query('SELECT * FROM ' . $this->table('taxon_attribute_terms')
                    . ' WHERE attribute_id = ?', [$attributeId])->getResultArray();
                foreach ($terms as $term) {
                    $this->terms[$attributeId][(int) $term['source_id']] = $term;
                }
            }
            $this->write($this->db->table('taxon_attribute_values')
                ->where('taxon_id', $taxonId)->where('attribute_id', $attributeId)->delete());
            $assignments = [];
            foreach ($data['values'] as $value) {
                $termId = null;
                if ($definition['type'] === 'term') {
                    $term = $this->terms[$attributeId][$value['value']] ?? null;
                    $termPayload = ['attribute_id' => $attributeId, 'source_id' => $value['value'],
                        'term_key' => 'indicia:' . $value['value'], 'label' => $value['label']];
                    if ($term === null) {
                        $this->write($this->db->table('taxon_attribute_terms')->insert($termPayload));
                        $term = $termPayload + ['id' => (int) $this->db->insertID()];
                    } elseif ($term['label'] !== $value['label']) {
                        $this->write($this->db->table('taxon_attribute_terms')->where('id', $term['id'])->update($termPayload));
                        $term = $termPayload + ['id' => $term['id']];
                    }
                    $this->terms[$attributeId][$value['value']] = $term;
                    $termId = (int) $term['id'];
                }
                $assignments[] = ['taxon_id' => $taxonId, 'attribute_id' => $attributeId,
                    'integer_value' => $termId === null ? $value['value'] : null, 'term_id' => $termId];
            }
            if ($assignments !== []) {
                $this->write($this->db->table('taxon_attribute_values')->insertBatch($assignments) !== false);
            }
        }
    }

    /**
     * Validate the persistence contract even during a dry run.
     *
     * @param array<string, mixed> $values Normalized assignments for every configured definition.
     * @throws RuntimeException If cardinality, ownership metadata or typed payloads are invalid.
     */
    public function validateValues(array $values): void
    {
        if (array_diff_key($values, $this->definitions) !== []) {
            throw new RuntimeException('Unconfigured normalized taxon attributes.');
        }
        $registry = $this->registry();
        foreach ($this->definitions as $key => $definition) {
            $data = $values[$key] ?? null;
            if (! is_array($data) || ! array_key_exists('termlist_id', $data)
                || ! is_array($data['values'] ?? null) || ! array_is_list($data['values'])
                || (! $definition['multiValue'] && count($data['values']) > 1)
                || ($definition['type'] === 'integer' && $data['termlist_id'] !== null)
                || ($definition['type'] === 'term' && (! is_int($data['termlist_id']) || $data['termlist_id'] < 1))) {
                throw new RuntimeException('Invalid normalized attribute metadata/cardinality: ' . $key);
            }
            $storedVocabulary = $registry[$key]['termlist_id'] ?? null;
            if ($storedVocabulary !== null && (int) $storedVocabulary !== $data['termlist_id']) {
                throw new RuntimeException('Attribute vocabulary changed: ' . $key);
            }
            foreach ($data['values'] as $value) {
                if (! is_array($value) || ! is_int($value['value'] ?? null) || ! array_key_exists('label', $value)) {
                    throw new RuntimeException('Invalid normalized attribute value: ' . $key);
                }
                if ($definition['type'] === 'integer') {
                    if ($value['label'] !== null) {
                        throw new RuntimeException('Integer attribute has a term label: ' . $key);
                    }
                } elseif ($value['value'] < 1 || ! is_string($value['label']) || trim($value['label']) === ''
                    || ! mb_check_encoding($value['label'], 'UTF-8')
                    || str_contains($value['label'], "\0") || mb_strlen($value['label']) > 255) {
                    throw new RuntimeException('Invalid normalized attribute term: ' . $key);
                }
            }
        }
    }

    /**
     * Turn database false returns into failures even when DBDebug is disabled.
     *
     * @param bool $success Whether a database write succeeded.
     * @throws RuntimeException If persistence failed.
     */
    private function write(bool $success): void
    {
        if (! $success) {
            throw new RuntimeException('Taxon attribute persistence failed.');
        }
    }

    /**
     * Quote a prefixed table identifier independently of query-builder alias state.
     *
     * @param string $name Internal table name.
     * @return string Quoted table identifier.
     */
    private function table(string $name): string
    {
        return $this->db->escapeIdentifiers($this->db->prefixTable($name));
    }

    /**
     * Validate supported request predicates without requiring any value to exist locally.
     *
     * @param array<string, mixed> $input Query parameters, with attribute__ keys.
     * @return array<string, array<string, int|string|list<int|string>|null>> Typed predicates by key.
     * @throws InvalidArgumentException If an attribute, operator or value is invalid.
     */
    public function criteria(array $input): array
    {
        $result = [];
        foreach ($input as $field => $raw) {
            if (! str_starts_with((string) $field, 'attribute__')) {
                continue;
            }
            $key = substr($field, 11);
            $definition = $this->definitions[$key] ?? null;
            if ($definition === null) {
                throw new InvalidArgumentException('Unsupported taxon attribute: ' . $key);
            }
            $operators = is_array($raw) ? $raw : ['eq' => $raw];
            if ($operators === []) {
                throw new InvalidArgumentException('Attribute filters must contain a predicate.');
            }
            foreach ($operators as $operator => $value) {
                $allowed = $definition['type'] === 'integer' ? ['eq', 'in', 'gte', 'lte'] : ['eq', 'in'];
                if (! in_array($operator, $allowed, true)) {
                    throw new InvalidArgumentException('Unsupported attribute operator: ' . $operator);
                }
                if ($operator === 'eq' && $value === 'null') {
                    $result[$key][$operator] = null;
                    continue;
                }
                $parts = $operator === 'in'
                    ? (is_array($value) && array_is_list($value) ? $value : (is_string($value) ? explode(',', $value) : []))
                    : [$value];
                if ($parts === [] || count($parts) > 100) {
                    throw new InvalidArgumentException('Choose between one and 100 attribute values.');
                }
                $validated = [];
                foreach ($parts as $part) {
                    if ($definition['type'] === 'integer') {
                        $validated[] = $this->integer($part);
                    } elseif (is_string($part) && preg_match('/^indicia:[1-9][0-9]{0,18}$/D', $part) === 1) {
                        $validated[] = $part;
                    } else {
                        throw new InvalidArgumentException('Choose a valid attribute term key.');
                    }
                }
                $result[$key][$operator] = $operator === 'in' ? $validated : $validated[0];
            }
            if (array_key_exists('eq', $result[$key]) && $result[$key]['eq'] === null && count($result[$key]) > 1) {
                throw new InvalidArgumentException('Attribute absence cannot be combined with other predicates.');
            }
            if (isset($result[$key]['gte'], $result[$key]['lte'])
                && $result[$key]['gte'] > $result[$key]['lte']) {
                throw new InvalidArgumentException('Attribute minimum must not exceed its maximum.');
            }
        }

        return $result;
    }

    /**
     * Apply one correlated membership predicate per attribute without multiplying base rows.
     *
     * @param BaseBuilder $builder Base query, normally owning taxon alias t.
     * @param array<string, array<string, mixed>> $criteria Validated predicates.
     * @param string $taxonColumn Trusted internal taxon expression.
     */
    public function apply(BaseBuilder $builder, array $criteria, string $taxonColumn = 't.id'): void
    {
        $registry = $criteria === [] ? [] : $this->registry();
        foreach ($criteria as $key => $operators) {
            $row = $registry[$key] ?? null;
            $sql = 'SELECT 1 FROM ' . $this->table('taxon_attribute_values') . ' av'
                . ' LEFT JOIN ' . $this->table('taxon_attribute_terms') . ' atr ON atr.id=av.term_id'
                . ' WHERE av.taxon_id=' . $taxonColumn . ' AND av.attribute_id=' . (int) ($row['id'] ?? 0);
            $absent = array_key_exists('eq', $operators) && $operators['eq'] === null;
            if (! $absent) {
                $column = $this->definitions[$key]['type'] === 'integer' ? 'av.integer_value' : 'atr.term_key';
                foreach ($operators as $operator => $value) {
                    $sql .= match ($operator) {
                        'eq' => ' AND ' . $column . '=' . $this->db->escape($value),
                        'gte' => ' AND ' . $column . '>=' . $this->db->escape($value),
                        'lte' => ' AND ' . $column . '<=' . $this->db->escape($value),
                        'in' => ' AND ' . $column . ' IN (' . implode(',', array_map($this->db->escape(...), $value)) . ')',
                    };
                }
            }
            $builder->where(new RawSql(($absent ? 'NOT ' : '') . 'EXISTS (' . $sql . ')'));
        }
    }

    /**
     * Build a numeric or label aggregate using only a configured attribute key.
     *
     * @param string $key Configured key, without attribute__ prefix.
     * @param string $direction ASC or DESC.
     * @return string Trusted scalar SQL expression; missing assignments produce NULL.
     * @throws InvalidArgumentException If the key/direction is unsupported.
     */
    public function sortExpression(string $key, string $direction): string
    {
        if (! isset($this->definitions[$key]) || ! in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Unsupported attribute sort: ' . $key);
        }
        $row = $this->registry()[$key] ?? null;
        $term = $this->definitions[$key]['type'] === 'term';
        $aggregate = $direction === 'DESC' ? 'MAX' : 'MIN';

        return '(SELECT ' . $aggregate . '(' . ($term ? 'ast.label' : 'asv.integer_value') . ') FROM '
            . $this->table('taxon_attribute_values') . ' asv'
            . ($term ? ' JOIN ' . $this->table('taxon_attribute_terms') . ' ast ON ast.id=asv.term_id' : '')
            . ' WHERE asv.taxon_id=t.id AND asv.attribute_id=' . (int) ($row['id'] ?? 0) . ')';
    }

    /**
     * Batch-hydrate all configured keys, retaining arrays even for single-valued attributes.
     *
     * @param list<int> $taxonIds Exact taxon IDs.
     * @return array<int, object> Attribute objects indexed by taxon ID.
     */
    public function read(array $taxonIds): array
    {
        $taxonIds = array_values(array_unique(array_filter($taxonIds, static fn (int $id): bool => $id > 0)));
        $result = [];
        foreach ($taxonIds as $id) {
            $result[$id] = (object) array_fill_keys(array_keys($this->definitions), []);
        }
        $registry = $this->definitions === [] ? [] : $this->registry();
        if ($taxonIds === [] || $registry === []) {
            return $result;
        }
        $keys = [];
        foreach ($registry as $key => $row) {
            $keys[(int) $row['id']] = $key;
        }
        $sql = 'SELECT av.taxon_id,av.attribute_id,av.integer_value,atr.term_key,atr.label FROM '
            . $this->table('taxon_attribute_values') . ' av LEFT JOIN '
            . $this->table('taxon_attribute_terms') . ' atr ON atr.id=av.term_id'
            . ' WHERE av.taxon_id IN (' . implode(',', $taxonIds) . ')'
            . ' AND av.attribute_id IN (' . implode(',', array_keys($keys)) . ')'
            . ' ORDER BY av.taxon_id,av.attribute_id,av.integer_value,atr.label,atr.term_key';
        foreach ($this->db->query($sql)->getResultArray() as $row) {
            $key = $keys[(int) $row['attribute_id']];
            $values = &$result[(int) $row['taxon_id']]->{$key};
            if (! $this->definitions[$key]['multiValue'] && $values !== []) {
                throw new RuntimeException('Stored attribute cardinality is invalid: ' . $key . '. Reimport taxa.');
            }
            $values[] = $this->definitions[$key]['type'] === 'integer' ? (int) $row['integer_value']
                : ['key' => $row['term_key'], 'label' => $row['label']];
            unset($values);
        }

        return $result;
    }

    /**
     * List currently retained terms without exposing assignments on hidden taxa.
     *
     * @param bool $public Whether blocked taxa must also be excluded.
     * @return array<string, list<array<string, mixed>>> Term choices by configured attribute.
     */
    public function choices(bool $public = false): array
    {
        $result = array_fill_keys(array_keys($this->definitions), []);
        $registry = $this->definitions === [] ? [] : $this->registry();
        if ($registry === []) {
            return $result;
        }
        $keys = [];
        foreach ($registry as $key => $row) {
            $keys[(int) $row['id']] = $key;
        }
        $sql = 'SELECT atr.* FROM ' . $this->table('taxon_attribute_terms') . ' atr'
            . ' WHERE atr.attribute_id IN (' . implode(',', array_keys($keys)) . ') AND EXISTS ('
            . 'SELECT 1 FROM ' . $this->table('taxon_attribute_values') . ' av JOIN '
            . $this->table('taxa') . ' t ON t.id=av.taxon_id AND t.deleted_at IS NULL'
            . ($public ? ' AND t.blocked=0' : '')
            . ' WHERE av.term_id=atr.id) ORDER BY atr.label,atr.term_key';
        foreach ($this->db->query($sql)->getResultArray() as $row) {
            $result[$keys[(int) $row['attribute_id']]][] = $row;
        }

        return $result;
    }
}
