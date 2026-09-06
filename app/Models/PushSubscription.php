<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'endpoint', 'public_key', 'auth_token', 'content_encoding', 'created_by'])]
class PushSubscription extends Model
{
    use HasCreator;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
