<?php

namespace App\Models\Concerns;

use App\Models\Comment;
use App\Models\CommentParticipant;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasComments
{
    public static function bootHasComments(): void
    {
        static::deleting(function ($model) {
            $model->comments()->each(fn (Comment $comment) => $comment->delete());
            $model->commentParticipants()->delete();
        });
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')->orderBy('id');
    }

    public function commentParticipants(): MorphMany
    {
        return $this->morphMany(CommentParticipant::class, 'commentable');
    }
}
