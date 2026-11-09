<tr><td colspan="5" class="report-masthead">
    <table class="masthead-layout"><tr>
        <td class="report-logos">@foreach (['gerenciadora', 'construtora', 'cliente'] as $role)@if (!empty($logos[$role]))<img src="{{ $logos[$role] }}" alt="Logo {{ $role }}" style="max-width:{{ min(125, (int) (225 / max(count(array_filter($logos)), 1))) }}px">@endif @endforeach</td>
        <td class="report-heading">
            <div class="report-title">{{ $heading }}</div>
            <div class="report-meta">{{ $subtitle }}</div>
            @if (!empty($headerParty))
                <span class="report-party report-party-{{ $headerParty }}">{{ $partyLabel ?? ('Preenchimento: '.\App\Support\RdoWorkflow::PARTIES[$headerParty]) }}</span>
            @endif
        </td>
    </tr></table>
</td></tr>
