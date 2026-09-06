<?php

namespace App\Http\Controllers;

use App\Services\Comments\CommentService;
use App\Support\Commentables;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function index(Request $request, CommentService $comments)
    {
        return $comments->inbox($request->user());
    }

    public function read(Request $request, string $type, int $id, CommentService $comments)
    {
        $comments->markRead(Commentables::resolve($type, $id), $request->user());

        return ['ok' => true];
    }
}
