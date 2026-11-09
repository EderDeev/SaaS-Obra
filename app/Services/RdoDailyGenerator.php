<?php

namespace App\Services;

use App\Models\RdoConfiguracao;
use App\Models\RdoDiario;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class RdoDailyGenerator
{
    public function generateDue(CarbonImmutable $now): int
    {
        $created = 0;

        RdoConfiguracao::query()
            ->where('active', true)
            ->orderBy('id')
            ->each(function (RdoConfiguracao $configuration) use ($now, &$created): void {
                $localNow = $now->setTimezone($configuration->timezone);
                if ($localNow->format('H:i') < RdoConfiguracao::GENERATION_TIME) {
                    return;
                }

                if ($this->generateForConfiguration($configuration, $localNow->startOfDay(), true)) {
                    $created++;
                }
            });

        return $created;
    }

    public function generateForDate(CarbonImmutable $date, bool $automatic = true): int
    {
        $created = 0;

        RdoConfiguracao::query()
            ->where('active', true)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('end_date')
                ->orWhereDate('end_date', '>=', $date->toDateString()))
            ->orderBy('id')
            ->each(function (RdoConfiguracao $configuration) use ($date, $automatic, &$created): void {
                if (! in_array($date->dayOfWeek, array_map('intval', $configuration->generation_weekdays ?? []), true)) {
                    return;
                }

                if ($this->generateForConfiguration($configuration, $date, $automatic)) {
                    $created++;
                }
            });

        return $created;
    }

    public function generateForConfiguration(
        RdoConfiguracao $configuration,
        CarbonImmutable $date,
        bool $automatic = false,
        ?int $createdById = null,
    ): ?RdoDiario {
        if (! $configuration->active
            || $date->lt($configuration->start_date->startOfDay())
            || ($configuration->end_date && $date->gt($configuration->end_date->endOfDay()))) {
            return null;
        }

        if (! in_array($date->dayOfWeek, array_map('intval', $configuration->generation_weekdays ?? []), true)) {
            return null;
        }


        try {
            return DB::transaction(function () use ($configuration, $date, $automatic, $createdById): ?RdoDiario {
                $lockedConfiguration = RdoConfiguracao::query()
                    ->whereKey($configuration->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = RdoDiario::withTrashed()
                    ->where('tenant_id', $lockedConfiguration->tenant_id)
                    ->where('contract_id', $lockedConfiguration->contract_id)
                    ->where('obra_id', $lockedConfiguration->obra_id)
                    ->whereDate('reference_date', $date->toDateString())
                    ->first();

                if ($existing) {
                    return null;
                }

                $previous = null;
                if ($lockedConfiguration->copy_previous_day) {
                    $previous = RdoDiario::query()
                        ->where('tenant_id', $lockedConfiguration->tenant_id)
                        ->where('contract_id', $lockedConfiguration->contract_id)
                        ->where('obra_id', $lockedConfiguration->obra_id)
                        ->whereDate('reference_date', '<', $date->toDateString())
                        ->latest('reference_date')
                        ->latest('id')
                        ->first();
                }

                $lastSequence = (int) RdoDiario::withTrashed()
                    ->where('tenant_id', $lockedConfiguration->tenant_id)
                    ->where('obra_id', $lockedConfiguration->obra_id)
                    ->orderByDesc('sequence_number')
                    ->value('sequence_number');
                $sequence = $lastSequence + 1;

                $diary = RdoDiario::create([
                    'tenant_id' => $lockedConfiguration->tenant_id,
                    'rdo_configuracao_id' => $lockedConfiguration->id,
                    'contract_id' => $lockedConfiguration->contract_id,
                    'obra_id' => $lockedConfiguration->obra_id,
                    'responsible_user_id' => $lockedConfiguration->responsible_user_id,
                    'created_by_id' => $createdById,
                    'copied_from_rdo_id' => $previous?->id,
                    'sequence_number' => $sequence,
                    'code' => 'RDO-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                    'reference_date' => $date->toDateString(),
                    'status' => 'rascunho',
                    'generated_automatically' => $automatic,
                    'pdf_configuration_snapshot' => [...\App\Support\RdoPdfTemplates::forContract($lockedConfiguration->tenant, $lockedConfiguration->contract_id), ...($lockedConfiguration->report_company_id ? ['company_name' => \App\Models\Empresa::where('tenant_id', $lockedConfiguration->tenant_id)->where('contract_id', $lockedConfiguration->contract_id)->whereKey($lockedConfiguration->report_company_id)->value('nome') ?? ''] : []), 'logos' => app(\App\Services\RdoDocumentLogos::class)->snapshot($lockedConfiguration->tenant, $lockedConfiguration->contract)],
                    'workflow_snapshot' => \App\Support\RdoWorkflow::forNewDiary($lockedConfiguration->workflow),
                ]);
                if ($previous) $this->copyConfiguredSections($lockedConfiguration, $previous, $diary);
                return $diary;
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                return null;
            }

            throw $exception;
        }
    }

    private function copyConfiguredSections(RdoConfiguracao $configuration, RdoDiario $previous, RdoDiario $diary): void
    {
        $parties = \App\Support\RdoWorkflow::fillParties($diary);
        $fronts = $configuration->obras()->pluck('obras.id')->all();
        foreach ($previous->secoes()->whereIn('obra_id', $fronts)->whereIn('filling_party', $parties)->get() as $section) {
            $data = null;
            if ($section->secao === 'mao_obra' && $configuration->copy_workforce) {
                $data = array_intersect_key($section->dados, array_flip(['efetivos', 'subcontratadas']));
            } elseif ($section->secao === 'equipamentos' && $configuration->copy_equipment) {
                $data = array_intersect_key($section->dados, ['registros' => true]);
            } elseif ($section->secao === 'atividades' && $configuration->copy_pending_activities) {
                $data = ['atividades' => array_values(array_filter($section->dados['atividades'] ?? [], fn ($activity) => !in_array($activity['status'] ?? 'pendente', ['concluido', 'concluida', 'completed'], true)))];
            }
            if ($data === null) continue;
            foreach (['efetivos' => \App\Models\RdoMaoObraCadastro::class, 'subcontratadas' => \App\Models\RdoSubcontratadaCadastro::class, 'registros' => \App\Models\RdoEquipamentoCadastro::class] as $key => $model) {
                if (!isset($data[$key])) continue;
                $ids = $model::where('tenant_id', $diary->tenant_id)->where('contract_id', $diary->contract_id)->where('active', true)->pluck('id')->all();
                $data[$key] = array_intersect_key($data[$key], array_flip($ids));
            }
            \App\Support\RdoCatalogs::validate($diary, [$section->secao => $data]);
            $diary->secoes()->create(['tenant_id' => $diary->tenant_id, 'obra_id' => $section->obra_id, 'filling_party' => $section->filling_party, 'secao' => $section->secao, 'dados' => $data]);
        }
    }
}
