<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Obra;
use App\Models\RdoAnalise;
use App\Models\RdoConfiguracao;
use App\Models\RdoDiario;
use App\Models\RdoFiscalizacaoRegistro;
use App\Models\RdoSecaoRegistro;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RdoPdfTemplates;
use App\Support\RdoWorkflow;
use Illuminate\Database\Eloquent\Collection;

class RdoPdfPreview
{
    public function make(Tenant $tenant, Contract $contract, string $model, array $document, ?Obra $obra, ?User $actor): RdoDiario
    {
        $workflow = RdoWorkflow::forModel($model);
        $party = $workflow['fill_parties'][0];
        $actor ??= new User(['name' => 'Engenheiro responsável']);
        $obra ??= new Obra(['tenant_id' => $tenant->id, 'codigo' => '01', 'nome' => $contract->name]);
        $obra->id ??= 0;
        $reference = ($document['layout'] ?? '') === 'concremat_diary' ? $this->concrematReference() : [];
        if ($reference) {
            $contract = clone $contract;
            $contract->description = $reference['description'];
            $contract->starts_at = '2026-06-11';
            $contract->ends_at = '2027-05-11';
            $obra = clone $obra;
            $obra->nome = $reference['work_label'];
            $actor = clone $actor;
            $actor->name = 'MAGNO RIBEIRO LOPES';
            $client = clone ($contract->clienteEmpresa ?? new \App\Models\Empresa);
            $client->sigla = 'EMAP';
            $client->nome = 'FISCALIZAÇÃO EMAP';
            $contract->setRelation('clienteEmpresa', $client);
        }
        $configuration = new RdoConfiguracao(['timezone' => 'America/Sao_Paulo', 'require_photos' => $document['require_photos'] ?? true]);
        $configuration->setRelation('obras', new Collection([$obra]));
        $rdo = new RdoDiario(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'code' => 'RDO-0001',
            'reference_date' => $reference ? '2026-09-30' : today(), 'status' => 'arquivado', 'workflow_snapshot' => $workflow, 'pdf_configuration_snapshot' => RdoPdfTemplates::configuration($document)]);
        $sections = [
            'clima' => ['manha' => 'ensolarado', 'tarde' => 'nublado', 'noite' => 'chuvoso', 'precipitacao_manha_mm' => 0, 'precipitacao_tarde_mm' => 0, 'precipitacao_noite_mm' => 8.5,
                'observacoes' => 'Sem impedimentos diurnos.'],
            'mao_obra' => ['efetivos' => [101 => 6, 102 => 1], 'subcontratadas' => [301 => 3]],
            'equipamentos' => ['registros' => [201 => ['quantidade' => 1, 'situacao' => 'operando']]],
            'atividades' => ['atividades' => [['titulo' => $party === 'construtora' ? 'Execução dos serviços' : 'Acompanhamento e inspeção dos serviços',
                'ocorrencia' => $party === 'construtora' ? 'Execução dos serviços previstos para a frente de trabalho, com conferência das condições de segurança e qualidade.' : 'Inspeção das frentes de trabalho, conferência dos serviços executados e atualização dos registros de acompanhamento.'],
                ['titulo' => 'Controle de qualidade', 'ocorrencia' => 'Conferência das condições do serviço e dos registros de controle da frente.']]],
            'fotos' => ['arquivos' => [['path' => '__rdo_preview__/registro', 'comment' => 'Registro da frente de serviço', 'position' => 1]]],
            'comentarios' => [$party => 'Atividades e recursos do dia registrados. Serviços acompanhados conforme o planejamento da frente.'],
        ];
        if ($reference) $sections = $reference['sections'];
        $records = new Collection;
        foreach ($sections as $key => $data) {
            $records->push(new RdoSecaoRegistro(['tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'filling_party' => $party, 'secao' => $key, 'dados' => $data]));
        }
        $analyses = new Collection;
        foreach ($workflow['review_parties'] ?: ['gerenciadora'] as $stage) {
            $analysis = new RdoAnalise(['obra_id' => $obra->id, 'etapa' => $stage, 'decisao' => $model === 'gerenciadora' ? 'save' : 'approve',
                'comentario' => $reference ? '' : ($stage === 'cliente' ? 'Registros conferidos e aprovados pelo cliente.' : ($model === 'gerenciadora' ? 'Registro diário salvo pela gerenciadora.' : 'Informações conferidas. Serviços acompanhados e registros validados pela gerenciadora.'))]);
            $analysis->created_at = $reference ? $rdo->reference_date->copy()->setTime(17, 0) : now();
            $analysis->setRelations(['user' => $actor, 'empresa' => null, 'obra' => $obra]);
            $analyses->push($analysis);
        }
        $inspections = new Collection;
        if (in_array($model, ['construtora_gerenciadora_cliente', 'gerenciadora_construtora'], true)) {
            $inspection = new RdoFiscalizacaoRegistro(['obra_id' => $obra->id, 'version_number' => 1, 'status' => 'concluido', 'submitted_at' => now(),
                'resumo_fiscalizacao' => 'A gerenciadora conferiu as condições de execução e registrou as constatações desta frente de serviço.',
                'atividades' => [['titulo' => 'Inspeção dos serviços executados', 'ocorrencia' => 'Conferência das frentes de trabalho, das condições de segurança e dos controles de qualidade.']],
                'comentario' => 'Serviços acompanhados conforme o planejamento do dia, sem interferências na execução.',
                'clima' => ['manha' => 'ensolarado', 'tarde' => 'nublado', 'noite' => 'chuvoso', 'precipitacao_noite_mm' => 8.5, 'observacoes' => 'Chuva após o encerramento das atividades.'],
                'evidencias' => [['path' => '__rdo_preview__/registro', 'name' => 'Registro da vistoria da gerenciadora', 'mime_type' => 'image/png', 'comment' => 'Conferência das condições de execução na frente de serviço']]]);
            $inspection->setRelations(['user' => $actor, 'empresa' => null, 'obra' => $obra]);
            $inspections->push($inspection);
        }
        $rdo->setRelations(['tenant' => $tenant, 'contract' => $contract, 'obra' => $obra, 'responsible' => $actor, 'configuracao' => $configuration,
            'secoes' => $records, 'analises' => $analyses, 'versoes' => new Collection, 'fiscalizacaoRegistros' => $inspections, 'divergencias' => new Collection]);

        return $rdo;
    }

    public function reference(RdoDiario $rdo): array
    {
        return ($rdo->pdf_configuration_snapshot['layout'] ?? '') === 'concremat_diary' ? $this->concrematReference() : [];
    }

    private function concrematReference(): array
    {
        return json_decode(file_get_contents(resource_path('fixtures/rdo-concremat-preview.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function catalogs(?RdoDiario $rdo = null): array
    {
        if ($rdo && $this->reference($rdo)) return $this->reference($rdo)['catalogs'];
        return ['mao_obra' => [101 => ['descricao' => 'Equipe de campo', 'tipo' => 'direta'], 102 => ['descricao' => 'Engenheiro responsável', 'tipo' => 'indireta']],
            'equipamentos' => [201 => ['descricao' => 'Veículo de apoio']], 'subcontratadas' => [301 => ['razao_social' => 'Equipe de serviços especializados']]];
    }

    public function photo(): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }
        $image = imagecreatetruecolor(800, 450);
        imagefill($image, 0, 0, imagecolorallocate($image, 206, 226, 240));
        imagefilledrectangle($image, 0, 200, 800, 450, imagecolorallocate($image, 132, 157, 117));
        imagefilledpolygon($image, [330, 200, 470, 200, 760, 450, 40, 450], imagecolorallocate($image, 100, 107, 117));
        imagefilledpolygon($image, [397, 205, 403, 205, 410, 270, 390, 270], imagecolorallocate($image, 249, 221, 119));
        imagefilledpolygon($image, [387, 300, 413, 300, 426, 400, 374, 400], imagecolorallocate($image, 249, 221, 119));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
