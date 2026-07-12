<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractAdditive;
use App\Models\Tenant;
use App\Support\TenantRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractAdditiveController extends Controller
{
    public function store(Request $request, Tenant $tenant, Contract $contract): RedirectResponse
    {
        $this->authorizeContract($request, $tenant, $contract);

        $isCost = in_array($request->input('type'), ['cost', 'cost_deadline'], true);
        $isDeadline = in_array($request->input('type'), ['deadline', 'cost_deadline'], true);
        $minDeadlineDate = $contract->ends_at?->toDateString() ?? 'today';

        $data = $request->validate([
            'type' => ['required', Rule::in(['cost', 'deadline', 'cost_deadline'])],
            'title' => ['required', 'string', 'max:180'],
            'motivation' => ['required', 'string', 'max:3000'],
            'amount' => [
                Rule::requiredIf($isCost),
                'nullable',
                'numeric',
                'min:0.01',
                'max:999999999999.99',
            ],
            'deadline_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:3650',
            ],
            'new_ends_at' => [
                Rule::requiredIf($isDeadline),
                'nullable',
                'date',
                "after:{$minDeadlineDate}",
            ],
            'attachment' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,zip', 'max:30720'],
        ], [
            'amount.required' => 'Informe o valor do aditivo de custo.',
            'new_ends_at.required' => 'Informe a nova data final do prazo.',
            'new_ends_at.after' => 'A nova data final deve ser posterior a vigencia atual do contrato.',
            'attachment.required' => 'Envie o documento do aditivo.',
            'attachment.mimes' => 'O documento deve ser PDF, Word, Excel, imagem ou ZIP.',
            'attachment.max' => 'O documento pode ter no maximo 30 MB.',
        ]);

        DB::transaction(function () use ($request, $tenant, $contract, $data, $isCost, $isDeadline): void {
            $contract->refresh();

            $previousTotal = $contract->total_value;
            $previousEndsAt = $contract->ends_at;
            $newTotal = $previousTotal;
            $newEndsAt = $previousEndsAt;
            $deadlineDays = null;

            if ($isCost) {
                $newTotal = (float) ($previousTotal ?? 0) + (float) $data['amount'];
            }

            if ($isDeadline) {
                $newEndsAt = $request->date('new_ends_at');
                $deadlineDays = $previousEndsAt
                    ? $previousEndsAt->copy()->startOfDay()->diffInDays($newEndsAt->copy()->startOfDay())
                    : null;
            }

            $file = $data['attachment'];
            $sequence = ((int) $contract->contractAdditives()->max('sequence_number')) + 1;
            $path = $file->store("tenant-{$tenant->id}/contracts/{$contract->id}/additives", 'public');

            $contract->contractAdditives()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $request->user()->id,
                'sequence_number' => $sequence,
                'type' => $data['type'],
                'title' => $data['title'],
                'motivation' => $data['motivation'],
                'amount' => $isCost ? $data['amount'] : null,
                'previous_total_value' => $isCost ? $previousTotal : null,
                'new_total_value' => $isCost ? $newTotal : null,
                'deadline_days' => $isDeadline ? $deadlineDays : null,
                'previous_ends_at' => $isDeadline ? $previousEndsAt : null,
                'new_ends_at' => $isDeadline ? $newEndsAt : null,
                'attachment_path' => $path,
                'attachment_original_name' => $file->getClientOriginalName(),
                'attachment_mime_type' => $file->getClientMimeType(),
                'attachment_size' => $file->getSize(),
            ]);

            $updates = [];

            if ($isCost) {
                $updates['total_value'] = $newTotal;
            }

            if ($isDeadline && $newEndsAt) {
                $updates['ends_at'] = $newEndsAt;
            }

            if ($updates !== []) {
                $contract->update($updates);
            }
        });

        return back()->with('success', 'Aditivo cadastrado com sucesso.');
    }

    public function download(Request $request, Tenant $tenant, Contract $contract, ContractAdditive $additive): StreamedResponse
    {
        $this->authorizeContract($request, $tenant, $contract);
        abort_unless((int) $additive->contract_id === (int) $contract->id, 404);
        abort_unless(Storage::disk('public')->exists($additive->attachment_path), 404);

        return Storage::disk('public')->download(
            $additive->attachment_path,
            $additive->attachment_original_name ?: "aditivo-{$additive->sequence_number}.pdf",
            ['Content-Type' => $additive->attachment_mime_type ?: 'application/octet-stream'],
        );
    }

    private function authorizeContract(Request $request, Tenant $tenant, Contract $contract): void
    {
        abort_unless((int) $contract->tenant_id === (int) $tenant->id, 404);
        abort_unless(
            $request->user()->is_platform_admin
                || TenantRoles::canManageContracts($request->user()->tenantRole($tenant)),
            403,
        );
    }
}
