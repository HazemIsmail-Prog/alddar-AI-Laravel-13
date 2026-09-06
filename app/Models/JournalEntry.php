<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasComments;
use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['date', 'description', 'source_type', 'source_id', 'event_key', 'posted_by', 'created_by'])]
#[Appends(['is_manual'])]
class JournalEntry extends Model
{
    use HasAttachments, HasComments, HasCreator;
    public const SOURCE_MANUAL = 'manual';

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    protected function isManual(): Attribute
    {
        return Attribute::get(fn () => $this->source_type === self::SOURCE_MANUAL);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_id');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
