<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OsDnitSandbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Throwable;

class SeedOsDnitSandbox extends Command
{
    protected $signature = 'os-dnit:seed-sandbox {--tenant=teste-os-dnit : Slug exclusivo de laboratório} {--viewer= : ID do administrador que visualizará os contratos}';
    protected $description = 'Cria um laboratório local isolado com 4 contratos, 120 OSs, equipes, produtos e atividades, sem enviar e-mails externos.';

    public function handle(OsDnitSandbox $sandbox): int
    {
        $connection = DB::connection();
        $this->table(['Ambiente', 'Driver', 'Host', 'Banco'], [[app()->environment(), $connection->getDriverName(), $connection->getConfig('host') ?: 'n/a', $connection->getDatabaseName()]]);
        try {
            OsDnitSandbox::assertEnvironment();
            $viewer = User::where('is_platform_admin', true)->when($this->option('viewer'), fn ($query, $id) => $query->whereKey($id))->sole();
            $password = Str::random(18).'!A7';
            $result = $sandbox->create((string)$this->option('tenant'), $viewer, $password);
            $tenant = $result['tenant'];
            $directory = 'sandbox-os-dnit/'.$tenant->id;
            if ($result['created']) {
                $content = "# Acessos do Laboratório OS DNIT\n\nDados fictícios. Uso somente no ambiente local.\n\n";
                $content .= 'URL: '.route('tenant.os-dnit.orders.index', $tenant->slug)."\n\n";
                $content .= 'Senha das contas abaixo: `'.$password."`\n\n";
                $content .= "| Conta | Perfil |\n| --- | --- |\n";
                foreach ($result['accounts'] as $account) $content .= '| '.$account['email'].' | '.$account['name']." |\n";
                $content .= "\nO gestor atua nos quatro contratos e nos três papéis do fluxo. As demais contas acessam somente seu contrato.\n";
                $content .= "O administrador da plataforma atual foi vinculado aos contratos para consulta. Sua senha e seus outros acessos foram preservados.\n";
                $content .= "Durante a carga os e-mails foram processados apenas em memória. Operações manuais posteriores usam a configuração normal da aplicação.\n";
                Storage::disk('local')->put($directory.'/acessos.md', $content);
                Storage::disk('local')->put($directory.'/relatorio.json', json_encode($result['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $report = $result['report'];
            $this->info($result['created'] ? 'Laboratório criado. Nenhum registro existente foi excluído.' : 'Laboratório já existente; dados e alterações manuais preservados.');
            $this->table(['Item', 'Quantidade'], [
                ['Contratos', count($report['contracts'])], ['Contas de exemplo', $report['users_created']], ['Produtos', $report['products']],
                ['Rodovias', $report['highways']], ['OSs', $report['orders']], ['Eventos do fluxo', $report['order_events']],
                ['Atividades', $report['activities']], ['Verificações da carga', count($report['checks'])], ['E-mails externos enviados', $report['external_emails']],
            ]);
            $this->line('Acesso: '.route('tenant.os-dnit.orders.index', $tenant->slug));
            $this->line('Credenciais locais: '.Storage::disk('local')->path($directory.'/acessos.md'));
            $this->line('Relatório: '.Storage::disk('local')->path($directory.'/relatorio.json'));
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            if ($exception instanceof \Illuminate\Validation\ValidationException) $this->line(json_encode($exception->errors(), JSON_UNESCAPED_UNICODE));
            return self::FAILURE;
        }
    }
}
