<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Account\AddressRepository;

/**
 * Customer address book (PROJECT_RULES.md §13, §19 "No IDOR vulnerabilities").
 */
final class AddressRepositoryTest extends DatabaseTestCase
{
    private AddressRepository $addresses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addresses = new AddressRepository($this->db);
    }

    private function sample(string $label = 'Home'): array
    {
        return [
            'label'          => $label,
            'recipient_name' => 'Jane Doe',
            'phone'          => '01711111111',
            'address_line1'  => '12 Green Road',
            'address_line2'  => null,
            'city'           => 'Dhaka',
        ];
    }

    public function test_create_and_find(): void
    {
        $userId = $this->createUser();
        $id     = $this->addresses->create($userId, $this->sample());

        $found = $this->addresses->findOwned($id, $userId);

        $this->assertNotNull($found);
        $this->assertSame('Jane Doe', $found['recipient_name']);
    }

    /**
     * The very first address a customer saves must become the default
     * automatically, so checkout always has something pre-selected.
     */
    public function test_first_address_is_automatically_default(): void
    {
        $userId = $this->createUser();
        $id     = $this->addresses->create($userId, $this->sample());

        $found = $this->addresses->findOwned($id, $userId);
        $this->assertTrue((bool) $found['is_default']);
    }

    public function test_only_one_address_can_be_default_at_a_time(): void
    {
        $userId = $this->createUser();
        $first  = $this->addresses->create($userId, $this->sample('Home'));
        $second = $this->addresses->create($userId, $this->sample('Office'), true);

        $all       = $this->addresses->forUser($userId);
        $defaults  = array_filter($all, static fn(array $a): bool => (bool) $a['is_default']);

        $this->assertCount(1, $defaults, 'Exactly one address may be default.');
        $this->assertSame($second, array_values($defaults)[0]['id']);
        $this->assertFalse((bool) $this->addresses->findOwned($first, $userId)['is_default']);
    }

    public function test_update_changes_fields(): void
    {
        $userId = $this->createUser();
        $id     = $this->addresses->create($userId, $this->sample());

        $updated = $this->sample();
        $updated['city'] = 'Chittagong';
        $this->addresses->update($id, $userId, $updated);

        $this->assertSame('Chittagong', $this->addresses->findOwned($id, $userId)['city']);
    }

    public function test_deleting_the_default_promotes_another_address(): void
    {
        $userId = $this->createUser();
        $first  = $this->addresses->create($userId, $this->sample('Home'));
        $second = $this->addresses->create($userId, $this->sample('Office'));

        // 'first' is default (created first).
        $this->addresses->delete($first, $userId);

        $remaining = $this->addresses->defaultForUser($userId);
        $this->assertNotNull($remaining, 'A customer with a saved address must always have a default.');
        $this->assertSame($second, $remaining['id']);
    }

    public function test_deleting_the_only_address_leaves_no_default(): void
    {
        $userId = $this->createUser();
        $id     = $this->addresses->create($userId, $this->sample());

        $this->addresses->delete($id, $userId);

        $this->assertNull($this->addresses->defaultForUser($userId));
        $this->assertSame([], $this->addresses->forUser($userId));
    }

    /**
     * §19 "No IDOR": one customer must never be able to read, edit or delete
     * another customer's saved address by guessing its id.
     */
    public function test_addresses_are_scoped_to_their_owner(): void
    {
        $owner    = $this->createUser('owner@test.com');
        $attacker = $this->createUser('attacker@test.com');
        $id       = $this->addresses->create($owner, $this->sample());

        $this->assertNull($this->addresses->findOwned($id, $attacker), 'Wrong owner must not see the address.');

        // Update/delete against the wrong owner must silently affect nothing.
        $this->addresses->update($id, $attacker, $this->sample('Hijacked'));
        $this->assertSame('Home', $this->addresses->findOwned($id, $owner)['label'], 'Address must be unchanged.');

        $this->addresses->delete($id, $attacker);
        $this->assertNotNull($this->addresses->findOwned($id, $owner), 'Address must still exist.');
    }

    public function test_count_and_max_per_user_are_queryable(): void
    {
        $userId = $this->createUser();
        $this->assertSame(0, $this->addresses->count($userId));

        $this->addresses->create($userId, $this->sample());
        $this->addresses->create($userId, $this->sample('Office'));

        $this->assertSame(2, $this->addresses->count($userId));
    }

    public function test_set_default_ignores_an_address_belonging_to_someone_else(): void
    {
        $owner    = $this->createUser('owner2@test.com');
        $attacker = $this->createUser('attacker2@test.com');
        $ownerAddr = $this->addresses->create($owner, $this->sample());

        // Attacker tries to make the owner's address their own default.
        $this->addresses->setDefault($ownerAddr, $attacker);

        $this->assertNull($this->addresses->defaultForUser($attacker));
        $this->assertNotNull($this->addresses->defaultForUser($owner));
    }
}
