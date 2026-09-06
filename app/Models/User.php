<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use App\Models\Concerns\HasRoles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name_en', 'name_ar', 'civil_id', 'email', 'password', 'is_active', 'created_by'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasCreator, HasFactory, HasRoles, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_user');
    }

    public function dispatchableServiceDepartments()
    {
        if ($this->hasRole('admin')) {
            return Department::query()->service()->orderBy('name_en')->get();
        }

        return $this->serviceDepartments();
    }

    public function serviceDepartments()
    {
        return $this->departments()
            ->where('departments.is_service', true)
            ->orderBy('departments.name_en')
            ->get();
    }

    public function canDispatchDepartment(?int $departmentId): bool
    {
        if (! $departmentId) {
            return false;
        }

        return $this->dispatchableServiceDepartments()->contains('id', $departmentId);
    }

    public function warehouse(): HasOne
    {
        return $this->hasOne(Warehouse::class, 'technician_id');
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
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
