<?php

namespace App\Http\Controllers;

use App\Services\Comments\CommentService;
use App\Support\Commentables;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function index(Request $request, string $type, int $id, CommentService $comments)
    {
        $data = $request->validate([
            'before' => ['nullable', 'integer'],
        ]);

        return $comments->list(
            Commentables::resolve($type, $id),
            $request->user(),
            isset($data['before']) ? (int) $data['before'] : null,
        );
    }

    public function store(Request $request, string $type, int $id, CommentService $comments)
    {
        $data = $request->validate([
            'body' => ['nullable', 'string'],
            'kind' => ['nullable', 'in:text,voice,image,file'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file'],
        ]);

        return $comments->create(
            Commentables::resolve($type, $id),
            $request->user(),
            $data['body'] ?? null,
            $data['kind'] ?? null,
            array_values($request->file('files') ?? []),
        );
    }

    public function read(Request $request, string $type, int $id, CommentService $comments)
    {
        $comments->markRead(Commentables::resolve($type, $id), $request->user());

        return ['ok' => true];
    }
}
