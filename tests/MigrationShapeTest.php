<?php

declare(strict_types=1);

namespace K9o\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Structured declarative-DDL migration shape (tier-3 type model). Tier-3 PHP keeps `array` pass-through at
 * runtime, so this asserts the canonical wire JSON the documented array shape
 * serializes to: snake_case op tags, nested `searchable` inside `encrypted`,
 * a create_table `up` + a drop_table `down`.
 */
class MigrationShapeTest extends TestCase
{
    /** @return array<string, mixed> The structured Migration shape. */
    private function sampleMigration(): array
    {
        return [
            'version' => 1,
            'slug' => 'create_customers',
            'up' => [
                [
                    'create_table' => [
                        'name' => 'customers',
                        'columns' => [
                            ['name' => 'id', 'type' => 'uuid', 'primary_key' => true],
                            ['name' => 'org_id', 'type' => 'uuid', 'not_null' => true, 'references' => [
                                'collection' => 'orgs',
                                'on' => 'id',
                                'on_delete' => 'CASCADE',
                            ]],
                            ['name' => 'email', 'type' => 'text', 'not_null' => true, 'unique' => true],
                            // encrypted union — object form with NESTED searchable
                            ['name' => 'ssn', 'type' => 'text', 'encrypted' => ['store' => 'vault', 'searchable' => true]],
                            // encrypted union — bare `true` inline shorthand
                            ['name' => 'notes', 'type' => 'text', 'encrypted' => true],
                            ['name' => 'tax_id', 'type' => 'text', 'encrypted' => ['store' => 'inline'], 'blind_indexed' => true],
                        ],
                        'indexes' => [
                            ['name' => 'customers_email_idx', 'columns' => ['email'], 'unique' => true],
                        ],
                    ],
                ],
            ],
            'down' => [
                ['drop_table' => 'customers'],
            ],
        ];
    }

    public function testWireJsonRoundTrip(): void
    {
        $json = json_encode($this->sampleMigration());
        $this->assertIsString($json);
        $decoded = json_decode($json, true);

        // version + slug + up/down arrays
        $this->assertSame(1, $decoded['version']);
        $this->assertSame('create_customers', $decoded['slug']);
        $this->assertCount(1, $decoded['up']);
        $this->assertCount(1, $decoded['down']);

        // key-tagged create_table op
        $up0 = $decoded['up'][0];
        $this->assertArrayHasKey('create_table', $up0);
        $this->assertSame('customers', $up0['create_table']['name']);

        // snake_case column attrs
        $cols = $up0['create_table']['columns'];
        $this->assertTrue($cols[0]['primary_key']);
        $this->assertTrue($cols[1]['not_null']);
        $this->assertSame('CASCADE', $cols[1]['references']['on_delete']);

        // encrypted union — object form: `searchable` NESTED inside `encrypted`
        $ssn = $cols[3];
        $this->assertSame(['store' => 'vault', 'searchable' => true], $ssn['encrypted']);
        $this->assertArrayNotHasKey('searchable', $ssn, 'searchable must be nested inside encrypted, never a column sibling');

        // encrypted union — bare `true` inline shorthand
        $this->assertTrue($cols[4]['encrypted']);

        // blind_indexed sibling (C-mode)
        $this->assertTrue($cols[5]['blind_indexed']);
        $this->assertSame(['store' => 'inline'], $cols[5]['encrypted']);

        // drop_table down op is a bare string payload
        $down0 = $decoded['down'][0];
        $this->assertArrayHasKey('drop_table', $down0);
        $this->assertSame('customers', $down0['drop_table']);
    }

    public function testWireJsonExactString(): void
    {
        // Pin the exact serialized wire bytes for the encrypted-union column.
        $col = ['name' => 'ssn', 'type' => 'text', 'encrypted' => ['store' => 'vault', 'searchable' => true]];
        $this->assertSame(
            '{"name":"ssn","type":"text","encrypted":{"store":"vault","searchable":true}}',
            json_encode($col)
        );
    }
}
