<?php

namespace Tests\Feature;

use App\Events\ClientChanged;
use App\Events\ConversationChanged;
use App\Events\OrderChanged;
use App\Models\Client;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Orders\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DemoClients;
use Tests\Support\DemoInventory;
use Tests\TestCase;

class RealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        DemoInventory::seed();
        DemoClients::seed();
    }

    public function test_creating_an_order_dispatches_order_changed(): void
    {
        Event::fake([OrderChanged::class, ClientChanged::class, ConversationChanged::class]);

        $user = User::query()->where('email', 'callcenter@example.test')->first();
        $template = ServiceOrder::query()->where('status', 'pending')->first();

        $response = $this->actingAs($user)
            ->postJson('/api/orders', [
                'client_id' => $template->client_id,
                'phone_id' => $template->phone_id,
                'location_id' => $template->location_id,
                'department_id' => $template->department_id,
                'notes' => 'Realtime job',
            ])
            ->assertSuccessful();

        $orderId = (int) $response->json('id');

        Event::assertDispatched(OrderChanged::class, function (OrderChanged $event) use ($template, $orderId) {
            $payload = $event->broadcastWith();

            return $event->action === 'created'
                && $payload['action'] === 'created'
                && $payload['order_id'] === $orderId
                && $payload['department_id'] === $template->department_id
                && $payload['client_id'] === $template->client_id
                && $payload['status'] === 'pending';
        });
    }

    public function test_changing_order_status_dispatches_order_changed(): void
    {
        Event::fake([OrderChanged::class, ClientChanged::class, ConversationChanged::class]);

        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $pending = ServiceOrder::query()->where('status', 'pending')->whereNull('technician_id')->first();
        $this->assertNotNull($pending);

        $this->actingAs($dispatcher)
            ->postJson("/api/orders/{$pending->id}/assign", [
                'technician_id' => $tech->id,
            ])
            ->assertSuccessful();

        Event::assertDispatched(OrderChanged::class, function (OrderChanged $event) use ($pending, $tech) {
            $payload = $event->broadcastWith();

            return $event->action === 'assigned'
                && $payload['action'] === 'assigned'
                && $payload['order_id'] === $pending->id
                && $payload['technician_id'] === $tech->id
                && $payload['department_id'] === $pending->department_id
                && $payload['client_id'] === $pending->client_id
                && $payload['status'] === 'assigned';
        });
    }

    public function test_holding_an_order_keeps_the_previous_technician_on_the_event(): void
    {
        Event::fake([OrderChanged::class, ClientChanged::class, ConversationChanged::class]);

        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $current = app(OrderService::class)->currentFor($tech);
        $this->assertNotNull($current);
        $techId = (int) $current->technician_id;

        $this->actingAs($dispatcher)
            ->postJson("/api/orders/{$current->id}/hold", ['reason' => 'Client postponed'])
            ->assertOk();

        Event::assertDispatched(OrderChanged::class, function (OrderChanged $event) use ($current, $techId) {
            $payload = $event->broadcastWith();

            return $event->action === 'on_hold'
                && $payload['action'] === 'on_hold'
                && $payload['order_id'] === $current->id
                && $payload['technician_id'] === $techId
                && $payload['status'] === 'on_hold';
        });
    }

    public function test_creating_a_client_dispatches_client_changed(): void
    {
        Event::fake([OrderChanged::class, ClientChanged::class, ConversationChanged::class]);

        $user = User::query()->where('email', 'callcenter@example.test')->first();

        $response = $this->actingAs($user)
            ->postJson('/api/clients', ['name' => 'Realtime Co'])
            ->assertSuccessful();

        $clientId = (int) $response->json('id');
        $this->assertTrue(Client::query()->whereKey($clientId)->exists());

        Event::assertDispatched(ClientChanged::class, function (ClientChanged $event) use ($clientId) {
            $payload = $event->broadcastWith();

            return $event->action === 'created'
                && $payload['action'] === 'created'
                && $payload['client_id'] === $clientId;
        });
    }

    public function test_posting_a_comment_dispatches_conversation_changed(): void
    {
        Event::fake([OrderChanged::class, ClientChanged::class, ConversationChanged::class]);

        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->where('notes', 'Quarterly checkup')->first();
        $this->assertNotNull($order);

        $this->actingAs($dispatcher)
            ->postJson("/api/order/{$order->id}/comments", ['body' => 'Live note'])
            ->assertSuccessful();

        Event::assertDispatched(ConversationChanged::class, function (ConversationChanged $event) use ($order) {
            $payload = $event->broadcastWith();

            return $event->action === 'comment'
                && $payload['action'] === 'comment'
                && $payload['type'] === 'order'
                && $payload['id'] === $order->id;
        });
        Event::assertNotDispatched(ConversationChanged::class, fn (ConversationChanged $event) => $event->action === 'attachment');
    }

    public function test_marking_comments_read_dispatches_conversation_changed(): void
    {
        Event::fake([OrderChanged::class, ClientChanged::class, ConversationChanged::class]);

        $tech = User::query()->where('email', 'tech@example.test')->first();
        $order = ServiceOrder::query()->where('notes', 'Quarterly checkup')->first();
        $this->assertNotNull($order);

        $this->actingAs($tech)->postJson('/api/orders/'.$order->id.'/accept')->assertOk();
        Event::fake([OrderChanged::class, ClientChanged::class, ConversationChanged::class]);

        $this->actingAs($tech)
            ->postJson("/api/order/{$order->id}/comments/read")
            ->assertOk();

        Event::assertDispatched(ConversationChanged::class, function (ConversationChanged $event) use ($order) {
            $payload = $event->broadcastWith();

            return $event->action === 'read'
                && $payload['action'] === 'read'
                && $payload['type'] === 'order'
                && $payload['id'] === $order->id;
        });

        Event::fake([OrderChanged::class, ClientChanged::class, ConversationChanged::class]);

        $this->actingAs($tech)
            ->postJson("/api/order/{$order->id}/comments/read")
            ->assertOk();

        Event::assertNotDispatched(ConversationChanged::class);
    }

    public function test_uploading_an_attachment_dispatches_conversation_changed(): void
    {
        Storage::fake('local');
        Event::fake([OrderChanged::class, ClientChanged::class, ConversationChanged::class]);

        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->where('notes', 'Quarterly checkup')->first();
        $this->assertNotNull($order);

        $this->actingAs($dispatcher)
            ->post("/api/order/{$order->id}/attachments", [
                'files' => [UploadedFile::fake()->create('shot.jpg', 20, 'image/jpeg')],
            ])
            ->assertSuccessful();

        Event::assertDispatched(ConversationChanged::class, function (ConversationChanged $event) use ($order) {
            $payload = $event->broadcastWith();

            return $event->action === 'attachment'
                && $payload['action'] === 'attachment'
                && $payload['type'] === 'order'
                && $payload['id'] === $order->id;
        });
    }

    public function test_reordering_a_technician_column_dispatches_order_changed(): void
    {
        Event::fake([OrderChanged::class, ClientChanged::class, ConversationChanged::class]);

        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $ids = ServiceOrder::query()
            ->where('technician_id', $tech->id)
            ->whereNotIn('status', ['completed', 'cancelled', 'on_hold'])
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();
        $this->assertNotEmpty($ids);
        $ids = array_reverse($ids);

        $this->actingAs($dispatcher)
            ->postJson("/api/technicians/{$tech->id}/reorder", ['order_ids' => $ids])
            ->assertSuccessful();

        Event::assertDispatched(OrderChanged::class, function (OrderChanged $event) use ($ids, $tech) {
            $payload = $event->broadcastWith();

            return $event->action === 'updated'
                && $payload['action'] === 'updated'
                && $payload['order_id'] === (int) $ids[0]
                && $payload['technician_id'] === $tech->id;
        });
    }
}
