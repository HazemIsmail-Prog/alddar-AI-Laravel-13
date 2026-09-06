<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['invoice_id', 'item_id', 'description', 'machine_id', 'quantity', 'unit_amount', 'unit_cost', 'is_covered', 'created_by'])]
class InvoiceItem extends Model
{
    use HasCreator;
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_amount' => 'integer',
            'unit_cost' => 'integer',
            'is_covered' => 'boolean',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(AcMachine::class, 'machine_id');
    }

    public function label(): string
    {
        return $this->item?->name ?: (string) $this->description;
    }

    public function lineTotal(): int
    {
        if ($this->is_covered) {
            return 0;
        }

        return $this->extend((string) $this->quantity, (int) $this->unit_amount);
    }

    public function lineCost(): int
    {
        return $this->extend((string) $this->quantity, (int) $this->unit_cost);
    }

    private function extend(string $qty, int $unit): int
    {
        $product = bcmul($qty, (string) $unit, 2);
        if (bccomp($product, '0', 2) >= 0) {
            return (int) bcadd($product, '0.5', 0);
        }

        return (int) bcsub($product, '0.5', 0);
    }
}
