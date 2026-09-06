<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name_en', 'name_ar', 'slug', 'color', 'sort_order', 'is_system', 'created_by'])]
class OrderStatus extends Model
{
    use HasCreator;
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return array{id: int, name_en: string, name_ar: string}
     */
    public function toNamePayload(): array
    {
        return [
            'id' => $this->id,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
        ];
    }
}
