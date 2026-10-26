@if (count($fields))
    @foreach ($fields as $field)
        <div style="padding:7pt; border-bottom:.5pt solid #d8dee8; word-wrap:break-word;">
            <strong>{{ $field['label'] }}</strong>
            <div>{!! nl2br(e($field['value'])) !!}</div>
        </div>
    @endforeach
@endif
