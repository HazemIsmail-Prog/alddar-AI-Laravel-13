<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['warehouse_id', 'item_id', 'qty_remaining', 'unit_cost', 'received_at', 'created_by'])]
class StockLayer extends Model
{
    use HasCreator;
    protected function casts(): array
    {
        return [
            'qty_remaining' => 'decimal:2',
            'unit_cost' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
