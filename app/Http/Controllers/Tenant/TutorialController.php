<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

class TutorialController extends Controller
{
    public function __invoke(Tenant $tenant): Response
    {
        return Inertia::render('Tenant/Tutorials/Index', [
            'tenant' => $tenant,
        ]);
    }
}
