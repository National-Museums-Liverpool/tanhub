<?php

namespace Tests;

use App\Database\Migrations\CreateTaxonAttributes;
use App\Services\TaxonAttributeService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\TaxonAttributes;
use InvalidArgumentException;
use RuntimeException;

/**
 * Exercise typed cardinality, exact-taxon membership, sorting and database constraints.
 *
 * @internal
 */
final class TaxonAttributeServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private TaxonAttributes $configuration;
    private TaxonAttributeService $attributes;

    /**
     * Install real attribute migrations in a separate in-memory database.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'attrs_',
            'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $this->connection->query('CREATE TABLE attrs_taxa (id INTEGER PRIMARY KEY,'
            . ' blocked INTEGER NOT NULL DEFAULT 0, deleted_at TEXT NULL)');
        require_once APPPATH . 'Database/Migrations/2026-11-09-100000_CreateTaxonAttributes.php';
        (new CreateTaxonAttributes(Database::forge($this->connection)))->up();
        foreach (range(1, 4) as $id) {
            $this->connection->table('taxa')->insert(['id' => $id]);
        }
        $this->configuration = new TaxonAttributes();
        $this->configuration->attributes = [
            'sqs' => ['id' => 24, 'label' => 'SQS', 'type' => 'integer', 'multiValue' => false],
            'biotope' => ['id' => 15, 'label' => 'Broad biotope', 'type' => 'term', 'multiValue' => true],
            'habitat' => ['id' => 16, 'label' => 'Habitat', 'type' => 'term', 'multiValue' => true],
            'sat' => ['id' => 20, 'label' => 'SAT', 'type' => 'term', 'multiValue' => false],
        ];
        $this->attributes = new TaxonAttributeService($this->connection, $this->configuration);
    }

    /**
     * Close only this test's private connection.
     */
    protected function tearDown(): void
    {
        $this->connection->close();
        parent::tearDown();
    }

    /**
     * Build the versioned report metadata around supplied value arrays.
     *
     * @param array<string, list<array{value: int, label?: string}>> $values Source assignments.
     * @return array<string, mixed> Deployed report contract.
     */
    private function payload(array $values): array
    {
        $attributes = [];
        foreach ($this->configuration->definitions() as $key => $definition) {
            $attributes[] = ['id' => $definition['id'],
                'data_type' => $definition['type'] === 'term' ? 'L' : 'I',
                'multi_value' => $definition['multiValue'],
                'termlist_id' => $definition['type'] === 'term' ? 627 : null,
                'values' => $values[$key] ?? []];
        }

        return ['version' => 1, 'attributes' => $attributes];
    }

    /**
     * Persist a normalized report unit using the same transaction boundary as import.
     *
     * @param int $taxonId Exact fixture taxon.
     * @param array<string, list<array{value: int, label?: string}>> $values Source values.
     */
    private function seed(int $taxonId, array $values): void
    {
        $this->connection->transBegin();
        $this->attributes->replace($taxonId, $this->attributes->normalize($this->payload($values)));
        $this->assertTrue($this->connection->transCommit());
    }

    /**
     * Retain zero/one items for singleton metadata without rejecting dirty warehouse rows.
     */
    public function testSingletonSelectionAndMultiValueDeduplication(): void
    {
        $this->seed(1, [
            'sqs' => [['value' => 1], ['value' => 9]],
            'biotope' => [['value' => 101, 'label' => 'open habitats'],
                ['value' => 102, 'label' => 'tree-associated'], ['value' => 101, 'label' => 'open habitats']],
            'habitat' => [['value' => 103, 'label' => 'shaded woodland floor'],
                ['value' => 104, 'label' => 'tall sward & scrub']],
            'sat' => [['value' => 105, 'label' => 'First SAT'], ['value' => 106, 'label' => 'Other SAT']],
        ]);
        $data = $this->attributes->read([1, 2]);
        $this->assertSame([1], $data[1]->sqs);
        $this->assertCount(2, $data[1]->biotope);
        $this->assertCount(2, $data[1]->habitat);
        $this->assertSame([['key' => 'indicia:105', 'label' => 'First SAT']], $data[1]->sat);
        $this->assertSame([], $data[2]->sqs);
        $this->assertSame([], $data[2]->sat);
        $this->assertCount(6, $this->connection->table('taxon_attribute_values')->get()->getResultArray());
    }

    /**
     * Replaying and clearing a taxon must replace values rather than append stale assignments.
     */
    public function testReplayEmptyReplacementAndLabelChanges(): void
    {
        $this->seed(1, ['habitat' => [['value' => 1, 'label' => 'Before']]]);
        $this->seed(1, ['habitat' => [['value' => 1, 'label' => 'After']]]);
        $this->assertSame([['key' => 'indicia:1', 'label' => 'After']], $this->attributes->read([1])[1]->habitat);
        $this->assertCount(1, $this->connection->table('taxon_attribute_terms')->get()->getResultArray());
        $this->seed(1, []);
        $this->assertSame([], $this->attributes->read([1])[1]->habitat);
        $this->assertSame([], $this->attributes->choices()['habitat']);
    }

    /**
     * A partial contract must not look like a request to delete assignments.
     */
    public function testMissingMetadataIsRejected(): void
    {
        $payload = $this->payload([]);
        array_pop($payload['attributes']);
        $this->expectException(RuntimeException::class);
        $this->attributes->normalize($payload);
    }

    /**
     * Expected cardinality must agree with the source metadata.
     */
    public function testIncorrectCardinalityMetadataIsRejected(): void
    {
        $payload = $this->payload([]);
        $payload['attributes'][0]['multi_value'] = true;
        $this->expectException(RuntimeException::class);
        $this->attributes->normalize($payload);
    }

    /**
     * Definition IDs cannot be reused under different public keys.
     */
    public function testDuplicateConfigurationIsRejected(): void
    {
        $this->configuration->attributes['duplicate'] = $this->configuration->attributes['sqs'];
        $this->expectException(InvalidArgumentException::class);
        $this->configuration->definitions();
    }

    /**
     * Multi-valued ranges must have one assignment satisfying both numeric bounds.
     */
    public function testNumericBoundsMatchTheSameValue(): void
    {
        $this->configuration->attributes = [
            'score' => ['id' => 25, 'label' => 'Generic score', 'type' => 'integer', 'multiValue' => true],
        ];
        $this->attributes = new TaxonAttributeService($this->connection, $this->configuration);
        $this->seed(1, ['score' => [['value' => 2], ['value' => 9]]]);
        $this->seed(2, ['score' => [['value' => 5]]]);
        $builder = $this->connection->table('taxa t')->select('t.id');
        $this->attributes->apply($builder, $this->attributes->criteria([
            'attribute__score' => ['gte' => '4', 'lte' => '6'],
        ]));
        $this->assertSame([2], array_map('intval', array_column($builder->get()->getResultArray(), 'id')));
    }

    /**
     * Dry-run validation must reject the same stored-vocabulary changes as real persistence.
     */
    public function testValidationRejectsChangedStoredVocabulary(): void
    {
        $this->seed(1, ['habitat' => [['value' => 101, 'label' => 'Woodland']]]);
        $values = $this->attributes->normalize($this->payload([]));
        $values['habitat']['termlist_id'] = 628;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Attribute vocabulary changed: habitat');
        $this->attributes->validateValues($values);
    }

    /**
     * Labels, not term IDs, define ordering; missing values stay last in either direction.
     */
    public function testControlledSortingAndDuplicateFreeMembership(): void
    {
        $this->seed(1, ['habitat' => [['value' => 300, 'label' => 'Alpha'], ['value' => 1, 'label' => 'Zulu']]]);
        $this->seed(2, ['habitat' => [['value' => 2, 'label' => 'Beta']]]);
        foreach (['ASC', 'DESC'] as $direction) {
            $expression = $this->attributes->sortExpression('habitat', $direction);
            $rows = $this->connection->table('taxa t')->select('t.id')
                ->orderBy('(' . $expression . ' IS NULL)', 'ASC', false)
                ->orderBy($expression, $direction, false)->orderBy('t.id')->get()->getResultArray();
            $this->assertSame([1, 2, 3, 4], array_map('intval', array_column($rows, 'id')));
        }
        $builder = $this->connection->table('taxa t')->select('t.id');
        $this->attributes->apply($builder, $this->attributes->criteria([
            'attribute__habitat' => ['in' => 'indicia:1,indicia:300'],
        ]));
        $this->assertSame(1, $builder->countAllResults());
    }

    /**
     * Public term discovery cannot reveal a blocked or soft-deleted assignment.
     */
    public function testTermDiscoveryRespectsVisibility(): void
    {
        $this->seed(1, ['habitat' => [['value' => 1, 'label' => 'Blocked-only']]]);
        $this->connection->table('taxa')->where('id', 1)->update(['blocked' => 1]);
        $this->assertCount(1, $this->attributes->choices()['habitat']);
        $this->assertSame([], $this->attributes->choices(true)['habitat']);
        $this->connection->table('taxa')->where('id', 1)->update(['deleted_at' => '2020-01-01']);
        $this->assertSame([], $this->attributes->choices()['habitat']);
    }

    /**
     * Integer absence must not match an explicitly stored zero.
     */
    public function testAbsenceIsDistinctFromZero(): void
    {
        $this->seed(1, ['sqs' => [['value' => 0]]]);
        $builder = $this->connection->table('taxa t')->select('t.id');
        $this->attributes->apply($builder, $this->attributes->criteria(['attribute__sqs' => 'null']));
        $this->assertSame([2, 3, 4], array_map('intval', array_column($builder->orderBy('t.id')->get()->getResultArray(), 'id')));
    }

    /**
     * Unknown attributes and unsupported label comparisons must not become SQL.
     */
    public function testUnsupportedTermOperatorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->attributes->criteria(['attribute__habitat' => ['contains' => 'wood']]);
    }

    /**
     * Reject out-of-range values before they can be rounded or truncated by a driver.
     */
    public function testIntegerOverflowIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->attributes->integer('9223372036854775808');
    }

    /**
     * An empty installation keeps an object-shaped empty attribute result.
     */
    public function testDisabledAttributesNeedNoDictionaryQueries(): void
    {
        $this->configuration->attributes = [];
        $service = new TaxonAttributeService($this->connection, $this->configuration);
        $this->assertSame('{}', json_encode($service->read([1])[1], JSON_THROW_ON_ERROR));
        $this->assertSame([], $service->normalize(null));
    }

    /**
     * The database must reject linking another attribute's dictionary term.
     */
    public function testTermOwnershipForeignKey(): void
    {
        $this->seed(1, ['habitat' => [['value' => 101, 'label' => 'Woodland']]]);
        $term = $this->connection->table('taxon_attribute_terms')->get()->getRowArray();
        $biotope = $this->attributes->registry()['biotope'];
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->connection->table('taxon_attribute_values')->insert([
            'taxon_id' => 2, 'attribute_id' => $biotope['id'], 'term_id' => $term['id'],
        ]);
    }

    /**
     * CHECK constraints complement application validation on enforcing drivers.
     */
    public function testAlternatePayloadCheck(): void
    {
        $this->seed(1, ['habitat' => [['value' => 101, 'label' => 'Woodland']]]);
        $term = $this->connection->table('taxon_attribute_terms')->get()->getRowArray();
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->connection->table('taxon_attribute_values')->insert([
            'taxon_id' => 2, 'attribute_id' => $term['attribute_id'],
            'term_id' => $term['id'], 'integer_value' => 99,
        ]);
    }
}
