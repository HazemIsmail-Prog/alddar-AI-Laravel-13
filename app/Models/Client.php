<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasComments;
use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'notes', 'created_by'])]
class Client extends Model
{
    use HasAttachments, HasComments, HasCreator;
    public function phones(): HasMany
    {
        return $this->hasMany(ClientPhone::class);
    }

    public function defaultPhone(): ?ClientPhone
    {
        $this->loadMissing('phones');

        return $this->phones->firstWhere('is_primary', true) ?? $this->phones->first();
    }

    public function locations(): HasMany
    {
        return $this->hasMany(ClientLocation::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
