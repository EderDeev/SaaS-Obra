<?php

namespace App\Http\Controllers;

use App\Support\AccessibleTenants;
use App\Support\TenantLandingPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TenantSelectionController extends Controller
{
    public function dashboard(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->is_platform_admin) {
            return redirect()->route('platform.dashboard');
        }

        $tenants = AccessibleTenants::query($user);
        $selected = $request->session()->get('current_tenant_id');
        $tenant = $selected ? (clone $tenants)->find($selected) : null;
        if ($tenant) {
            return redirect(TenantLandingPage::url($user, $tenant));
        }

        $request->session()->forget('current_tenant_id');
        if (AccessibleTenants::hasMultiple($user)) {
            return redirect()->route('tenant-selection.index');
        }

        $tenant = $tenants->first();
        abort_unless($tenant, 403, 'Seu usuário ainda não possui vínculo ativo.');
        $request->session()->put('current_tenant_id', $tenant->id);

        return redirect(TenantLandingPage::url($user, $tenant));
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $tenants = AccessibleTenants::query($request->user())->orderBy('name')->get(['id', 'name', 'slug']);
        abort_if($tenants->isEmpty(), 403, 'Seu usuário ainda não possui vínculo ativo.');
        if ($tenants->count() === 1) {
            $tenant = $tenants->first();
            $request->session()->put('current_tenant_id', $tenant->id);

            return redirect(TenantLandingPage::url($request->user(), $tenant));
        }

        return Inertia::render('Auth/SelectTenant', [
            'tenants' => $tenants,
            'selectedTenantId' => $request->session()->get('current_tenant_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'integer']]);
        // Recheck the live authorization, even if the choice was previously listed.
        $tenant = AccessibleTenants::query($request->user())->findOrFail($data['tenant_id']);
        $request->session()->put('current_tenant_id', $tenant->id);
        $request->session()->forget('url.intended');

        return redirect(TenantLandingPage::url($request->user(), $tenant));
    }
}
