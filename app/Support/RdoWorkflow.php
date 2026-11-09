<?php

namespace App\Support;

use App\Models\RdoDiario;
use App\Models\RdoFiscalizacaoRegistro;

class RdoWorkflow
{
    public const PARTIES = ['construtora' => 'Construtora', 'gerenciadora' => 'Gerenciadora', 'cliente' => 'Cliente'];
    public const FILLING_PARTIES = ['construtora', 'gerenciadora'];
    public const DEFAULT = ['fill_party' => 'construtora', 'review_parties' => ['gerenciadora', 'cliente']];
    public const REVIEW_STATUSES = ['construtora' => 'em_analise_construtora', 'gerenciadora' => 'em_analise_gerenciadora', 'cliente' => 'em_aprovacao_cliente'];
    public const FILL_STATUSES = ['rascunho', 'devolvido_construtora', 'pendente_comprovacao'];

    public const MODELS = [
        'construtora_gerenciadora_cliente' => ['fill_parties' => self::FILLING_PARTIES, 'review_parties' => ['gerenciadora', 'cliente'], 'client_comment_only' => false],
        'gerenciadora_construtora' => ['fill_parties' => self::FILLING_PARTIES, 'review_parties' => ['gerenciadora'], 'client_comment_only' => false],
        'gerenciadora_cliente' => ['fill_parties' => ['gerenciadora'], 'review_parties' => ['cliente'], 'client_comment_only' => false],
        'gerenciadora' => ['fill_parties' => ['gerenciadora'], 'review_parties' => [], 'client_comment_only' => false],
    ];

    public static function forModel(string $model): array
    {
        return ['model' => $model, ...self::MODELS[$model]];
    }

    public static function savesWithoutApproval(RdoDiario $rdo): bool
    {
        return (self::definition($rdo)['model'] ?? null) === 'gerenciadora';
    }

    public static function definition(RdoDiario $rdo): array
    {
        $workflow = $rdo->workflow_snapshot ?? self::DEFAULT;

        // An unsubmitted draft can adopt the current model. Submitted versions keep their recorded flow.
        return $rdo->status === 'rascunho' && (int) $rdo->current_version === 0
            ? self::forNewDiary($workflow)
            : $workflow;
    }

    public static function forNewDiary(?array $workflow): array
    {
        $workflow ??= self::DEFAULT;
        $model = $workflow['model'] ?? null;
        if (in_array($model, ['construtora_gerenciadora_cliente', 'gerenciadora_construtora'], true)) {
            $workflow['fill_parties'] = self::MODELS[$model]['fill_parties'];
        }

        return $workflow;
    }

    public static function canSetHoliday(RdoDiario $rdo, string $party): bool
    {
        // The holiday applies to the entire diary. A second party must not overwrite it during sync.
        return self::fillParty($rdo) === $party;
    }

    public static function hasParallelManagerFilling(RdoDiario $rdo): bool
    {
        return in_array(self::definition($rdo)['model'] ?? null, ['construtora_gerenciadora_cliente', 'gerenciadora_construtora'], true);
    }

    public static function fieldParties(RdoDiario $rdo): array
    {
        return self::hasParallelManagerFilling($rdo) ? self::FILLING_PARTIES : self::fillParties($rdo);
    }

    public static function inspectionFieldSections(RdoFiscalizacaoRegistro $record): array
    {
        $activities = collect($record->atividades ?? []);
        if ($activities->isEmpty() && filled($record->resumo_fiscalizacao)) {
            $activities->push(['titulo' => 'Registro da gerenciadora', 'ocorrencia' => $record->resumo_fiscalizacao]);
        }
        foreach (['servicos_atestados' => 'Serviços atestados', 'ocorrencias' => 'Ocorrências constatadas', 'orientacoes' => 'Orientações'] as $field => $title) {
            if (filled($record->{$field})) {
                $activities->push(['titulo' => $title, 'ocorrencia' => $record->{$field}]);
            }
        }

        return array_filter([
            'clima' => $record->clima,
            'atividades' => $activities->isNotEmpty() ? ['atividades' => $activities->values()->all()] : null,
            'comentarios' => filled($record->comentario) ? ['gerenciadora' => $record->comentario] : null,
        ]);
    }

    public static function managerFillsDuringReview(RdoDiario $rdo): bool
    {
        return $rdo->status === self::REVIEW_STATUSES['gerenciadora'] && self::hasParallelManagerFilling($rdo);
    }

    public static function isManagerFieldRecord(RdoDiario $rdo, string $party): bool
    {
        return $party === 'gerenciadora' && self::hasParallelManagerFilling($rdo);
    }

    public static function inspectionVersion(RdoDiario $rdo): int
    {
        $version = (int) $rdo->current_version;

        return self::hasParallelManagerFilling($rdo) && in_array($rdo->status, self::FILL_STATUSES, true)
            ? $version + 1
            : max(1, $version);
    }

    public static function submissionParties(RdoDiario $rdo): array
    {
        return self::hasParallelManagerFilling($rdo) ? ['construtora'] : self::fillParties($rdo);
    }

    public static function fillParty(RdoDiario $rdo): string
    {
        return self::fillParties($rdo)[0];
    }

    public static function fillParties(RdoDiario $rdo): array
    {
        $definition = self::definition($rdo);
        return $definition['fill_parties'] ?? [$definition['fill_party']];
    }

    public static function sections(RdoDiario $rdo, string $party): \Illuminate\Support\Collection
    {
        return $rdo->secoes->filter(fn ($section) => ($section->filling_party ?? 'construtora') === $party);
    }

    public static function reviews(RdoDiario $rdo): array
    {
        return self::definition($rdo)['review_parties'];
    }

    public static function clientCommentOnly(RdoDiario $rdo): bool
    {
        return self::definition($rdo)['client_comment_only'] ?? false;
    }

    public static function parties(RdoDiario $rdo): array
    {
        return array_values(array_unique([...self::fillParties($rdo), ...self::reviews($rdo)]));
    }

    public static function activeParty(RdoDiario $rdo, ?string $status = null): ?string
    {
        $status ??= $rdo->status;
        if (in_array($status, self::FILL_STATUSES, true)) return self::fillParty($rdo);
        foreach (self::reviews($rdo) as $party) if (self::REVIEW_STATUSES[$party] === $status) return $party;
        return null;
    }

    public static function isReview(string $status): bool
    {
        return in_array($status, self::REVIEW_STATUSES, true);
    }

    public static function statusLabel(RdoDiario $rdo, ?string $status = null): string
    {
        $status ??= $rdo->status;
        if ($status === 'devolvido_construtora') return 'Devolvido para ajustes: '.implode(' e ', array_map(fn ($party) => self::PARTIES[$party], self::fillParties($rdo)));
        return match ($status) {
            'em_analise_construtora' => 'Em análise pela construtora',
            'em_analise_gerenciadora' => 'Em análise pela gerenciadora',
            'em_aprovacao_cliente' => self::clientCommentOnly($rdo) ? 'Aguardando comentário do cliente' : 'Aguardando manifestação do cliente',
            'pendente_comprovacao' => 'Pendente de comprovação',
            'arquivado' => self::savesWithoutApproval($rdo) ? 'Finalizado' : 'Aprovado e arquivado',
            default => 'Rascunho',
        };
    }
}
