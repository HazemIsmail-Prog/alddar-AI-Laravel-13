<?php

namespace App\Services\Push;

use App\Models\Comment;
use App\Models\CommentParticipant;
use App\Models\PushSubscription;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\Commentables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class PushService
{
    /** @var list<array{user_id: int, title: string, body: string, url: string}>|null */
    public static ?array $sent = null;

    public static function fake(): void
    {
        self::$sent = [];
    }

    /**
     * @param  array{endpoint: string, keys?: array{p256dh?: string, auth?: string}, contentEncoding?: string}  $payload
     */
    public function subscribe(User $user, array $payload): PushSubscription
    {
        $endpoint = (string) $payload['endpoint'];

        return PushSubscription::query()->updateOrCreate(
            ['endpoint' => $endpoint],
            [
                'user_id' => $user->id,
                'public_key' => (string) ($payload['keys']['p256dh'] ?? ''),
                'auth_token' => (string) ($payload['keys']['auth'] ?? ''),
                'content_encoding' => (string) ($payload['contentEncoding'] ?? 'aes128gcm'),
                'created_by' => $user->id,
            ],
        );
    }

    public function unsubscribe(User $user, string $endpoint): void
    {
        PushSubscription::query()
            ->where('user_id', $user->id)
            ->where('endpoint', $endpoint)
            ->delete();
    }

    public function notifyOrderAssigned(ServiceOrder $order, User $technician): void
    {
        $this->afterCommit(function () use ($order, $technician) {
            $order->loadMissing('client');
            $this->sendTo(
                $technician,
                'New job #'.$order->id,
                (string) ($order->client?->name ?: 'CoolAir'),
                $this->techOrderUrl($order),
            );
        });
    }

    public function notifyComment(Model $parent, User $author, Comment $comment): void
    {
        $push = $this;
        $this->afterCommit(function () use ($parent, $author, $comment, $push) {
            $ids = CommentParticipant::query()
                ->where('commentable_type', $parent->getMorphClass())
                ->where('commentable_id', $parent->getKey())
                ->where('user_id', '!=', $author->id)
                ->pluck('user_id');

            if ($ids->isEmpty()) {
                return;
            }

            $preview = trim((string) $comment->body) ?: 'New comment';
            $title = 'New comment';
            $body = trim(Commentables::title($parent).' · '.$preview);

            User::query()
                ->whereIn('id', $ids)
                ->where('is_active', true)
                ->with(['roles.permissions', 'extraPermissions'])
                ->get()
                ->each(function (User $user) use ($parent, $title, $body, $push) {
                    if ($user->isFieldTech() && ! $parent instanceof ServiceOrder) {
                        return;
                    }
                    $push->sendTo($user, $title, $body, $push->commentUrl($parent, $user));
                });
        });
    }

    public function sendTo(User $user, string $title, string $body, string $url): void
    {
        if (self::$sent !== null) {
            self::$sent[] = [
                'user_id' => (int) $user->id,
                'title' => $title,
                'body' => $body,
                'url' => $url,
            ];

            return;
        }

        $publicKey = (string) config('services.webpush.public_key');
        $privateKey = (string) config('services.webpush.private_key');
        if ($publicKey === '' || $privateKey === '') {
            return;
        }

        $subscriptions = $user->pushSubscriptions;
        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string) config('services.webpush.subject'),
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);
        $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url], JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $row) {
            try {
                $report = $webPush->sendOneNotification(
                    Subscription::create([
                        'endpoint' => $row->endpoint,
                        'publicKey' => $row->public_key,
                        'authToken' => $row->auth_token,
                        'contentEncoding' => $row->content_encoding ?: 'aes128gcm',
                    ]),
                    $payload ?: '{}',
                );
                if ($report->isSubscriptionExpired()) {
                    $row->delete();
                }
            } catch (Throwable) {
                // Keep other devices; drop only expired endpoints above.
            }
        }
    }

    /**
     * @param  callable(): void  $callback
     */
    private function afterCommit(callable $callback): void
    {
        $schedule = function () use ($callback) {
            if (app()->runningUnitTests()) {
                $callback();

                return;
            }

            // Keep the live closure (do not queue/serialize — that drops $this).
            app()->terminating(function () use ($callback) {
                try {
                    $callback();
                } catch (Throwable) {
                    // Response already left; a push failure must not become a 500.
                }
            });
        };

        if (DB::transactionLevel() === 0) {
            $schedule();

            return;
        }

        DB::afterCommit($schedule);
    }

    private function techOrderUrl(ServiceOrder $order): string
    {
        $query = $order->department_id ? '?department='.$order->department_id : '';

        return '/tech'.$query;
    }

    private function commentUrl(Model $parent, User $recipient): string
    {
        if ($recipient->isFieldTech() && $parent instanceof ServiceOrder) {
            return $this->techOrderUrl($parent).'#comments';
        }

        return Commentables::href($parent);
    }
}
