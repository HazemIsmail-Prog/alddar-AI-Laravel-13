<?php

namespace App\Services\Comments;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\CommentParticipant;
use App\Models\CommentRead;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Push\PushService;
use App\Support\Commentables;
use App\Support\StaffRealtime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CommentService
{
    public const PAGE_SIZE = 10;

    public function __construct(private AttachmentService $attachments) {}

    /**
     * @return array{comments: list<array<string, mixed>>, has_more: bool}
     */
    public function list(Model $parent, User $user, ?int $before = null): array
    {
        Commentables::assertView($user, $parent);
        $this->join($parent, $user);

        $query = $parent->comments()
            ->with(['user', 'attachments.user', 'reads.user'])
            ->reorder()
            ->orderByDesc('id');

        if ($before) {
            $query->where('id', '<', $before);
        }

        $page = $query->limit(self::PAGE_SIZE)->get()->reverse()->values();
        $oldestId = $page->first()?->id;
        $hasMore = $oldestId
            ? $parent->comments()->where('id', '<', $oldestId)->exists()
            : false;

        return [
            'comments' => $page->map(fn (Comment $comment) => $this->serialize($comment))->all(),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function create(Model $parent, User $user, ?string $body, ?string $kind, array $files): array
    {
        Commentables::assertView($user, $parent);
        $body = trim((string) $body) ?: null;
        if (! $body && $files === []) {
            throw ValidationException::withMessages([
                'body' => 'Write a message or attach a file.',
            ]);
        }

        $kind = $this->resolveKind($kind, $body, $files);
        $this->seedParticipants($parent);
        $this->join($parent, $user);

        $comment = $parent->comments()->create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'body' => $body,
            'kind' => $kind,
        ]);

        foreach ($files as $file) {
            $this->attachments->store($comment, $user, $file);
        }

        CommentRead::query()->create([
            'comment_id' => $comment->id,
            'user_id' => $user->id,
            'read_at' => now(),
        ]);

        StaffRealtime::conversation($parent, 'comment');
        $fresh = $comment->fresh(['user', 'attachments.user', 'reads.user']) ?? $comment;
        app(PushService::class)->notifyComment($parent, $user, $fresh);

        return $this->serialize($fresh);
    }

    public function markRead(Model $parent, User $user): void
    {
        Commentables::assertView($user, $parent);
        $this->join($parent, $user);
        $created = false;
        $ids = $parent->comments()->pluck('id');
        foreach ($ids as $id) {
            $read = CommentRead::query()->firstOrCreate(
                ['comment_id' => $id, 'user_id' => $user->id],
                ['read_at' => now()],
            );
            if ($read->wasRecentlyCreated) {
                $created = true;
            }
        }
        if ($created) {
            StaffRealtime::conversation($parent, 'read');
        }
    }

    /**
     * @return array{unread_total: int, threads: list<array<string, mixed>>, nav: array<string, int>}
     */
    public function inbox(User $user): array
    {
        $orderMorph = (new ServiceOrder)->getMorphClass();
        $canDispatch = $user->hasPermission('orders.dispatch');

        $unread = Comment::query()
            ->with('commentable')
            ->where('user_id', '!=', $user->id)
            ->whereNotExists(function ($query) use ($user) {
                $query->selectRaw('1')
                    ->from('comment_reads')
                    ->whereColumn('comment_reads.comment_id', 'comments.id')
                    ->where('comment_reads.user_id', $user->id);
            })
            ->where(function ($query) use ($user, $orderMorph, $canDispatch) {
                $query->whereExists(function ($exists) use ($user) {
                    $exists->selectRaw('1')
                        ->from('comment_participants')
                        ->whereColumn('comment_participants.commentable_type', 'comments.commentable_type')
                        ->whereColumn('comment_participants.commentable_id', 'comments.commentable_id')
                        ->where('comment_participants.user_id', $user->id);
                });
                if ($canDispatch) {
                    $query->orWhere('commentable_type', $orderMorph);
                }
            })
            ->orderByDesc('id')
            ->get();

        $groups = $unread->groupBy(fn (Comment $comment) => $comment->commentable_type.':'.$comment->commentable_id);
        $threads = [];
        $nav = [];
        $currentIds = $user->isFieldTech() ? app(OrderService::class)->currentIdsFor($user) : null;

        foreach ($groups as $group) {
            /** @var Collection<int, Comment> $group */
            $first = $group->first();
            $parent = $first->commentable;
            if (! $parent || ! Commentables::canView($user, $parent)) {
                continue;
            }
            if (is_array($currentIds) && ! $this->isFieldTechInboxParent($parent, $currentIds)) {
                continue;
            }
            $type = Commentables::alias($parent);
            $latest = $group->sortByDesc('id')->first();
            $threads[] = [
                'type' => $type,
                'id' => $parent->getKey(),
                'title' => Commentables::title($parent),
                'unread' => $group->count(),
                'preview' => $latest->body ?: $latest->kind,
                'at' => $latest->created_at?->toIso8601String(),
                'href' => Commentables::href($parent),
                'department_id' => $parent instanceof ServiceOrder ? $parent->department_id : null,
            ];
            $path = Commentables::navPath($type);
            $nav[$path] = ($nav[$path] ?? 0) + $group->count();
        }

        usort($threads, fn ($a, $b) => strcmp($b['at'] ?? '', $a['at'] ?? ''));

        return [
            'unread_total' => array_sum(array_column($threads, 'unread')),
            'threads' => $threads,
            'nav' => $nav,
        ];
    }

    /**
     * @param  list<int>  $currentIds
     */
    private function isFieldTechInboxParent(Model $parent, array $currentIds): bool
    {
        if ($parent instanceof ServiceOrder) {
            return in_array((int) $parent->getKey(), $currentIds, true);
        }

        if ($parent instanceof Invoice) {
            $orderId = (int) $parent->order_id;

            return $orderId > 0 && in_array($orderId, $currentIds, true);
        }

        return false;
    }

    public function join(Model $parent, User $user): void
    {
        CommentParticipant::query()->firstOrCreate([
            'commentable_type' => $parent->getMorphClass(),
            'commentable_id' => $parent->getKey(),
            'user_id' => $user->id,
        ]);
    }

    private function seedParticipants(Model $parent): void
    {
        foreach (Commentables::seedUserIds($parent) as $userId) {
            CommentParticipant::query()->firstOrCreate([
                'commentable_type' => $parent->getMorphClass(),
                'commentable_id' => $parent->getKey(),
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function resolveKind(?string $kind, ?string $body, array $files): string
    {
        if (in_array($kind, ['text', 'voice', 'image', 'file'], true)) {
            return $kind;
        }
        if ($files === []) {
            return 'text';
        }
        $kinds = collect($files)->map(fn (UploadedFile $file) => $this->attachments->kindFor($file))->unique();
        if ($kinds->count() === 1 && $kinds->first() === 'audio') {
            return 'voice';
        }
        if ($kinds->every(fn (string $k) => $k === 'image')) {
            return 'image';
        }

        return 'file';
    }

    public function serialize(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'kind' => $comment->kind,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toIso8601String(),
            'user' => $comment->user?->toNamePayload(),
            'attachments' => $comment->attachments->map(fn (Attachment $attachment) => $this->attachments->serialize($attachment))->all(),
            'read_by' => $comment->reads
                ->filter(fn (CommentRead $read) => (int) $read->user_id !== (int) $comment->user_id)
                ->map(fn (CommentRead $read) => $read->user?->toNamePayload())
                ->filter()
                ->values()
                ->all(),
        ];
    }
}
