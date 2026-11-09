# Vídeos tutoriais

Os vídeos são gravados a partir dos tours guiados do sistema, que usam dados fictícios e não gravam registros nem enviam e-mails.

## Gerar de novo

O SigWorks precisa estar rodando em `http://127.0.0.1:8000`, com o Laboratório OS DNIT criado (ver `docs/laboratorio-os-dnit.md`). O script recusa outros endereços.

```cmd
cd docs/portal/videos/tools
npm install
npx playwright install chromium ffmpeg
node gerar.mjs roteiro-os-dnit-criar-os.json
```

O resultado fica em `tools/saida/`. Depois de revisar, copie o `.mp4` e o `.srt` para esta pasta.

- `tools/roteiro-*.json`: texto falado (`fala`), legenda (`legenda`) e passo do tour de cada trecho. Na `fala`, escreva as siglas do jeito que a voz deve pronunciar.
- `tools/gerar.mjs`: grava a tela e monta o vídeo.
- `tools/narrar.ps1`: gera a narração com a voz Microsoft Maria, do Windows.
