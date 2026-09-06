<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $q = trim($request->string('q')->toString());
        $digits = preg_replace('/\D/', '', $q) ?: null;
        if ($q === '' || (mb_strlen($q) < 2 && ! $digits)) {
            return ['groups' => []];
        }

        $user = $request->user();
        $like = '%'.addcslashes($q, '%_\\').'%';
        $groups = [];

        if ($user->hasPermission('clients.view')) {
            $clients = Client::query()
                ->with(['phones', 'locations'])
                ->where(function ($query) use ($like) {
                    $query->where('name', 'like', $like)
                        ->orWhereHas('phones', fn ($p) => $p->where('phone', 'like', $like)
                            ->orWhereRaw("(country_code || ' ' || phone) like ?", [$like]))
                        ->orWhereHas('locations', fn ($l) => $l->where('area', 'like', $like)
                            ->orWhere('label', 'like', $like)
                            ->orWhere('city', 'like', $like));
                })
                ->orderBy('name')
                ->limit(5)
                ->get();

            $items = $clients->map(function (Client $client) use ($user) {
                $phone = $client->phones->first()?->full_phone;
                $place = $client->locations->first()?->area ?: $client->locations->first()?->label;
                $actions = [['label' => 'Open', 'to' => '/clients/'.$client->id]];
                if ($user->hasPermission('orders.create')) {
                    $actions[] = ['label' => 'New order', 'modal' => 'create-order', 'client_id' => $client->id];
                }
                if ($user->hasPermission('contracts.create')) {
                    $actions[] = ['label' => 'New contract', 'modal' => 'create-contract', 'client_id' => $client->id];
                }
                if ($user->hasPermission('contracts.view')) {
                    $actions[] = ['label' => 'Contracts', 'to' => '/contracts?client='.$client->id];
                }

                return [
                    'id' => $client->id,
                    'title' => $client->name,
                    'subtitle' => collect([$phone, $place])->filter()->implode(' · '),
                    'href' => '/clients/'.$client->id,
                    'actions' => $actions,
                ];
            });

            if ($items->isNotEmpty()) {
                $groups[] = ['type' => 'client', 'label' => 'Clients', 'items' => $items->values()];
            }
        }

        if ($user->hasPermission('orders.view')) {
            $orders = ServiceOrder::query()
                ->with(['client', 'technician', 'invoice'])
                ->when(
                    ! $user->canSeeAllOrders() && ! $user->hasRole('admin'),
                    fn ($query) => $query->where('technician_id', $user->id),
                )
                ->tap(fn ($query) => app(OrderService::class)->restrictToCurrentJobs($query, $user))
                ->where(function ($query) use ($like, $digits, $user) {
                    if ($user->isFieldTech()) {
                        $query->where(function ($visible) use ($like, $digits) {
                            $visible->where('status', '!=', 'assigned')
                                ->where(function ($inner) use ($like, $digits) {
                                    $inner->whereHas('client', fn ($c) => $c->where('name', 'like', $like))
                                        ->orWhere('notes', 'like', $like)
                                        ->orWhereHas('technician', fn ($t) => $t->where(function ($n) use ($like) {
                                            $n->where('name_en', 'like', $like)->orWhere('name_ar', 'like', $like);
                                        }))
                                        ->orWhereHas('department', fn ($d) => $d->where(function ($n) use ($like) {
                                            $n->where('name_en', 'like', $like)->orWhere('name_ar', 'like', $like);
                                        }));
                                    if ($digits) {
                                        $inner->orWhere('id', (int) $digits);
                                    }
                                });
                        });
                        if ($digits) {
                            $query->orWhere(fn ($assigned) => $assigned->where('status', 'assigned')->where('id', (int) $digits));
                        }

                        return;
                    }

                    $query->whereHas('client', fn ($c) => $c->where('name', 'like', $like))
                        ->orWhere('notes', 'like', $like)
                        ->orWhereHas('technician', fn ($t) => $t->where(function ($n) use ($like) {
                            $n->where('name_en', 'like', $like)->orWhere('name_ar', 'like', $like);
                        }))
                        ->orWhereHas('department', fn ($d) => $d->where(function ($n) use ($like) {
                            $n->where('name_en', 'like', $like)->orWhere('name_ar', 'like', $like);
                        }));
                    if ($digits) {
                        $query->orWhere('id', (int) $digits);
                    }
                })
                ->latest('id')
                ->limit(5)
                ->get();

            $items = $orders->map(function (ServiceOrder $order) use ($user) {
                $href = $this->orderHref($user, $order);
                $actions = [['label' => 'Open', 'to' => $href]];
                if ($user->hasPermission('orders.dispatch')) {
                    $actions[] = ['label' => 'Dispatch', 'to' => '/dispatch'];
                }
                if ($order->invoice && $user->hasPermission('invoices.view') && ! $user->isFieldTech()) {
                    $actions[] = ['label' => 'Invoice', 'to' => '/invoices/'.$order->invoice->id];
                }

                return [
                    'id' => $order->id,
                    'title' => app(OrderService::class)->detailsVisibleTo($user, $order)
                        ? '#'.$order->id.' '.$order->client?->name
                        : '#'.$order->id,
                    'subtitle' => $order->status,
                    'technician' => $order->technician?->toNamePayload(),
                    'href' => $href,
                    'actions' => $actions,
                ];
            });

            if ($items->isNotEmpty()) {
                $groups[] = ['type' => 'order', 'label' => 'Orders', 'items' => $items->values()];
            }
        }

        if ($user->hasPermission('invoices.view')) {
            $invoices = Invoice::query()
                ->with('order.client')
                ->when(
                    ! $user->canSeeAllInvoices() && ! $user->hasRole('admin'),
                    fn ($query) => $query->whereHas('order', fn ($o) => $o->where('technician_id', $user->id)),
                )
                ->tap(fn ($query) => app(OrderService::class)->restrictToCurrentJobs($query, $user, 'order_id'))
                ->where(function ($query) use ($like, $digits) {
                    $query->whereHas('order.client', fn ($c) => $c->where('name', 'like', $like));
                    if ($digits) {
                        $query->orWhere('id', (int) $digits)
                            ->orWhere('order_id', (int) $digits);
                    }
                })
                ->latest('id')
                ->limit(5)
                ->get();

            $items = $invoices->map(function (Invoice $invoice) use ($user) {
                $orderHref = $invoice->order instanceof ServiceOrder
                    ? $this->orderHref($user, $invoice->order)
                    : '/orders/'.$invoice->order_id;
                $href = $user->isFieldTech() ? $orderHref : '/invoices/'.$invoice->id;

                return [
                    'id' => $invoice->id,
                    'title' => 'Invoice #'.$invoice->id,
                    'subtitle' => collect([
                        $invoice->order instanceof ServiceOrder && app(OrderService::class)->detailsVisibleTo($user, $invoice->order)
                            ? $invoice->order->client?->name
                            : null,
                        $invoice->status,
                        'total '.$invoice->total,
                    ])->filter()->implode(' · '),
                    'href' => $href,
                    'actions' => [
                        ['label' => 'Open', 'to' => $href],
                        ['label' => 'Order', 'to' => $orderHref],
                    ],
                ];
            });

            if ($items->isNotEmpty()) {
                $groups[] = ['type' => 'invoice', 'label' => 'Invoices', 'items' => $items->values()];
            }
        }

        if ($user->hasPermission('contracts.view')) {
            $contracts = Contract::query()
                ->with(['client', 'location'])
                ->where(function ($query) use ($like, $digits) {
                    $query->whereHas('client', fn ($c) => $c->where('name', 'like', $like))
                        ->orWhere('type', 'like', $like);
                    if ($digits) {
                        $query->orWhere('id', (int) $digits);
                    }
                })
                ->latest('id')
                ->limit(5)
                ->get();

            $items = $contracts->map(fn (Contract $contract) => [
                'id' => $contract->id,
                'title' => $contract->client?->name.' · '.$contract->type,
                'subtitle' => collect([$contract->location?->label, $contract->status])->filter()->implode(' · '),
                'href' => '/contracts/'.$contract->id,
                'actions' => [
                    ['label' => 'Open', 'to' => '/contracts/'.$contract->id],
                    ['label' => 'Client', 'to' => '/clients/'.$contract->client_id],
                ],
            ]);

            if ($items->isNotEmpty()) {
                $groups[] = ['type' => 'contract', 'label' => 'Contracts', 'items' => $items->values()];
            }
        }

        if ($user->hasPermission('inventory.view')) {
            $catalog = Item::query()
                ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('sku', 'like', $like))
                ->orderBy('name')
                ->limit(5)
                ->get();

            $items = $catalog->map(fn (Item $item) => [
                'id' => $item->id,
                'title' => $item->name,
                'subtitle' => $item->sku.' · '.$item->type,
                'href' => '/inventory?tab=items&q='.$item->sku,
                'actions' => [['label' => 'Inventory', 'to' => '/inventory?tab=items&q='.$item->sku]],
            ]);

            if ($items->isNotEmpty()) {
                $groups[] = ['type' => 'item', 'label' => 'Items', 'items' => $items->values()];
            }
        }

        if ($user->hasPermission('users.view')) {
            $staff = User::query()
                ->where(fn ($query) => $query->where('name_en', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('civil_id', 'like', $like)->orWhere('email', 'like', $like))
                ->orderBy('name_en')
                ->limit(5)
                ->get();

            $items = $staff->map(fn (User $row) => [
                'id' => $row->id,
                'title' => $row->name_en,
                'name_en' => $row->name_en,
                'name_ar' => $row->name_ar,
                'subtitle' => $row->civil_id.($row->email ? ' · '.$row->email : ''),
                'href' => '/admin/users',
                'actions' => [['label' => 'Staff', 'to' => '/admin/users']],
            ]);

            if ($items->isNotEmpty()) {
                $groups[] = ['type' => 'staff', 'label' => 'Staff', 'items' => $items->values()];
            }
        }

        return ['groups' => $groups];
    }

    private function orderHref(User $user, ServiceOrder $order): string
    {
        if ($user->isFieldTech()) {
            $query = $order->department_id ? '?department='.$order->department_id : '';

            return '/tech'.$query;
        }

        return '/orders/'.$order->id;
    }
}
