<?php

namespace Tests\Unit;

use App\Support\TutorialCatalog;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TutorialCatalogTest extends TestCase
{
    public function test_catalog_covers_the_operational_modules_with_valid_routes(): void
    {
        $guides = collect(TutorialCatalog::all());
        $expected = [
            'primeiros-passos',
            'administracao-plataforma',
            'visao-geral',
            'contratos',
            'parametrizacao',
            'usuarios-permissoes',
            'atividades',
            'orcamentos',
            'ordem-servico',
            'medicao',
            'diario-obra',
            'qualidade-rnc',
            'documentacao',
            'projetos',
            'notificacoes-perfil',
            'assistente-deming',
        ];

        $this->assertSame($expected, $guides->pluck('id')->all());
        $this->assertSame($guides->count(), $guides->pluck('id')->unique()->count());
        $this->assertEqualsCanonicalizing([
            'Comece por aqui',
            'Plataforma',
            'Gestão',
            'Programação',
            'Acompanhamento',
            'Campo',
            'Controle',
            'Ajuda',
            'Administração',
        ], $guides->pluck('group')->unique()->all());

        $guides->each(function (array $guide): void {
            $this->assertNotEmpty($guide['summary'], $guide['id']);
            $this->assertNotEmpty($guide['prerequisites'], $guide['id']);
            $this->assertGreaterThanOrEqual(4, count($guide['steps']), $guide['id']);
            $this->assertNotEmpty($guide['tips'], $guide['id']);
            $this->assertNotEmpty($guide['outcome'], $guide['id']);
            $this->assertNotEmpty($guide['screenshots'], $guide['id']);
            $this->assertIsArray($guide['videos'], $guide['id']);
            $this->assertTrue(Route::has($guide['route']), $guide['route']);

            foreach ($guide['screenshots'] as $screenshot) {
                $this->assertFileExists(public_path(ltrim($screenshot['src'], '/')), $screenshot['src']);
            }

            foreach ($guide['videos'] as $video) {
                $this->assertFileExists(public_path(ltrim($video['src'], '/')), $video['src']);
                $this->assertFileExists(public_path(ltrim($video['poster'], '/')), $video['poster']);
            }
        });

        $this->assertCount(1, $guides->firstWhere('id', 'qualidade-rnc')['videos']);
    }

    public function test_assistant_uses_the_same_catalog_and_keeps_critical_new_flows(): void
    {
        $guides = collect(TutorialCatalog::assistantGuides());

        $this->assertStringContainsString('Checklist', $guides['atividades']['tutorial']);
        $this->assertStringContainsString('LOT-001-AAAA', $guides['projetos']['tutorial']);
        $this->assertStringContainsString('permissão macro', $guides['documentacao']['tutorial']);
        $this->assertStringContainsString('reduzir quantitativo', $guides['medicao']['tutorial']);
        $this->assertStringContainsString('60.000 tokens', $guides['assistente-deming']['tutorial']);
    }
}
