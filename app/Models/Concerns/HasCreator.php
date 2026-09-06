<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

trait HasCreator
{
    public static function bootHasCreator(): void
    {
        static::creating(function ($model) {
            if ($model->created_by) {
                return;
            }

            $id = auth()->id();
            if (! $id) {
                throw new InvalidArgumentException('created_by requires an authenticated user.');
            }

            $model->created_by = $id;
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
