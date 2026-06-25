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
                $generationTime = substr((string) $configuration->generation_time, 0, 5);

                if ($localNow->format('H:i') < $generationTime) {
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
                if (! in_array($date->dayOfWeek, $configuration->generation_weekdays ?? [], true)) {
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

        if (! in_array($date->dayOfWeek, $configuration->generation_weekdays ?? [], true)) {
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

                return RdoDiario::create([
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
                ]);
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                return null;
            }

            throw $exception;
        }
    }
}
