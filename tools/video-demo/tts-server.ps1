# Servidor de voz (Windows SAPI). Lee peticiones JSON por stdin, una por línea:
#   {"text": "...", "out": "C:\\ruta\\archivo.wav"}
# y responde "OK" o "ERR <mensaje>" cuando el WAV está listo.
param([string]$Voice = 'Microsoft Sabina Desktop', [int]$Rate = 0)

Add-Type -AssemblyName System.Speech
[Console]::InputEncoding = [System.Text.Encoding]::UTF8
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$synth = New-Object System.Speech.Synthesis.SpeechSynthesizer
try { $synth.SelectVoice($Voice) } catch {
    $es = $synth.GetInstalledVoices() | Where-Object { $_.VoiceInfo.Culture.Name -like 'es-*' } | Select-Object -First 1
    if ($es) { $synth.SelectVoice($es.VoiceInfo.Name) }
}
$synth.Rate = $Rate
[Console]::Out.WriteLine("READY " + $synth.Voice.Name)
[Console]::Out.Flush()

while ($null -ne ($line = [Console]::In.ReadLine())) {
    try {
        $req = $line | ConvertFrom-Json
        $synth.SetOutputToWaveFile($req.out)
        $synth.Speak([string]$req.text)
        $synth.SetOutputToNull()
        [Console]::Out.WriteLine('OK')
    } catch {
        $synth.SetOutputToNull()
        [Console]::Out.WriteLine('ERR ' + $_.Exception.Message)
    }
    [Console]::Out.Flush()
}
