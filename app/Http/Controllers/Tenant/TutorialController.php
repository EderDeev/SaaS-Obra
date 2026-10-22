<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\TutorialCatalog;
use App\Support\NavigationPermissions;
use Inertia\Inertia;
use Inertia\Response;

class TutorialController extends Controller
{
    public function __invoke(Tenant $tenant): Response
    {
        abort_unless(NavigationPermissions::can(request()->user(), $tenant, NavigationPermissions::TUTORIALS), 403);
        return Inertia::render('Tenant/Tutorials/Index', [
            'tenant' => $tenant,
            'tutorials' => TutorialCatalog::all(),
        ]);
    }
}
