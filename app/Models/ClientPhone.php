<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['client_id', 'country_code', 'phone', 'is_primary', 'created_by'])]
#[Appends(['full_phone'])]
class ClientPhone extends Model
{
    use HasCreator;
    public const DEFAULT_COUNTRY_CODE = '+965';

    public static function normalizeCountryCode(?string $code): string
    {
        $digits = preg_replace('/\D/', '', (string) $code) ?: '965';

        return '+'.$digits;
    }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    protected function countryCode(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ?: self::DEFAULT_COUNTRY_CODE,
            set: fn (?string $value) => self::normalizeCountryCode($value),
        );
    }

    protected function fullPhone(): Attribute
    {
        return Attribute::get(fn () => trim(($this->country_code ?: self::DEFAULT_COUNTRY_CODE).' '.$this->phone));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class, 'phone_id');
    }
}
