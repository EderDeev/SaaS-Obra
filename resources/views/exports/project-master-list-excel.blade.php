@php echo "\xEF\xBB\xBF"; @endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        table {
            border-collapse: collapse;
            font-family: Arial, sans-serif;
            font-size: 11px;
            width: 100%;
        }

        th {
            background: #0f172a;
            color: #ffffff;
            font-weight: 700;
            padding: 8px;
            text-align: left;
        }

        td {
            border: 1px solid #d9e2ef;
            padding: 6px;
            vertical-align: top;
        }

        .code {
            mso-number-format: "\@";
        }
    </style>
</head>
<body>
    <table>
        <tr>
            <td colspan="18"><strong>{{ $tenant->name }} - Lista Mestra de Projetos</strong></td>
        </tr>
        <tr>
            <td colspan="18">Gerado em {{ $generatedAt->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Código</th>
                <th>Título</th>
                <th>Sequencial</th>
                <th>Contrato</th>
                <th>Nome do contrato</th>
                <th>Código da obra</th>
                <th>Obra</th>
                <th>Disciplina</th>
                <th>Nome da disciplina</th>
                <th>Fase</th>
                <th>Nome da fase</th>
                <th>Tipo</th>
                <th>Revisão</th>
                <th>Status</th>
                <th>Arquivo</th>
                <th>Tamanho</th>
                <th>Criado em</th>
                <th>Aprovado em</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($documents as $document)
                <tr>
                    <td class="code">{{ $document['code'] }}</td>
                    <td>{{ $document['title'] }}</td>
                    <td class="code">{{ $document['document_number'] }}</td>
                    <td class="code">{{ $document['contract']['code'] }}</td>
                    <td>{{ $document['contract']['name'] }}</td>
                    <td class="code">{{ $document['obra']['codigo'] }}</td>
                    <td>{{ $document['obra']['nome'] }}</td>
                    <td>{{ $document['disciplina']['sigla'] }}</td>
                    <td>{{ $document['disciplina']['nome'] }}</td>
                    <td>{{ $document['phase']['code'] }}</td>
                    <td>{{ $document['phase']['name'] }}</td>
                    <td>{{ $document['document_type_label'] }}</td>
                    <td>{{ $document['revision'] }}</td>
                    <td>{{ $document['status_label'] }}</td>
                    <td>{{ $document['file_name'] }}</td>
                    <td>{{ $document['file_size'] }}</td>
                    <td>{{ $document['created_at'] }}</td>
                    <td>{{ $document['approved_at'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="18">Nenhum projeto encontrado para os filtros selecionados.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
