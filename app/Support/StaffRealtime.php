<?php

namespace App\Support;

use App\Events\ClientChanged;
use App\Events\ConversationChanged;
use App\Events\OrderChanged;
use App\Models\Client;
use App\Models\Comment;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StaffRealtime
{
    public static function order(ServiceOrder $order, string $action, ?int $technicianId = null): void
    {
        $event = new OrderChanged(
            $action,
            (int) $order->id,
            $order->department_id ? (int) $order->department_id : null,
            $technicianId ?? ($order->technician_id ? (int) $order->technician_id : null),
            $order->client_id ? (int) $order->client_id : null,
            (string) $order->status,
        );

        self::afterCommit(fn () => broadcast($event));
    }

    public static function client(Client $client, string $action): void
    {
        $event = new ClientChanged($action, (int) $client->id);

        self::afterCommit(fn () => broadcast($event));
    }

    public static function conversation(Model $parent, string $action): void
    {
        $thread = Commentables::parentOfAttachment($parent);
        if ($thread instanceof Comment) {
            $thread = $thread->commentable;
        }
        if (! $thread instanceof Model) {
            return;
        }

        $event = new ConversationChanged(
            $action,
            Commentables::alias($thread),
            (int) $thread->getKey(),
        );

        self::afterCommit(fn () => broadcast($event));
    }

    /**
     * @param  callable(): void  $callback
     */
    private static function afterCommit(callable $callback): void
    {
        if (app()->runningUnitTests() || DB::transactionLevel() === 0) {
            $callback();

            return;
        }

        DB::afterCommit($callback);
    }
}
