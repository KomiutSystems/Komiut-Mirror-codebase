<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Models\QueueStatus;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression coverage for
 * App\Http\Controllers\APIs\Dashboard\Queues\QueueStatusAPIController.
 */
final class QueueStatusApiTest extends QueueTestCase
{
    #[Test]
    public function statuses_are_listed_alphabetically_by_name(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $this->makeQueueStatus('Active', 'Active');
        $this->makeQueueStatus('Completed', 'Completed');
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->getJson('/api/auth/queues/statuses')
            ->assertOk()
            ->assertJsonCount(3, 'queue_statuses')
            ->assertJsonPath('queue_statuses.0.name', 'Active')
            ->assertJsonPath('queue_statuses.1.name', 'Completed')
            ->assertJsonPath('queue_statuses.2.name', 'Pending');
    }

    #[Test]
    public function statuses_can_be_searched_by_name(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $this->makeQueueStatus('Cancelled', 'Cancelled');
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->getJson('/api/auth/queues/statuses?search=Cancel')
            ->assertOk()
            ->assertJsonCount(1, 'queue_statuses')
            ->assertJsonPath('queue_statuses.0.name', 'Cancelled');
    }

    #[Test]
    public function a_permitted_user_can_create_a_queue_status(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['Add Queue Statuses'], $world['sacco']));

        $this->postJson('/api/auth/queues/statuses/add', [
            'id' => 0,
            'name' => 'On Route',
            'active' => 1,
            'status' => 'Active',
        ])->assertOk()->assertJson(['success' => 'Queue Status updated successfully!']);

        $status = QueueStatus::firstOrFail();
        $this->assertSame('On Route', $status->name);
        $this->assertSame('Active', $status->status);
        $this->assertTrue((bool) $status->active);
    }

    #[Test]
    public function an_existing_queue_status_can_be_updated_by_id(): void
    {
        $world = $this->makeWorld();
        $status = $this->makeQueueStatus('Pending', 'Pending');
        Sanctum::actingAs($this->makeUser(['Add Queue Statuses'], $world['sacco']));

        $this->postJson('/api/auth/queues/statuses/add', [
            'id' => $status->id,
            'name' => 'Pending',
            'active' => 0,
            'status' => 'Suspended',
        ])->assertOk()->assertJson(['success' => 'Queue Status updated successfully!']);

        $status->refresh();
        $this->assertSame('Suspended', $status->status);
        $this->assertFalse((bool) $status->active);
        $this->assertSame(1, QueueStatus::count());
    }

    #[Test]
    public function a_duplicate_status_name_is_rejected(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        Sanctum::actingAs($this->makeUser(['Add Queue Statuses'], $world['sacco']));

        $this->postJson('/api/auth/queues/statuses/add', [
            'id' => 0,
            'name' => 'Pending',
            'active' => 1,
            'status' => 'Pending',
        ])->assertStatus(400)->assertJsonStructure(['errors' => ['name']]);

        $this->assertSame(1, QueueStatus::count());
    }

    #[Test]
    public function creating_a_queue_status_validates_its_input(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['Add Queue Statuses'], $world['sacco']));

        $this->postJson('/api/auth/queues/statuses/add', ['id' => 0])
            ->assertStatus(400)
            ->assertJsonStructure(['errors' => ['name', 'active', 'status']]);
    }

    #[Test]
    public function a_user_without_permission_gets_an_error_body_but_a_200_status(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        // NOTE: unlike the other controllers this branch returns HTTP 200.
        $this->postJson('/api/auth/queues/statuses/add', [
            'id' => 0,
            'name' => 'On Route',
            'active' => 1,
            'status' => 'Active',
        ])->assertOk()
            ->assertJson(['error' => 'You do not have permission to Add/Edit Queue Statuses']);

        $this->assertSame(0, QueueStatus::count());
    }
}
