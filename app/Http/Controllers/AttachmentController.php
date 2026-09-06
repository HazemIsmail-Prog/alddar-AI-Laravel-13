<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\Comments\AttachmentService;
use App\Support\Commentables;
use Illuminate\Http\Request;

class AttachmentController extends Controller
{
    public function index(Request $request, string $type, int $id, AttachmentService $attachments)
    {
        $parent = Commentables::resolve($type, $id);
        Commentables::assertView($request->user(), $parent);

        return $parent->attachments()
            ->with('user')
            ->get()
            ->map(fn (Attachment $attachment) => $attachments->serialize($attachment))
            ->values();
    }

    public function store(Request $request, string $type, int $id, AttachmentService $attachments)
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file'],
        ]);
        $parent = Commentables::resolve($type, $id);
        Commentables::assertView($request->user(), $parent);

        return collect(array_values($request->file('files') ?? []))
            ->map(fn ($file) => $attachments->serialize($attachments->store($parent, $request->user(), $file)->load('user')))
            ->values();
    }

    public function show(Request $request, Attachment $attachment, AttachmentService $attachments)
    {
        $attachment->load('attachable');

        return $attachments->download($attachment, $request->user());
    }

    public function destroy(Request $request, Attachment $attachment, AttachmentService $attachments)
    {
        $attachment->load('attachable');
        $attachments->destroy($attachment, $request->user());

        return ['ok' => true];
    }
}
