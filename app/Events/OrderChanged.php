<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class OrderChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $action,
        public int $orderId,
        public ?int $departmentId,
        public ?int $technicianId,
        public ?int $clientId,
        public string $status,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('staff')];
    }

    public function broadcastAs(): string
    {
        return 'order.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'order_id' => $this->orderId,
            'department_id' => $this->departmentId,
            'technician_id' => $this->technicianId,
            'client_id' => $this->clientId,
            'status' => $this->status,
        ];
    }
}
