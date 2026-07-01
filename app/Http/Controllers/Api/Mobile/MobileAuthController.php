<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileApiToken;
use App\Models\RdoResponsavel;
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

        $membershipTenantIds = $user->tenantMemberships()
            ->where('status', 'active')
            ->orderByDesc('joined_at')
            ->orderByDesc('id')
            ->pluck('tenant_id')
            ->unique()
            ->values();

        $contractTenantIds = $user->contractParticipations()
            ->where('status', 'active')
            ->orderByDesc('joined_at')
            ->orderByDesc('id')
            ->pluck('tenant_id')
            ->unique()
            ->values();

        $rdoResponsibleTenantIds = RdoResponsavel::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->pluck('tenant_id')
            ->unique()
            ->values();

        $tenantIds = $membershipTenantIds
            ->merge($contractTenantIds)
            ->merge($rdoResponsibleTenantIds)
            ->unique()
            ->values();

        if ($tenantIds->isEmpty() && $user->is_platform_admin) {
            $tenantIds = Tenant::query()
                ->where('status', 'active')
                ->orderByDesc('id')
                ->pluck('id')
                ->values();
        }

        if ($tenantIds->isEmpty()) {
            return response()->json(['message' => 'Usuário sem acesso a um ambiente ativo.'], 403);
        }

        $tenant = Tenant::query()
            ->whereIn('id', $tenantIds)
            ->where('status', 'active')
            ->get()
            ->sortBy(fn (Tenant $candidate): int => $tenantIds->search($candidate->id))
            ->first();

        if (! $tenant) {
            return response()->json(['message' => 'O ambiente vinculado ao usuário está inativo.'], 403);
        }

        $plainToken = Str::random(64);

        MobileApiToken::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => $data['device_name'] ?? 'mobile',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addMonths(6),
        ]);

        return response()->json([
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
