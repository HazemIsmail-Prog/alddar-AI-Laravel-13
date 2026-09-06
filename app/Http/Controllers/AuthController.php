<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'civil_id' => ['required', 'digits:12'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, true)) {
            throw ValidationException::withMessages([
                'civil_id' => ['The provided credentials are incorrect.'],
            ]);
        }

        $request->session()->regenerate();
        /** @var User $user */
        $user = $request->user();

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Your account is inactive.'], 403);
        }

        return response()->json($this->payload($user));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        return response()->json($this->payload($request->user()));
    }

    public static function payload(User $user): array
    {
        $user->load(['roles.permissions', 'extraPermissions', 'departments', 'warehouse']);

        return [
            'id' => $user->id,
            'name_en' => $user->name_en,
            'name_ar' => $user->name_ar,
            'civil_id' => $user->civil_id,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'roles' => $user->roleSlugs(),
            'permissions' => $user->permissionSlugs(),
            'field_tech' => $user->isFieldTech(),
            'departments' => $user->departments,
            'warehouse' => $user->warehouse,
        ];
    }
}
