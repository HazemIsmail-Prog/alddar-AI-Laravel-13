<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['warehouse_id', 'item_id', 'qty', 'unit_cost', 'total_cost', 'type', 'created_by'])]
class StockMovement extends Model
{
    use HasCreator;
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'unit_cost' => 'integer',
            'total_cost' => 'integer',
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

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
