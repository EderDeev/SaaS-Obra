<?php

namespace App\Support;

use App\Models\RdoDiario;

class RdoWorkflow
{
    public const PARTIES = ['construtora' => 'Construtora', 'gerenciadora' => 'Gerenciadora', 'cliente' => 'Cliente'];
    public const FILLING_PARTIES = ['construtora', 'gerenciadora'];
    public const DEFAULT = ['fill_party' => 'construtora', 'review_parties' => ['gerenciadora', 'cliente']];
    public const REVIEW_STATUSES = ['construtora' => 'em_analise_construtora', 'gerenciadora' => 'em_analise_gerenciadora', 'cliente' => 'em_aprovacao_cliente'];
    public const FILL_STATUSES = ['rascunho', 'devolvido_construtora', 'pendente_comprovacao'];

    public const MODELS = [
        'construtora_gerenciadora_cliente' => ['fill_parties' => ['construtora'], 'review_parties' => ['gerenciadora', 'cliente'], 'client_comment_only' => false],
        'gerenciadora_construtora' => ['fill_parties' => ['construtora'], 'review_parties' => ['gerenciadora'], 'client_comment_only' => false],
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
        // Null belongs to a legacy document, which must keep the original flow.
        return $rdo->workflow_snapshot ?? self::DEFAULT;
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
            'arquivado' => self::savesWithoutApproval($rdo) ? 'Salvo' : 'Aprovado e arquivado',
            default => 'Rascunho',
        };
    }
}
