<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

class OsDnitTourController extends Controller
{
    public function __invoke(Tenant $tenant): Response
    {
        return Inertia::render('Tenant/OsDnit/TourPreview', [
            'tenant' => $tenant->only(['id', 'slug', 'name']),
        ]);
    }
}
