@if (count($fields))
    @if ($layout === 'inspection_form')
        <table class="identity-table model-header-fields">
            @foreach (array_chunk($fields, 2) as $row)
                <tr>
                    @foreach ($row as $field)
                        <td class="label-right model-field-label" style="width:20%">{{ $field['label'] }}:</td>
                        <td style="width:30%">
                            @if ($field['choices'])
                                @foreach ($field['choices'] as $choice)
                                    <span class="choice model-field-choice"><span class="check">{{ $choice['checked'] ? 'X' : '' }}</span>{{ $choice['label'] }}</span>
                                @endforeach
                            @else
                                <span class="field-box model-field-value">{!! nl2br(e($field['value'])) !!}</span>
                            @endif
                        </td>
                    @endforeach
                    @if (count($row) === 1)<td style="width:20%"></td><td style="width:30%"></td>@endif
                </tr>
            @endforeach
        </table>
    @else
        @foreach (array_chunk($fields, 3) as $row)
            <div class="grid grid-three model-header-fields"><div class="row">
                @foreach ($row as $field)
                    <div class="cell cell-third"><span class="label">{{ $field['label'] }}</span><span class="value">{!! nl2br(e($field['value'])) !!}</span></div>
                @endforeach
                @for ($column = count($row); $column < 3; $column++)<div class="cell cell-third"></div>@endfor
            </div></div>
        @endforeach
    @endif
@endif
