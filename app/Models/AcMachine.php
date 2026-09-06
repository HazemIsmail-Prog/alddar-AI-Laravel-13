<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['location_id', 'brand', 'model', 'serial', 'notes', 'created_by'])]
class AcMachine extends Model
{
    use HasCreator;
    public function location(): BelongsTo
    {
        return $this->belongsTo(ClientLocation::class, 'location_id');
    }

    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class, 'contract_machine', 'machine_id', 'contract_id');
    }
}
