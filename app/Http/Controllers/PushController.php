<?php

namespace App\Http\Controllers;

use App\Services\Push\PushService;
use Illuminate\Http\Request;

class PushController extends Controller
{
    public function vapid()
    {
        return [
            'public_key' => (string) config('services.webpush.public_key'),
        ];
    }

    public function subscribe(Request $request, PushService $push)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string'],
        ]);

        $push->subscribe($request->user(), $data);

        return ['ok' => true];
    }

    public function unsubscribe(Request $request, PushService $push)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
        ]);
        $push->unsubscribe($request->user(), $data['endpoint']);

        return ['ok' => true];
    }
}
