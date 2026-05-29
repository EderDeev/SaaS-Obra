<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
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
        return Inertia::render('Platform/Tenants/Index', [
            'tenants' => Tenant::query()
                ->withCount(['users', 'contracts'])
                ->latest()
                ->get(),
            'plans' => ['starter', 'growth', 'enterprise'],
            'statuses' => ['trial', 'active', 'suspended'],
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
        ]);

        $data['cnpj'] = $this->formatCnpj($data['cnpj'] ?? null);

        $tenant->update($data);

        return back()->with('success', 'Tenant atualizado.');
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
