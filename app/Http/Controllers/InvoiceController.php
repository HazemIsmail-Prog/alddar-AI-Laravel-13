<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Invoices\InvoiceService;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request, OrderService $orders)
    {
        $user = $request->user();
        $q = Invoice::query()
            ->with(['order.client', 'order.location', 'order.technician', 'items', 'creator'])
            ->latest('id');

        if (! $user->canSeeAllInvoices()) {
            $q->whereHas('order', fn ($order) => $order->where('technician_id', $user->id));
        }
        $orders->restrictToCurrentJobs($q, $user, 'order_id');

        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }
        if ($search = trim($request->string('search')->toString())) {
            $like = '%'.$search.'%';
            $q->whereHas('order.client', fn ($c) => $c->where('name', 'like', $like));
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 50);

        return $q->paginate($perPage);
    }

    public function show(Request $request, Invoice $invoice, OrderService $orders, PaymentService $payments)
    {
        $user = $request->user();
        $invoice->load([
            'creator',
            'items.item',
            'items.machine',
            'order.client',
            'order.contract.machines',
            'order.location.machines',
            'allocations.applier',
            'allocations.payment.receiver',
            'allocations.payment.creator',
        ]);
        $this->assertInvoiceAccess($user, $invoice, $orders);
        $payments->hydrateClientWallet($invoice->order?->client);

        return $invoice;
    }

    public function store(Request $request, ServiceOrder $order, InvoiceService $invoices, OrderService $orders)
    {
        $user = $request->user();
        if (! $user->canSeeAllOrders() && (int) $order->technician_id !== (int) $user->id) {
            abort(403, 'You cannot invoice this order.');
        }
        if ($user->isFieldTech() && ! $orders->isCurrentJob($user, $order)) {
            abort(403, 'You cannot invoice this order.');
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'exists:items,id'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'items.*.unit_amount' => ['nullable', 'integer', 'min:0'],
            'items.*.machine_id' => ['nullable', 'exists:ac_machines,id'],
            'items.*.is_covered' => ['nullable', 'boolean'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'report' => ['required', 'string'],
        ]);
        if (! $request->user()->hasPermission('invoices.confirm')) {
            unset($data['discount']);
            $data['items'] = array_map(function (array $line) {
                unset($line['unit_amount']);

                return $line;
            }, $data['items']);
        }

        return $this->domain(function () use ($invoices, $order, $request, $data) {
            $invoice = $invoices->createDraft(
                $order,
                $request->user(),
                $data['items'],
                $data['report'],
            );
            if (array_key_exists('discount', $data)) {
                $invoice = $invoices->updateDraft($invoice, ['discount' => $data['discount']], $request->user());
            }

            return $invoice;
        });
    }

    public function update(Request $request, Invoice $invoice, InvoiceService $invoices, OrderService $orders)
    {
        $invoice->load('order');
        $user = $request->user();
        if (! $user->hasPermission('invoices.update')) {
            abort(403, 'You cannot edit this invoice.');
        }
        $this->assertInvoiceAccess($user, $invoice, $orders, 'You cannot edit this invoice.');
        $canPrice = $user->hasPermission('invoices.confirm');

        $data = $request->validate([
            'discount' => ['nullable', 'integer', 'min:0'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'exists:items,id'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'items.*.unit_amount' => ['nullable', 'integer', 'min:0'],
            'items.*.machine_id' => ['nullable', 'exists:ac_machines,id'],
            'items.*.is_covered' => ['nullable', 'boolean'],
            'unit_amounts' => ['array'],
            'unit_amounts.*' => ['integer', 'min:0'],
            'report' => ['nullable', 'string'],
        ]);

        if (! $canPrice) {
            unset($data['discount'], $data['unit_amounts']);
            $data['items'] = array_map(function (array $line) {
                unset($line['unit_amount']);

                return $line;
            }, $data['items'] ?? []);
        }

        return $this->domain(fn () => $invoices->updateDraft($invoice, $data, $user));
    }

    public function confirm(Request $request, Invoice $invoice, InvoiceService $invoices)
    {
        return $this->domain(fn () => $invoices->confirm($invoice, $request->user()));
    }

    public function destroy(Request $request, Invoice $invoice, InvoiceService $invoices, OrderService $orders)
    {
        $invoice->load('order');
        $user = $request->user();
        $this->assertInvoiceAccess($user, $invoice, $orders, 'You cannot delete this invoice.');

        return $this->domain(function () use ($invoices, $invoice, $user) {
            $invoices->delete($invoice, $user);

            return ['ok' => true];
        });
    }

    public function pay(Request $request, PaymentService $payments)
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'exists:clients,id'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'installment_id' => ['nullable', 'exists:contract_installments,id'],
            'apply_to' => ['nullable', 'in:credit,invoice,installment'],
            'use_credit' => ['nullable', 'integer', 'min:0'],
            'amount' => ['required', 'integer', 'gt:0'],
            'method' => ['required', 'in:cash,card,bank,wallet'],
            'notes' => ['nullable', 'string'],
        ]);

        if (($data['method'] ?? '') === 'wallet') {
            return $this->domain(fn () => $payments->applyCredit($request->user(), $data));
        }

        return $this->domain(fn () => $payments->collect($request->user(), $data));
    }

    public function applyCredit(Request $request, PaymentService $payments)
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'exists:clients,id'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'installment_id' => ['nullable', 'exists:contract_installments,id'],
            'amount' => ['required', 'integer', 'gt:0'],
        ]);

        return $this->domain(fn () => $payments->applyCredit($request->user(), $data));
    }

    private function assertInvoiceAccess(User $user, Invoice $invoice, OrderService $orders, ?string $message = null): void
    {
        $invoice->loadMissing('order');
        if (! $user->canSeeAllInvoices() && (int) $invoice->order?->technician_id !== (int) $user->id) {
            abort(403, $message);
        }
        if ($user->isFieldTech() && (! $invoice->order instanceof ServiceOrder || ! $orders->isCurrentJob($user, $invoice->order))) {
            abort(403, $message);
        }
    }
}
