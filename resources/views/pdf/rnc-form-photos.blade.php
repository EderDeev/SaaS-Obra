<div class="rail-block photo-section">
    <div class="rail-label" style="top:{{ $photoPage->chunk(2)->count() * 93 + 14 }}pt">FOTOS</div>
    <div class="rail-content photos-content"><table class="photos">
        @foreach ($photoPage->chunk(2) as $photoRow)
            <tr>
                @foreach ($photoRow as $photo)
                    <td><div class="photo-frame">
                        @if ($photo['data_uri'])<img src="{{ $photo['data_uri'] }}" alt="Foto {{ $photo['position'] }}">
                        @else<div class="photo-placeholder">{{ $isPreview ? 'Registro fotográfico' : 'Imagem indisponível para exibição no PDF.' }}</div>@endif
                    </div><p class="caption"><strong>Imagem {{ $photo['position'] }}:</strong> {{ $photo['comment'] ?: ($photo['original_name'] ?: $photoLabel) }}</p></td>
                @endforeach
                @if ($photoRow->count() === 1)<td class="empty-photo"></td>@endif
            </tr>
        @endforeach
    </table></div>
</div>
