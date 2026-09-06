<?php

namespace App\Http\Controllers;

use App\Models\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderStatusController extends Controller
{
    public function index()
    {
        return OrderStatus::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    public function update(Request $request, OrderStatus $orderStatus)
    {
        if ($request->filled('color')) {
            $request->merge(['color' => strtoupper($request->string('color')->toString())]);
        }

        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:80'],
            'name_ar' => ['required', 'string', 'max:80'],
            'color' => [
                'required',
                'regex:/^#[0-9A-F]{6}$/',
                Rule::unique('order_statuses', 'color')->ignore($orderStatus->id),
            ],
        ], [
            'color.unique' => 'This color is already used by another status.',
            'color.regex' => 'Use a hex color like #2563EB.',
        ]);

        $orderStatus->update($data);

        return $orderStatus->fresh();
    }
}
