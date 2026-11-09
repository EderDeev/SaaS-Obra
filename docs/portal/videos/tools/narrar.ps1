# Gera um WAV por segmento do roteiro com a voz do Windows.
param([string]$Roteiro, [string]$Pasta)
Add-Type -AssemblyName System.Speech
$dados = Get-Content -Raw -Encoding UTF8 $Roteiro | ConvertFrom-Json
foreach ($seg in $dados.segmentos) {
    $voz = New-Object System.Speech.Synthesis.SpeechSynthesizer
    $voz.SelectVoice('Microsoft Maria Desktop')
    $voz.Rate = 0
    $voz.SetOutputToWaveFile((Join-Path $Pasta ($seg.id + '.wav')))
    $voz.Speak($seg.fala)
    $voz.Dispose()
}
