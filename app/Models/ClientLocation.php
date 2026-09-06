<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'client_id', 'label', 'country', 'city', 'area', 'block', 'street',
    'avenue', 'building', 'floor', 'flat', 'extras', 'paci_number', 'google_maps_link', 'created_by',
])]
#[Appends(['address'])]
class ClientLocation extends Model
{
    use HasCreator;

    protected static function booted(): void
    {
        static::saving(function (self $location) {
            if ($location->city === null) {
                $location->city = '';
            }
        });
    }

    public static function formatAddress(array $parts): string
    {
        $bits = [];
        foreach (['country', 'city', 'area'] as $key) {
            if (! empty($parts[$key])) {
                $bits[] = $parts[$key];
            }
        }
        foreach ([
            'block' => 'قطعة',
            'street' => 'شارع',
            'avenue' => 'Avenue',
            'building' => 'Building',
            'floor' => 'Floor',
            'flat' => 'Flat',
        ] as $key => $label) {
            if (! empty($parts[$key])) {
                $bits[] = $label.' '.$parts[$key];
            }
        }
        if (! empty($parts['extras'])) {
            $bits[] = $parts['extras'];
        }
        if (! empty($parts['paci_number'])) {
            $bits[] = 'PACI '.$parts['paci_number'];
        }

        return implode(', ', $bits);
    }

    protected function address(): Attribute
    {
        return Attribute::get(fn () => self::formatAddress($this->only([
            'country', 'city', 'area', 'block', 'street',
            'avenue', 'building', 'floor', 'flat', 'extras', 'paci_number',
        ])));
    }

    protected function casts(): array
    {
        return [
            'paci_number' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function machines(): HasMany
    {
        return $this->hasMany(AcMachine::class, 'location_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class, 'location_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'location_id');
    }
}
