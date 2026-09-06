<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasComments;
use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'client_id', 'phone_id', 'location_id', 'department_id', 'contract_id', 'technician_id',
    'created_by', 'source', 'status', 'sort_order', 'planned_date', 'notes',
    'accepted_at', 'reached_at', 'completed_at',
])]
class ServiceOrder extends Model
{
    use HasAttachments, HasComments, HasCreator;
    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'accepted_at' => 'datetime',
            'reached_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function phone(): BelongsTo
    {
        return $this->belongsTo(ClientPhone::class, 'phone_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ClientLocation::class, 'location_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'order_id')->latest('id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'order_id')->latestOfMany();
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id')->orderBy('id');
    }

    public function isPlannedFuture(?string $today = null): bool
    {
        if (! $this->planned_date) {
            return false;
        }

        return $this->planned_date->toDateString() > ($today ?? now()->toDateString());
    }
}
