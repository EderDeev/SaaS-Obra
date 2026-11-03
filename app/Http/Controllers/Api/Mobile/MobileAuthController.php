<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileApiToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Credenciais inválidas.',
            ]);
        }

        if ($user->must_change_password) {
            return response()->json(['message' => 'Altere a senha no sistema web antes de usar o app mobile.'], 403);
        }

        $tenants = self::availableTenants($user);
        $tenant = $tenants->first();
        abort_unless($tenant, 403, 'Usuário sem acesso a um ambiente ativo.');
        return $this->issueSession($user, $tenant, $data['device_name'] ?? 'mobile');
    }

    public static function availableTenants(User $user): \Illuminate\Support\Collection
    {
        $ids = $user->tenantMemberships()->where('status', 'active')->pluck('tenant_id')
            ->merge($user->contractParticipations()->where('status', 'active')->pluck('tenant_id'))->unique();
        return Tenant::query()->where('status', 'active')
            ->when(!$user->is_platform_admin, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('name')->get();
    }

    public function switchTenant(Request $request): JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'integer']]);
        $tenant = self::availableTenants($request->user())->firstWhere('id', (int) $data['tenant_id']);
        abort_unless($tenant, 403, 'Usuário sem acesso a este ambiente.');
        return $this->issueSession($request->user(), $tenant, 'SigWorks Mobile');
    }

    private function issueSession(User $user, Tenant $tenant, string $deviceName): JsonResponse
    {
        $plainToken = Str::random(64);

        MobileApiToken::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => $deviceName,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addMonths(6),
        ]);

        return response()->json([
            'tenants' => self::availableTenants($user)->map->only(['id', 'slug', 'name'])->values(),
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => now()->addMonths(6)->toISOString(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
            ],
        ]);
    }
}
