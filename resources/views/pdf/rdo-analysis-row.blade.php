                    <tr>
                        <td>{{ $fmtDateTime($analysis->created_at) }}</td>
                        <td>{{ match ($analysis->etapa) { 'gerenciadora' => 'Gerenciadora', 'cliente' => 'Cliente', default => 'Construtora' } }}</td>
                        <td>{{ match ($analysis->decisao) { 'save' => 'Salvo sem aprovação', 'approve' => 'Aprovado', 'approve_with_reservations' => 'Aprovado com ressalvas', 'return' => 'Devolvido', default => 'Enviado para análise' } }}</td>
                        <td>{{ $analysis->user?->name ?: '-' }}</td>
                        <td>{{ $analysis->obra ? "{$analysis->obra->codigo} - {$analysis->obra->nome}" : '-' }}</td>
                        <td>
                            {{ $analysis->comentario ?: '-' }}
                            @if (data_get($analysis->detalhes, 'reservation.obligation'))
                                <br><strong>Ressalva:</strong> {{ data_get($analysis->detalhes, 'reservation.obligation') }}
                                <br><strong>Responsável:</strong> {{ data_get($analysis->detalhes, 'reservation.responsible') ?: '-' }}
                                <br><strong>Prazo:</strong> {{ data_get($analysis->detalhes, 'reservation.due_date') ?: '-' }}
                            @endif
                        </td>
                    </tr>
