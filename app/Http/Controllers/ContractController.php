<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Department;
use App\Services\Contracts\ContractService;
use App\Services\Payments\PaymentService;
use App\Support\RequestFilters;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function index(Request $request, PaymentService $payments)
    {
        $q = Contract::query()->with(['client', 'location', 'department', 'machines', 'installments', 'creator'])->latest('id');
        if ($loc = $request->integer('location_id')) {
            $q->where('location_id', $loc);
        }
        $this->applyIndexFilters($q, $request);

        $contracts = $q->get();
        $payments->attachContractNetDue($contracts);

        return $contracts;
    }

    public function store(Request $request, ContractService $contracts)
    {
        $data = $request->validate($this->rules());

        return $this->domain(fn () => $contracts->create($data, $request->user()));
    }

    public function update(Request $request, Contract $contract, ContractService $contracts)
    {
        $data = $request->validate($this->rules());

        return $this->domain(fn () => $contracts->update($contract, $data, $request->user()));
    }

    public function show(Contract $contract, PaymentService $payments)
    {
        $contract->loadDetails();
        $payments->attachContractNetDue([$contract]);
        $payments->hydrateClientWallet($contract->client);

        return $contract;
    }

    private function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'location_id' => ['required', 'exists:client_locations,id'],
            'department_id' => ['required', Department::serviceIdRule()],
            'type' => ['required', 'in:warranty,annual'],
            'status' => ['nullable', 'in:active,cancelled,expired'],
            'includes_spare_parts' => ['boolean'],
            'includes_compressor_warranty' => ['boolean'],
            'compressor_warranty_start' => ['nullable', 'date'],
            'compressor_warranty_end' => ['nullable', 'date'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'total_amount' => ['nullable', 'integer', 'min:0'],
            'payment_count' => ['nullable', 'integer', 'min:0'],
            'planned_visits' => ['nullable', 'integer', 'min:0'],
            'machine_ids' => ['array'],
            'machine_ids.*' => ['exists:ac_machines,id'],
            'installments' => ['nullable', 'array'],
            'installments.*.id' => ['nullable', 'integer', 'exists:contract_installments,id'],
            'installments.*.due_date' => ['required', 'date'],
            'installments.*.amount' => ['required', 'integer', 'min:0'],
            'installments.*.description' => ['nullable', 'string', 'max:255'],
            'visits' => ['nullable', 'array'],
            'visits.*.id' => ['nullable', 'integer', 'exists:service_orders,id'],
            'visits.*.planned_date' => ['required', 'date'],
        ];
    }

    private function applyIndexFilters($q, Request $request): void
    {
        $number = trim($request->string('number')->toString());
        if ($number !== '') {
            $id = (int) ltrim($number, '#');
            $q->where('id', $id > 0 ? $id : 0);
        }

        $types = array_values(array_intersect(RequestFilters::listParam($request, 'type'), ['warranty', 'annual']));
        if ($types) {
            $q->whereIn('type', $types);
        }

        $statuses = array_values(array_intersect(RequestFilters::listParam($request, 'status'), ['active', 'cancelled', 'expired']));
        if ($statuses) {
            $q->whereIn('status', $statuses);
        }

        $departments = RequestFilters::intList($request, 'department_id');
        if ($departments) {
            $q->whereIn('department_id', $departments);
        }

        $clientName = trim($request->string('client_name')->toString());
        if ($clientName !== '') {
            $like = '%'.$clientName.'%';
            $q->whereHas('client', fn ($client) => $client->where('name', 'like', $like));
        }

        $location = trim($request->string('location')->toString());
        if ($location !== '') {
            $like = '%'.$location.'%';
            $q->whereHas('location', function ($loc) use ($like) {
                $loc->where('label', 'like', $like)
                    ->orWhere('country', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('area', 'like', $like)
                    ->orWhere('block', 'like', $like)
                    ->orWhere('street', 'like', $like)
                    ->orWhere('avenue', 'like', $like)
                    ->orWhere('building', 'like', $like)
                    ->orWhere('floor', 'like', $like)
                    ->orWhere('flat', 'like', $like)
                    ->orWhere('extras', 'like', $like)
                    ->orWhere('paci_number', 'like', $like)
                    ->orWhere('google_maps_link', 'like', $like);
            });
        }

        RequestFilters::applyDateRange($q, 'start_date', $request->input('start_from'), $request->input('start_to'));
        RequestFilters::applyDateRange($q, 'end_date', $request->input('end_from'), $request->input('end_to'));
        RequestFilters::applyDateRange($q, 'created_at', $request->input('created_from'), $request->input('created_to'));
        $this->applyBoolFilter($q, 'includes_spare_parts', $request->input('includes_spare_parts'));
        $this->applyBoolFilter($q, 'includes_compressor_warranty', $request->input('includes_compressor_warranty'));
    }

    private function applyBoolFilter($query, string $column, mixed $value): void
    {
        if ($value === null || $value === '' || $value === 'all') {
            return;
        }

        $query->where($column, RequestFilters::truthy($value));
    }
}
