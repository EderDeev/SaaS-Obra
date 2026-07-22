<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

class ProjectMasterListExportService
{
    public function branding(Tenant $tenant, Collection $contractIds): array
    {
        $contracts = $tenant->contracts()
            ->whereIn('id', $contractIds->filter()->unique()->values())
            ->with(['gerenciadoraEmpresa', 'clienteEmpresa', 'construtoraEmpresa'])
            ->get();

        return [
            'gerenciadora' => $this->companyGroup('Gerenciadora', $contracts->pluck('gerenciadoraEmpresa')),
            'cliente' => $this->companyGroup('Cliente', $contracts->pluck('clienteEmpresa')),
            'construtora' => $this->companyGroup('Construtora', $contracts->pluck('construtoraEmpresa')),
        ];
    }

    public function spreadsheet(Tenant $tenant, Collection $documents, array $branding, mixed $generatedAt): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator($tenant->name)
            ->setTitle('Lista Mestra de Projetos')
            ->setSubject('Controle de projetos por contrato, obra, disciplina, fase e revisao');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Lista Mestra');
        $sheet->setShowGridlines(false);
        $spreadsheet->getDefaultStyle()->getFont()->setName('Aptos')->setSize(9);

        $sheet->mergeCells('A1:R1');
        $sheet->setCellValue('A1', 'LISTA MESTRA DE PROJETOS');
        $sheet->getStyle('A1:R1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '111827']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $sheet->mergeCells('A2:R2');
        $sheet->setCellValue('A2', sprintf(
            '%s  |  Gerado em %s  |  %d projeto(s)',
            $tenant->name,
            $generatedAt->timezone(config('app.timezone'))->format('d/m/Y H:i'),
            $documents->count(),
        ));
        $sheet->getStyle('A2:R2')->applyFromArray([
            'font' => ['size' => 9, 'color' => ['rgb' => '64748B']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);

        $this->applyBrandBlock($sheet, 'A', 'B', 'C', 'F', $branding['gerenciadora']);
        $this->applyBrandBlock($sheet, 'G', 'H', 'I', 'L', $branding['cliente']);
        $this->applyBrandBlock($sheet, 'M', 'N', 'O', 'R', $branding['construtora']);

        $headers = [
            'Codigo',
            'Titulo',
            'Sequencial',
            'Contrato',
            'Nome do contrato',
            'Cod. obra',
            'Obra',
            'Disciplina',
            'Nome da disciplina',
            'Fase',
            'Nome da fase',
            'Tipo',
            'Revisao',
            'Status',
            'Arquivo',
            'Tamanho',
            'Criado em',
            'Aprovado em',
        ];
        $sheet->fromArray($headers, null, 'A9');
        $sheet->getStyle('A9:R9')->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '334155']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2F7']],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(9)->setRowHeight(24);

        $row = 10;
        foreach ($documents as $document) {
            $values = [
                $document['code'] ?: '-',
                $document['title'] ?: '-',
                $document['document_number'] ?: '-',
                $document['contract']['code'] ?: '-',
                $document['contract']['name'] ?: '-',
                $document['obra']['codigo'] ?: '-',
                $document['obra']['nome'] ?: '-',
                $document['disciplina']['sigla'] ?: '-',
                $document['disciplina']['nome'] ?: '-',
                $document['phase']['code'] ?: '-',
                $document['phase']['name'] ?: '-',
                $document['document_type_label'] ?: '-',
                $document['revision'] ?: '-',
                $document['status_label'] ?: '-',
                $document['file_name'] ?: '-',
                $document['file_size'] ?: '-',
                $document['created_at'] ?: '-',
                $document['approved_at'] ?: '-',
            ];

            $sheet->fromArray($values, null, "A{$row}");
            foreach (['A', 'C', 'D', 'F', 'H', 'J', 'M'] as $column) {
                $sheet->setCellValueExplicit("{$column}{$row}", (string) $sheet->getCell("{$column}{$row}")->getValue(), DataType::TYPE_STRING);
            }

            $fill = $row % 2 === 0 ? 'FFFFFF' : 'F8FAFC';
            $sheet->getStyle("A{$row}:R{$row}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E2E8F0']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $this->applyStatusStyle($sheet, "N{$row}", $document['status']);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;
        }

        $lastRow = max($row - 1, 9);
        $sheet->freezePane('A10');
        $sheet->setAutoFilter("A9:R{$lastRow}");
        if ($lastRow >= 10) {
            $sheet->getStyle("A10:R{$lastRow}")->getAlignment()->setWrapText(false);
        }

        $widths = [
            'A' => 24, 'B' => 34, 'C' => 14, 'D' => 13, 'E' => 24, 'F' => 12,
            'G' => 22, 'H' => 12, 'I' => 22, 'J' => 10, 'K' => 20, 'L' => 16,
            'M' => 11, 'N' => 16, 'O' => 34, 'P' => 13, 'Q' => 18, 'R' => 18,
        ];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getPageSetup()
            ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.35)->setRight(0.25)->setBottom(0.4)->setLeft(0.25);
        $sheet->getHeaderFooter()->setOddFooter('&L'.$tenant->name.'&CLista Mestra&RPagina &P de &N');
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 9);

        return $spreadsheet;
    }

    private function companyGroup(string $label, Collection $companies): array
    {
        $companies = $companies->filter()->unique('id')->values();
        /** @var Empresa|null $primary */
        $primary = $companies->first();
        $additional = max($companies->count() - 1, 0);

        return [
            'label' => $label,
            'name' => $primary?->nome ?? 'Nao vinculada',
            'display_name' => $primary
                ? $primary->nome.($additional > 0 ? " + {$additional} empresa(s)" : '')
                : 'Nao vinculada',
            'sigla' => $primary?->sigla,
            'additional_count' => $additional,
            'logo_data_uri' => $this->companyLogoDataUri($primary),
        ];
    }

    private function companyLogoDataUri(?Empresa $empresa): ?string
    {
        if (! $empresa?->logo_path || ! Storage::disk('public')->exists($empresa->logo_path)) {
            return null;
        }

        $image = @imagecreatefromstring((string) Storage::disk('public')->get($empresa->logo_path));
        if (! $image) {
            return null;
        }

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return $png ? 'data:image/png;base64,'.base64_encode($png) : null;
    }

    private function applyBrandBlock($sheet, string $logoStart, string $logoEnd, string $nameStart, string $end, array $company): void
    {
        $sheet->mergeCells("{$logoStart}4:{$end}4");
        $sheet->setCellValue("{$logoStart}4", mb_strtoupper($company['label']));
        $sheet->mergeCells("{$logoStart}5:{$logoEnd}7");
        $sheet->mergeCells("{$nameStart}5:{$end}7");
        $sheet->setCellValue("{$nameStart}5", $company['display_name']);

        $sheet->getStyle("{$logoStart}4:{$end}7")->applyFromArray([
            'borders' => [
                'outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']],
            ],
        ]);
        $sheet->getStyle("{$logoStart}4:{$end}4")->applyFromArray([
            'font' => ['bold' => true, 'size' => 8, 'color' => ['rgb' => '64748B']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("{$nameStart}5:{$end}7")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '1E293B']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(18);
        $sheet->getRowDimension(5)->setRowHeight(17);
        $sheet->getRowDimension(6)->setRowHeight(17);
        $sheet->getRowDimension(7)->setRowHeight(17);

        if (! $company['logo_data_uri']) {
            $sheet->setCellValue("{$logoStart}5", $company['sigla'] ?: '-');
            $sheet->getStyle("{$logoStart}5:{$logoEnd}7")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '2563EB']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);

            return;
        }

        $image = @imagecreatefromstring((string) base64_decode(substr($company['logo_data_uri'], strpos($company['logo_data_uri'], ',') + 1)));
        if (! $image) {
            return;
        }

        $drawing = new MemoryDrawing();
        $drawing->setName($company['name']);
        $drawing->setDescription($company['label']);
        $drawing->setImageResource($image);
        $drawing->setRenderingFunction(MemoryDrawing::RENDERING_PNG);
        $drawing->setMimeType(MemoryDrawing::MIMETYPE_PNG);
        $drawing->setHeight(38);
        $drawing->setCoordinates("{$logoStart}5");
        $drawing->setOffsetX(8);
        $drawing->setOffsetY(6);
        $drawing->setWorksheet($sheet);
    }

    private function applyStatusStyle($sheet, string $cell, ?string $status): void
    {
        [$background, $foreground] = match ($status) {
            'em_aprovacao' => ['FFF4D6', 'A16207'],
            'ativo' => ['E8F7EE', '166534'],
            'inativo', 'reprovado' => ['FDECEC', 'B91C1C'],
            default => ['EAF2FF', '1D4ED8'],
        };

        $sheet->getStyle($cell)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => $foreground]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $background]],
        ]);
    }
}
