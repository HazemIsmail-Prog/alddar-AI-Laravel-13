<?php

namespace App\Services\Comments;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\User;
use App\Support\Commentables;
use App\Support\StaffRealtime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentService
{
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic'];

    public const AUDIO_MIMES = [
        'audio/webm',
        'audio/ogg',
        'audio/mpeg',
        'audio/mp4',
        'audio/wav',
        'audio/x-wav',
        'audio/aac',
        'audio/x-m4a',
        'audio/m4a',
        'audio/mp4a-latm',
        'audio/x-aac',
        'application/ogg',
    ];

    /** Browsers often sniff MediaRecorder blobs as video containers. */
    public const AUDIO_ALIASES = [
        'video/webm' => 'audio/webm',
        'video/ogg' => 'audio/ogg',
        'video/mp4' => 'audio/mp4',
    ];

    public const AUDIO_EXTENSIONS = ['webm', 'm4a', 'mp3', 'ogg', 'wav', 'aac', 'opus'];

    public const FILE_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv',
        'application/zip',
        'application/x-zip-compressed',
    ];

    public function store(Model $parent, User $user, UploadedFile $file): Attachment
    {
        $kind = $this->kindFor($file);
        $this->assertSize($file, $kind);

        $disk = (string) config('filesystems.attachments');
        $ext = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin';
        $path = sprintf('attachments/%s/%s/%s.%s', now()->format('Y'), now()->format('m'), Str::uuid(), $ext);
        Storage::disk($disk)->put($path, $file->getContent(), ['visibility' => 'private']);

        $attachment = $parent->attachments()->create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName() ?: 'file',
            'mime' => $this->storedMime($file, $kind),
            'size' => $file->getSize() ?: 0,
            'kind' => $kind,
        ]);

        if (! $parent instanceof Comment) {
            StaffRealtime::conversation($parent, 'attachment');
        }

        return $attachment;
    }

    public function download(Attachment $attachment, User $user): StreamedResponse
    {
        $parent = Commentables::parentOfAttachment($attachment->attachable);
        if ($parent instanceof Comment) {
            $parent = $parent->commentable;
        }
        Commentables::assertView($user, $parent);

        if (! Storage::disk($attachment->disk)->exists($attachment->path)) {
            abort(404);
        }

        $inline = in_array($attachment->kind, ['image', 'audio'], true);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime,
                'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$attachment->original_name.'"',
            ],
        );
    }

    public function destroy(Attachment $attachment, User $user): void
    {
        $parent = Commentables::parentOfAttachment($attachment->attachable);
        Commentables::assertView($user, $parent);
        if (! Commentables::canDeleteAttachment($user, $parent, $attachment->user_id)) {
            abort(403);
        }
        $attachment->delete();
    }

    public function serialize(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'kind' => $attachment->kind,
            'original_name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'created_at' => $attachment->created_at?->toIso8601String(),
            'user' => $attachment->user?->toNamePayload(),
        ];
    }

    public function kindFor(UploadedFile $file): string
    {
        $mime = $this->mimeOf($file);
        $client = $this->clientMimeOf($file);
        $ext = $this->extensionOf($file);

        if (in_array($mime, self::IMAGE_MIMES, true) || in_array($client, self::IMAGE_MIMES, true)) {
            return 'image';
        }
        if ($this->isAudio($mime, $client, $ext)) {
            return 'audio';
        }
        if (in_array($mime, self::FILE_MIMES, true) || in_array($client, self::FILE_MIMES, true)) {
            return 'file';
        }

        throw ValidationException::withMessages([
            'files' => 'This file type is not allowed.',
        ]);
    }

    private function isAudio(string $mime, string $client, string $ext): bool
    {
        if (str_starts_with($mime, 'audio/') || str_starts_with($client, 'audio/')) {
            return true;
        }
        if (in_array($mime, self::AUDIO_MIMES, true) || in_array($client, self::AUDIO_MIMES, true)) {
            return true;
        }
        if (isset(self::AUDIO_ALIASES[$mime]) || isset(self::AUDIO_ALIASES[$client])) {
            return in_array($ext, ['webm', 'm4a', 'mp4', 'ogg', 'opus'], true);
        }

        return $mime === 'application/octet-stream' && in_array($ext, self::AUDIO_EXTENSIONS, true);
    }

    private function storedMime(UploadedFile $file, string $kind): string
    {
        $mime = $this->mimeOf($file) ?: $this->clientMimeOf($file);
        if ($kind === 'audio') {
            $mime = self::AUDIO_ALIASES[$mime] ?? $mime;
            if (! str_starts_with($mime, 'audio/')) {
                $ext = $this->extensionOf($file);
                $mime = match ($ext) {
                    'm4a', 'mp4' => 'audio/mp4',
                    'ogg', 'opus' => 'audio/ogg',
                    'mp3' => 'audio/mpeg',
                    'wav' => 'audio/wav',
                    'aac' => 'audio/aac',
                    default => 'audio/webm',
                };
            }
        }

        return $mime ?: 'application/octet-stream';
    }

    private function mimeOf(UploadedFile $file): string
    {
        return $this->normalizeMime($file->getMimeType() ?: $file->getClientMimeType() ?: '');
    }

    private function clientMimeOf(UploadedFile $file): string
    {
        return $this->normalizeMime($file->getClientMimeType() ?: '');
    }

    private function extensionOf(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if ($ext !== '') {
            return $ext;
        }

        return strtolower(pathinfo($file->getClientOriginalName() ?: '', PATHINFO_EXTENSION));
    }

    private function normalizeMime(string $mime): string
    {
        return strtolower(trim(explode(';', $mime, 2)[0]));
    }

    private function assertSize(UploadedFile $file, string $kind): void
    {
        $max = $kind === 'file' ? 20 * 1024 * 1024 : 10 * 1024 * 1024;
        if (($file->getSize() ?: 0) > $max) {
            throw ValidationException::withMessages([
                'files' => $kind === 'file' ? 'Files must be 20MB or smaller.' : 'Media must be 10MB or smaller.',
            ]);
        }
    }
}
