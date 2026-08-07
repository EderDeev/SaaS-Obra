<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AiMonthlyUsage;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\UserTemporaryPasswordNotification;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function index(): Response
    {
        $usageByTenant = AiMonthlyUsage::query()
            ->whereDate('period', now()->startOfMonth()->toDateString())
            ->selectRaw('tenant_id, SUM(total_tokens) AS total_tokens')
            ->groupBy('tenant_id')
            ->pluck('total_tokens', 'tenant_id');
        $tenants = Tenant::query()
            ->withCount(['users', 'contracts'])
            ->latest()
            ->get()
            ->each(function (Tenant $tenant) use ($usageByTenant): void {
                $tenant->setAttribute('ai_tokens_used_current_month', (int) ($usageByTenant[$tenant->id] ?? 0));
                $tenant->setAttribute(
                    'ai_effective_monthly_token_limit',
                    $tenant->ai_monthly_token_limit ?? (int) config('services.openai.tenant_monthly_token_limit')
                );
            });

        return Inertia::render('Platform/Tenants/Index', [
            'tenants' => $tenants,
            'plans' => ['starter', 'growth', 'enterprise'],
            'statuses' => ['trial', 'active', 'suspended'],
            'defaultAiTenantTokenLimit' => (int) config('services.openai.tenant_monthly_token_limit'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:50', Rule::unique('tenants', 'slug')],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'plan' => ['required', Rule::in(['starter', 'growth', 'enterprise'])],
            'status' => ['required', Rule::in(['trial', 'active', 'suspended'])],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
            'ai_monthly_token_limit' => ['nullable', 'integer', 'min:1000', 'max:1000000000'],
        ]);

        $data['cnpj'] = $this->formatCnpj($data['cnpj'] ?? null);

        $tenant = Tenant::create($data);

        $temporaryPassword = PasswordPolicy::temporaryPassword();

        $owner = User::firstOrCreate(
            ['email' => mb_strtolower($data['owner_email'])],
            [
                'name' => $data['owner_name'],
                'password' => Hash::make($temporaryPassword),
                'email_verified_at' => now(),
                'must_change_password' => true,
                'temporary_password_created_at' => now(),
            ],
        );

        if (! $owner->wasRecentlyCreated) {
            $owner->forceFill([
                'password' => Hash::make($temporaryPassword),
                'email_verified_at' => $owner->email_verified_at ?? now(),
                'must_change_password' => true,
                'temporary_password_created_at' => now(),
            ])->save();
        }

        $tenant->memberships()->updateOrCreate(
            ['user_id' => $owner->id],
            [
                'role' => 'tenant_owner',
                'status' => 'active',
                'invited_at' => now(),
                'joined_at' => now(),
            ],
        );

        $owner->notify(new UserTemporaryPasswordNotification($tenant, $temporaryPassword));

        return back()->with('success', 'Tenant criado com owner ativo. Senha provisoria enviada por email.');
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'plan' => ['required', Rule::in(['starter', 'growth', 'enterprise'])],
            'status' => ['required', Rule::in(['trial', 'active', 'suspended'])],
            'ai_monthly_token_limit' => ['nullable', 'integer', 'min:1000', 'max:1000000000'],
        ]);

        $data['cnpj'] = $this->formatCnpj($data['cnpj'] ?? null);

        $tenant->update($data);

        return back()->with('success', 'Tenant atualizado.');
    }

    public function updateAssistantQuota(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'ai_monthly_token_limit' => ['nullable', 'integer', 'min:1000', 'max:1000000000'],
        ]);

        $tenant->update([
            'ai_monthly_token_limit' => $data['ai_monthly_token_limit'] ?? null,
        ]);

        return back()->with('success', 'Cota mensal do agente atualizada.');
    }

    private function formatCnpj(?string $cnpj): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $cnpj);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) !== 14) {
            throw ValidationException::withMessages([
                'cnpj' => 'Informe um CNPJ com 14 digitos.',
            ]);
        }

        return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits);
    }
}
