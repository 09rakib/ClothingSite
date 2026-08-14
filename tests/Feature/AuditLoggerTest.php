<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditLogger;

/**
 * Business audit trail (PROJECT_RULES.md §23).
 */
final class AuditLoggerTest extends DatabaseTestCase
{
    private AuditLogger $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit = new AuditLogger($this->db);
    }

    public function test_log_records_an_entry(): void
    {
        $admin = $this->createUser('admin@test.com', 'admin');

        $this->audit->log($admin, 'product.archived', 'product', 42, ['reason' => 'discontinued']);

        $entries = $this->audit->recent();
        $this->assertCount(1, $entries);
        $this->assertSame('product.archived', $entries[0]['action']);
        $this->assertSame('product', $entries[0]['entity_type']);
        $this->assertSame(42, (int) $entries[0]['entity_id']);
        $this->assertSame($admin, (int) $entries[0]['actor_id']);
        $this->assertSame('admin@test.com', $this->findEmail($entries[0]));
    }

    private function findEmail(array $entry): string
    {
        $stmt = $this->db->prepare('SELECT email FROM users WHERE id = ?');
        $id = (int) $entry['actor_id'];
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $email = (string) $stmt->get_result()->fetch_assoc()['email'];
        $stmt->close();

        return $email;
    }

    public function test_system_initiated_entries_have_no_actor(): void
    {
        $this->audit->log(null, 'order.status_changed', 'order', 1);

        $entries = $this->audit->recent();
        $this->assertNull($entries[0]['actor_id']);
        $this->assertNull($entries[0]['actor_name']);
    }

    public function test_metadata_round_trips_as_json(): void
    {
        $this->audit->log(null, 'order.status_changed', 'order', 1, ['from' => 'pending', 'to' => 'confirmed']);

        $entries  = $this->audit->recent();
        $metadata = json_decode((string) $entries[0]['metadata'], true);

        $this->assertSame('pending', $metadata['from']);
        $this->assertSame('confirmed', $metadata['to']);
    }

    public function test_recent_filters_by_entity_type(): void
    {
        $this->audit->log(null, 'product.created', 'product', 1);
        $this->audit->log(null, 'order.status_changed', 'order', 1);

        $productEntries = $this->audit->recent('product');

        $this->assertCount(1, $productEntries);
        $this->assertSame('product', $productEntries[0]['entity_type']);
    }

    public function test_recent_filters_by_entity_id(): void
    {
        $this->audit->log(null, 'product.updated', 'product', 1);
        $this->audit->log(null, 'product.updated', 'product', 2);

        $entries = $this->audit->recent('product', 1);

        $this->assertCount(1, $entries);
        $this->assertSame(1, (int) $entries[0]['entity_id']);
    }

    public function test_recent_orders_newest_first(): void
    {
        $this->audit->log(null, 'action.one', 'thing', 1);
        $this->audit->log(null, 'action.two', 'thing', 1);

        $entries = $this->audit->recent();

        $this->assertSame('action.two', $entries[0]['action'], 'Most recent entry must come first.');
    }
}
