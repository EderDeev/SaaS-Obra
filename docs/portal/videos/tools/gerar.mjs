// Grava um tutorial a partir do tour guiado do SigWorks e monta o MP4.
// Só usa o tour, que tem dados fictícios e não grava registros nem envia e-mails.
// Uso: node gerar.mjs roteiro-os-dnit-criar-os.json
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import ffmpeg from 'ffmpeg-static';

const AQUI = path.dirname(fileURLToPath(import.meta.url));
const BASE = process.env.SIGWORKS_URL || 'http://127.0.0.1:8000';
const LARGURA = 1600, ALTURA = 900, FOLGA = 0.5, CARTAO = 3;

const host = new URL(BASE).hostname;
if (!['127.0.0.1', 'localhost'].includes(host)) throw new Error('Grave somente no ambiente local.');

const arquivoRoteiro = path.resolve(AQUI, process.argv[2]);
const roteiro = JSON.parse(fs.readFileSync(arquivoRoteiro, 'utf8'));
const SAIDA = path.join(AQUI, 'saida', roteiro.saida);
fs.mkdirSync(SAIDA, { recursive: true });
for (const item of fs.readdirSync(SAIDA)) fs.rmSync(path.join(SAIDA, item), { recursive: true, force: true });

const ff = (...args) => execFileSync(ffmpeg, ['-hide_banner', '-loglevel', 'error', '-y', ...args], { cwd: SAIDA, stdio: ['ignore', 'inherit', 'inherit'] });
const espera = ms => new Promise(r => setTimeout(r, ms));

function duracaoWav(arquivo) {
    const b = fs.readFileSync(arquivo);
    let i = 12;
    while (i < b.length && b.toString('ascii', i, i + 4) !== 'data') i += 8 + b.readUInt32LE(i + 4);
    return b.readUInt32LE(i + 4) / b.readUInt32LE(28);
}

// Conta fictícia do Laboratório OS DNIT (docs/laboratorio-os-dnit.md). A senha fica fora do Git.
function senhaDeTeste() {
    if (process.env.SIGWORKS_VIDEO_SENHA) return process.env.SIGWORKS_VIDEO_SENHA;
    const pasta = path.resolve(AQUI, '../../../../storage/app/private/sandbox-os-dnit');
    for (const id of fs.readdirSync(pasta)) {
        const arquivo = path.join(pasta, id, 'acessos.md');
        if (!fs.existsSync(arquivo)) continue;
        const achado = fs.readFileSync(arquivo, 'utf8').match(/Senha das contas abaixo:\s*`?([^`\s]+)/);
        if (achado) return achado[1];
    }
    throw new Error('Senha do laboratório não encontrada. Defina SIGWORKS_VIDEO_SENHA.');
}

// 1. Narração
execFileSync('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', path.join(AQUI, 'narrar.ps1'), '-Roteiro', arquivoRoteiro, '-Pasta', SAIDA], { stdio: 'inherit' });
for (const seg of roteiro.segmentos) seg.duracao = duracaoWav(path.join(SAIDA, `${seg.id}.wav`));

// 2. Gravação
const navegador = await chromium.launch();
const login = await navegador.newContext({ viewport: { width: LARGURA, height: ALTURA } });
const paginaLogin = await login.newPage();
await paginaLogin.goto(`${BASE}/login`);
await paginaLogin.fill('form input[type=text]', roteiro.login.email);
await paginaLogin.fill('input[type=password]', senhaDeTeste());
await paginaLogin.click('form button.sig-btn-primary');
await paginaLogin.waitForURL(url => !url.pathname.includes('/login'), { timeout: 30000 });
const sessao = await login.storageState();
await login.close();

const cartao = await navegador.newPage({ viewport: { width: LARGURA, height: ALTURA } });
const htmlCartao = (t, s) => `<body style="margin:0;height:100vh;display:flex;flex-direction:column;justify-content:center;padding:0 140px;box-sizing:border-box;background:#0f172a;color:#fff;font-family:'Segoe UI',Arial,sans-serif"><div style="font-size:64px;font-weight:600;line-height:1.15">${t}</div><div style="font-size:30px;color:#cbd5e1;margin-top:24px">${s}</div></body>`;
await cartao.setContent(htmlCartao(roteiro.titulo, roteiro.subtitulo));
await cartao.screenshot({ path: path.join(SAIDA, 'abertura.png') });
await cartao.setContent(htmlCartao(roteiro.fim, roteiro.fimDetalhe));
await cartao.screenshot({ path: path.join(SAIDA, 'fim.png') });
await cartao.close();

const contexto = await navegador.newContext({ storageState: sessao, viewport: { width: LARGURA, height: ALTURA }, recordVideo: { dir: SAIDA, size: { width: LARGURA, height: ALTURA } } });
// O navegador automatizado não desenha o ponteiro; este marcador mostra onde o clique acontece.
await contexto.addInitScript(() => {
    addEventListener('DOMContentLoaded', () => {
        const c = document.createElement('div');
        c.style.cssText = 'position:fixed;left:0;top:0;width:22px;height:22px;margin:-11px 0 0 -11px;border-radius:50%;background:rgba(37,99,235,.45);border:2px solid #fff;box-shadow:0 0 0 1px #1d4ed8;z-index:2147483647;pointer-events:none';
        document.documentElement.appendChild(c);
        addEventListener('mousemove', e => { c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px'; }, true);
    });
});
const pagina = await contexto.newPage();
const inicio = Date.now();
const agora = () => (Date.now() - inicio) / 1000;
const irAoBotao = async alvo => {
    await alvo.waitFor();
    const caixa = await alvo.boundingBox();
    await pagina.mouse.move(caixa.x + caixa.width / 2, caixa.y + caixa.height / 2, { steps: 25 });
    await espera(250);
    await pagina.mouse.down();
    await pagina.mouse.up();
};
const proximo = pagina.locator('[aria-describedby="dnit-tour-content"] button.sig-btn-primary');
const tituloPasso = pagina.locator('#dnit-tour-title');
const textoPasso = () => pagina.locator('[aria-describedby="dnit-tour-content"] footer span').first().innerText();
const naTela = async passo => {
    for (let n = 0; n < 40; n++) {
        const atual = Number((await textoPasso()).match(/\d+/)[0]);
        if (atual === passo) return;
        if (atual > passo) throw new Error(`Passou do passo ${passo}`);
        await proximo.click();
        await pagina.waitForFunction(a => document.querySelector('[aria-describedby="dnit-tour-content"] footer span')?.innerText.includes(`Passo ${a} `), atual + 1);
    }
};

await pagina.goto(`${BASE}/t/${roteiro.login.tenant}/os-dnit`);
await pagina.waitForLoadState('networkidle');
await pagina.mouse.move(400, 300);
await espera(600);

const passoNaTela = passo => pagina.waitForFunction(a => document.querySelector('[aria-describedby="dnit-tour-content"] footer span')?.innerText.includes(`Passo ${a} `), passo);
const marcas = [];
for (const [i, seg] of roteiro.segmentos.entries()) {
    if (seg.tela === 'tour' && !await tituloPasso.count()) {
        await pagina.click('a:has-text("Iniciar tour")');
        await tituloPasso.waitFor();
    }
    if (seg.tela === 'tour') {
        await naTela(seg.passo);
        await pagina.mouse.move(LARGURA - 180, 150, { steps: 5 });
        await espera(900);
    }
    const t = agora();
    await pagina.screenshot({ path: path.join(SAIDA, `quadro-${seg.id}.png`) });
    await espera(Math.max(0, (seg.duracao + FOLGA - (agora() - t)) * 1000));
    if (seg.apontar) {
        const caixa = await pagina.locator(seg.apontar).first().boundingBox();
        await pagina.mouse.move(caixa.x + caixa.width / 2, caixa.y + caixa.height / 2, { steps: 25 });
        await espera(700);
    }
    const seguinte = roteiro.segmentos[i + 1];
    if (seg.tela === 'tour' && seguinte?.passo === seg.passo + 1) {
        await irAoBotao(proximo);
        await espera(200);
        marcas.push({ id: seg.id, inicio: t, fim: agora() });
        await passoNaTela(seguinte.passo);
        await pagina.mouse.move(LARGURA - 180, 150, { steps: 5 });
    } else {
        marcas.push({ id: seg.id, inicio: t, fim: agora() });
    }
}
await pagina.close();
const webm = await pagina.video().path();
await contexto.close();
await navegador.close();

// 3. Montagem
const partes = [];
const parte = n => { partes.push(n); return n; };
const codec = ['-c:v', 'libx264', '-preset', 'medium', '-crf', '18', '-pix_fmt', 'yuv420p', '-r', '25', '-c:a', 'aac', '-ar', '44100', '-ac', '1'];
const silencio = ['-f', 'lavfi', '-t', String(CARTAO), '-i', 'anullsrc=r=44100:cl=mono'];
ff('-loop', '1', '-t', String(CARTAO), '-i', 'abertura.png', ...silencio, ...codec, '-shortest', parte('p-abertura.mp4'));
for (const m of marcas) {
    const dur = m.fim - m.inicio;
    ff('-ss', m.inicio.toFixed(3), '-t', dur.toFixed(3), '-i', webm, '-i', `${m.id}.wav`, '-filter_complex', `[1:a]apad[a]`, '-map', '0:v', '-map', '[a]', '-t', dur.toFixed(3), ...codec, parte(`p-${m.id}.mp4`));
}
ff('-loop', '1', '-t', String(CARTAO), '-i', 'fim.png', ...silencio, ...codec, '-shortest', parte('p-fim.mp4'));
fs.writeFileSync(path.join(SAIDA, 'lista.txt'), partes.map(p => `file '${p}'`).join('\n'));
ff('-f', 'concat', '-safe', '0', '-i', 'lista.txt', '-c', 'copy', 'sem-legenda.mp4');

// 4. Legendas: cada frase ganha tempo proporcional ao tamanho, dentro do tempo da narração.
const tempoSrt = s => new Date(Math.round(s * 1000)).toISOString().slice(11, 23).replace('.', ',');
const tempoAss = s => new Date(Math.round(s * 100) * 10).toISOString().slice(12, 22);
let srt = '', n = 1, cursor = CARTAO;
let ass = `[Script Info]\nScriptType: v4.00+\nPlayResX: ${LARGURA}\nPlayResY: ${ALTURA}\nWrapStyle: 0\n\n[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, BackColour, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV\nStyle: Default,Segoe UI,32,&H00FFFFFF,&H33000000,4,8,0,2,200,200,20\n\n[Events]\nFormat: Layer, Start, End, Style, Text\n`;
for (const [i, seg] of roteiro.segmentos.entries()) {
    const frases = seg.legenda.match(/[^.]+\.\s*/g).map(f => f.trim());
    const total = frases.reduce((a, f) => a + f.length, 0);
    let t = cursor;
    for (const f of frases) {
        const d = seg.duracao * f.length / total;
        srt += `${n++}\n${tempoSrt(t)} --> ${tempoSrt(t + d)}\n${f}\n\n`;
        ass += `Dialogue: 0,${tempoAss(t)},${tempoAss(t + d)},Default,${f}\n`;
        t += d;
    }
    cursor += marcas[i].fim - marcas[i].inicio;
}
fs.writeFileSync(path.join(SAIDA, `${roteiro.saida}.srt`), srt);
fs.writeFileSync(path.join(SAIDA, 'legendas.ass'), ass);
ff('-i', 'sem-legenda.mp4', '-vf', 'ass=legendas.ass', '-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-pix_fmt', 'yuv420p', '-c:a', 'copy', '-movflags', '+faststart', `${roteiro.saida}.mp4`);

console.log(JSON.stringify({ duracao: cursor + CARTAO, marcas, video: path.join(SAIDA, `${roteiro.saida}.mp4`) }, null, 1));
