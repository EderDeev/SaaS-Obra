<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RdoSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpenSignWebhookController extends Controller
{
    public function __invoke(Request $request, RdoSignatureService $signatureService): JsonResponse
    {
        $secret = (string) config('signatures.opensign.webhook_secret');
        if ($secret !== '') {
            $provided = (string) ($request->header('X-OpenSign-Signature') ?: $request->header('X-Signature') ?: $request->query('secret'));
            abort_unless(hash_equals($secret, $provided), 403);
        }

        $signatureRequest = $signatureService->applyWebhook($request->all());

        return response()->json([
            'ok' => true,
            'matched' => $signatureRequest !== null,
        ]);
    }
}
