<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['transfer_id', 'item_id', 'qty', 'unit_cost', 'created_by'])]
class WarehouseTransferLine extends Model
{
    use HasCreator;
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'unit_cost' => 'integer',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(WarehouseTransfer::class, 'transfer_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
