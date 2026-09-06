<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'technician_id', 'created_by'])]
class Warehouse extends Model
{
    use HasCreator;
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function layers(): HasMany
    {
        return $this->hasMany(StockLayer::class);
    }

    public function isTechnicianWarehouse(): bool
    {
        return $this->type === 'technician' || $this->technician_id !== null;
    }
}
