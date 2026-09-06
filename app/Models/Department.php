<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

#[Fillable(['name_en', 'name_ar', 'is_service', 'created_by'])]
class Department extends Model
{
    use HasCreator;
    protected function casts(): array
    {
        return [
            'is_service' => 'boolean',
        ];
    }

    public function scopeService(Builder $query): Builder
    {
        return $query->where('is_service', true);
    }

    public static function serviceIdRule(): Exists
    {
        return Rule::exists('departments', 'id')->where('is_service', true);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_user');
    }

    public function technicians(): BelongsToMany
    {
        return $this->users()->whereHas('roles', fn ($q) => $q->where('slug', 'technician'));
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
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
