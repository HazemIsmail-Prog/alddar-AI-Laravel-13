<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\ServiceOrder;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\WarehouseTransfer;
use App\Services\Orders\OrderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Commentables
{
    public const TYPES = [
        'client' => Client::class,
        'order' => ServiceOrder::class,
        'contract' => Contract::class,
        'invoice' => Invoice::class,
        'payment' => Payment::class,
        'item' => Item::class,
        'transfer' => WarehouseTransfer::class,
        'adjustment' => StockAdjustment::class,
        'journal' => JournalEntry::class,
        'comment' => Comment::class,
    ];

    public const THREAD_TYPES = [
        'client', 'order', 'contract', 'invoice', 'payment',
        'item', 'transfer', 'adjustment', 'journal',
    ];

    public static function morphMap(): array
    {
        return self::TYPES;
    }

    public static function resolve(string $type, int $id): Model
    {
        if (! in_array($type, self::THREAD_TYPES, true)) {
            abort(404);
        }

        $class = self::TYPES[$type];

        return $class::query()->findOrFail($id);
    }

    public static function alias(Model $model): string
    {
        $alias = array_search($model::class, self::TYPES, true);
        if ($alias === false) {
            abort(404);
        }

        return $alias;
    }

    public static function assertView(User $user, Model $parent): void
    {
        if (! self::canView($user, $parent)) {
            abort(403);
        }
    }

    public static function canView(User $user, Model $parent): bool
    {
        return match (true) {
            $parent instanceof Client => $user->hasPermission('clients.view'),
            $parent instanceof ServiceOrder => $user->hasPermission('orders.view')
                && ($user->canSeeAllOrders() || (int) $parent->technician_id === (int) $user->id)
                && (! $user->isFieldTech() || (
                    app(OrderService::class)->isCurrentJob($user, $parent)
                    && app(OrderService::class)->detailsVisibleTo($user, $parent)
                )),
            $parent instanceof Contract => $user->hasPermission('contracts.view'),
            $parent instanceof Invoice => $user->hasPermission('invoices.view')
                && ($user->canSeeAllInvoices() || (int) $parent->order()->value('technician_id') === (int) $user->id)
                && (! $user->isFieldTech() || (($order = $parent->order) instanceof ServiceOrder
                    && app(OrderService::class)->isCurrentJob($user, $order)
                    && app(OrderService::class)->detailsVisibleTo($user, $order))),
            $parent instanceof Payment => $user->hasPermission('payments.collect')
                || $user->hasPermission('accounting.view')
                || $user->hasPermission('clients.view'),
            $parent instanceof Item => $user->hasPermission('items.view'),
            $parent instanceof WarehouseTransfer => self::canViewTransfer($user, $parent),
            $parent instanceof StockAdjustment => self::canViewAdjustment($user, $parent),
            $parent instanceof JournalEntry => $user->hasPermission('accounting.view'),
            default => false,
        };
    }

    public static function canDeleteAttachment(User $user, Model $parent, $uploaderId): bool
    {
        if ((int) $uploaderId === (int) $user->id) {
            return true;
        }

        return match (true) {
            $parent instanceof Client => $user->hasPermission('clients.update'),
            $parent instanceof ServiceOrder => $user->hasPermission('orders.update'),
            $parent instanceof Contract => $user->hasPermission('contracts.update'),
            $parent instanceof Invoice => $user->hasPermission('invoices.update'),
            $parent instanceof Payment => $user->hasPermission('payments.collect'),
            $parent instanceof Item => $user->hasPermission('items.update'),
            $parent instanceof WarehouseTransfer => $user->hasPermission('transfers.update'),
            $parent instanceof StockAdjustment => $user->hasPermission('adjustments.update'),
            $parent instanceof JournalEntry => $user->hasPermission('accounting.update'),
            default => false,
        };
    }

    public static function seedUserIds(Model $parent): array
    {
        $ids = [];

        if ($parent instanceof ServiceOrder) {
            $ids = [$parent->technician_id, $parent->created_by, ...self::orderStaffWatcherIds($parent)];
        } elseif ($parent instanceof Invoice) {
            $ids = [$parent->created_by, $parent->confirmed_by];
        } elseif ($parent instanceof Payment) {
            $ids = [$parent->received_by];
        } elseif ($parent instanceof WarehouseTransfer) {
            $ids = [$parent->created_by, $parent->received_by];
        } elseif ($parent instanceof StockAdjustment) {
            $ids = [$parent->created_by];
        } elseif ($parent instanceof JournalEntry) {
            $ids = [$parent->posted_by];
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public static function title(Model $parent): string
    {
        return match (true) {
            $parent instanceof Client => $parent->name,
            $parent instanceof ServiceOrder => '#'.$parent->id.' '.($parent->client?->name ?? ''),
            $parent instanceof Contract => '#'.$parent->id.' '.($parent->client?->name ?? ''),
            $parent instanceof Invoice => 'Invoice #'.$parent->id,
            $parent instanceof Payment => 'Payment #'.$parent->id,
            $parent instanceof Item => trim($parent->sku.' '.$parent->name),
            $parent instanceof WarehouseTransfer => 'Transfer #'.$parent->id,
            $parent instanceof StockAdjustment => 'Adjustment #'.$parent->id,
            $parent instanceof JournalEntry => $parent->description ?: 'Journal #'.$parent->id,
            default => class_basename($parent).' #'.$parent->getKey(),
        };
    }

    public static function href(Model $parent): string
    {
        return match (true) {
            $parent instanceof Client => '/clients/'.$parent->id.'#comments',
            $parent instanceof ServiceOrder => static::orderHref($parent),
            $parent instanceof Contract => '/contracts/'.$parent->id.'#comments',
            $parent instanceof Invoice => '/invoices/'.$parent->id.'#comments',
            $parent instanceof Payment => '/clients/'.$parent->client_id.'?payment='.$parent->id,
            $parent instanceof Item => '/inventory?tab=items&id='.$parent->id.'#comments',
            $parent instanceof WarehouseTransfer => '/inventory?tab=transfers&id='.$parent->id.'#comments',
            $parent instanceof StockAdjustment => '/inventory?tab=adjustments&id='.$parent->id.'#comments',
            $parent instanceof JournalEntry => '/accounting?tab=journals&id='.$parent->id.'#comments',
            default => '/',
        };
    }

    public static function navPath(string $type): string
    {
        return match ($type) {
            'client', 'payment' => '/clients',
            'contract' => '/contracts',
            'order' => static::orderNavPath(),
            'invoice' => '/invoices',
            'item', 'transfer', 'adjustment' => '/inventory',
            'journal' => '/accounting',
            default => '/',
        };
    }

    public static function orderHref(ServiceOrder $order): string
    {
        if (static::viewerCanDispatch()) {
            return '/dispatch?order='.$order->id.'#comments';
        }
        if (static::viewerIsFieldTech()) {
            $query = $order->department_id ? '?department='.$order->department_id : '';

            return '/tech'.$query.'#comments';
        }

        return '/orders/'.$order->id.'#comments';
    }

    public static function orderNavPath(): string
    {
        if (static::viewerCanDispatch()) {
            return '/dispatch';
        }

        return static::viewerIsFieldTech() ? '/tech' : '/orders';
    }

    private static function viewerCanDispatch(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasPermission('orders.dispatch');
    }

    private static function viewerIsFieldTech(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isFieldTech();
    }

    /**
     * Dispatchers (and admins) who can see this order's department, so their inbox
     * picks up technician comments even if they never opened the thread.
     *
     * @return list<int>
     */
    private static function orderStaffWatcherIds(ServiceOrder $order): array
    {
        $departmentId = (int) $order->department_id;
        if (! $departmentId) {
            return [];
        }

        return User::query()
            ->where('is_active', true)
            ->with(['roles.permissions', 'extraPermissions', 'departments'])
            ->get()
            ->filter(fn (User $user) => $user->hasPermission('orders.dispatch')
                && $user->canDispatchDepartment($departmentId))
            ->pluck('id')
            ->all();
    }

    public static function parentOfAttachment(Model $attachable): Model
    {
        if ($attachable instanceof Comment) {
            return $attachable->commentable;
        }

        return $attachable;
    }

    private static function canViewTransfer(User $user, WarehouseTransfer $transfer): bool
    {
        if (! $user->hasPermission('transfers.view')) {
            return false;
        }
        if ($user->canSeeAllStock()) {
            return true;
        }
        if (! $user->hasPermission('inventory.view_own')) {
            return true;
        }
        $vanId = $user->warehouse?->id;

        return $vanId && in_array((int) $vanId, [(int) $transfer->from_warehouse_id, (int) $transfer->to_warehouse_id], true);
    }

    private static function canViewAdjustment(User $user, StockAdjustment $adjustment): bool
    {
        if (! $user->hasPermission('adjustments.view')) {
            return false;
        }
        if ($user->canSeeAllStock()) {
            return true;
        }
        if (! $user->hasPermission('inventory.view_own')) {
            return true;
        }
        $vanId = $user->warehouse?->id;

        return $vanId && (int) $adjustment->warehouse_id === (int) $vanId;
    }
}
