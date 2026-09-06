<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasComments;
use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'client_id', 'location_id', 'department_id', 'type', 'includes_spare_parts',
    'includes_compressor_warranty', 'compressor_warranty_start', 'compressor_warranty_end',
    'start_date', 'end_date', 'total_amount', 'payment_count', 'planned_visits', 'status', 'created_by',
])]
class Contract extends Model
{
    use HasAttachments, HasComments, HasCreator;
    protected function casts(): array
    {
        return [
            'includes_spare_parts' => 'boolean',
            'includes_compressor_warranty' => 'boolean',
            'compressor_warranty_start' => 'date',
            'compressor_warranty_end' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'total_amount' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ClientLocation::class, 'location_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function machines(): BelongsToMany
    {
        return $this->belongsToMany(AcMachine::class, 'contract_machine', 'contract_id', 'machine_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(ContractInstallment::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function loadDetails(): static
    {
        return $this->load([
            'client.phones',
            'location',
            'department',
            'machines',
            'installments' => fn ($q) => $q->orderBy('due_date')->orderBy('id'),
            'installments.allocations.applier',
            'installments.allocations.payment',
            'orders' => fn ($q) => $q->orderBy('planned_date')->orderBy('id'),
            'orders.invoices',
            'orders.invoice',
            'orders.technician',
            'creator',
        ]);
    }
}
