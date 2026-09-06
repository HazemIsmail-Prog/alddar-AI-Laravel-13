<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasComments;
use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['sku', 'name', 'type', 'valuation_method', 'cost', 'default_price', 'is_sellable', 'department_id', 'created_by'])]
class Item extends Model
{
    use HasAttachments, HasComments, HasCreator;
    protected function casts(): array
    {
        return [
            'cost' => 'integer',
            'default_price' => 'integer',
            'is_sellable' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function availableForDepartment(?int $departmentId): bool
    {
        return $this->department_id === null || (int) $this->department_id === (int) $departmentId;
    }

    public function isTracked(): bool
    {
        return $this->type === 'tracked_part';
    }

    public function isSellable(): bool
    {
        return $this->is_sellable !== false;
    }

    public static function reclassifyUntrackedParts(): void
    {
        static::query()->where('type', 'untracked_part')->whereNull('valuation_method')->update([
            'valuation_method' => 'fifo',
        ]);
        static::query()->where('type', 'untracked_part')->update([
            'type' => 'tracked_part',
        ]);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function layers(): HasMany
    {
        return $this->hasMany(StockLayer::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function movementsExist(): bool
    {
        return $this->movements()->exists();
    }
}
