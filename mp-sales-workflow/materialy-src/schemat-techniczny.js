// Schemat TECHNICZNY P3 → .drawio (edytowalny) + .svg (→ PDF). Pokazuje pełną
// drogę żądania: 4 kanały wejścia → brama pochodzenia zdarzeń → pipeline 9
// działów (25 par Agent+Krytyk, bramka jakości po każdym dziale) → jedna
// transakcja zapisu → wyjście po COMMIT.
//
// Źródło prawdy: kod wtyczki v1.0.0 (DB_VERSION 0.3.0):
//   - liczby par: MP_SW_Pipeline_Factory::make() → get_pairs() (3,5,2,3,2,2,3,3,2)
//   - macierz kanałów: MP_SW_Origin::matrix()
//   - transakcja: wyłącznie Dział 8; kolejka e-mail rusza PO COMMIT (Dział 9)
// Użycie: node schemat-techniczny.js out/schemat-techniczny
const fs = require('fs');
const OUT = process.argv[2] || 'out/schemat-techniczny';

// Kanały wejścia — [tytuł, źródło, dozwolone typy zdarzeń]
const channels = [
  { id: 'ch1', t: 'Hak z Pluginu 1', s: 'mp_lead_created', e: 'lead.created', c: '#2563eb', f: '#eef4ff' },
  { id: 'ch2', t: 'Hak z Pluginu 2', s: 'mp_offer_approved', e: 'offer.approved · status.change→offer_draft', c: '#2563eb', f: '#eef4ff' },
  { id: 'ch3', t: 'Panel handlowca', s: 'admin-ajax.php · mp_sw_event', e: 'status.change · dashboard.view', c: '#7c3aed', f: '#f3e8ff' },
  { id: 'ch4', t: 'Harmonogram', s: 'cron mp_sw_sweep_tasks', e: 'task.due', c: '#f59e0b', f: '#fef3c7' },
];

// Działy — [nr, nazwa, par, opis, akcent]
const deps = [
  [1, 'Brama i kontrakt zdarzenia', 3, 'Walidacja koperty, powtarzalny event_id, trace_id.', 'gate'],
  [2, 'Strzał odczytu — BD-1', 5, 'JEDYNY odczyt: proces, role, zespół, lead (P1), oferta (P2).', 'read'],
  [3, 'Uprawnienia i zakres roli', 2, 'WŁASNE / ZESPÓŁ / WSZYSTKIE; cudzy proces = 404.', 'sec'],
  [4, 'Przypisanie handlowca', 3, 'Dobór po kraju i języku; brak dopasowania → manager.', 'calc'],
  [5, 'Maszyna statusów', 2, 'Dozwolone przejścia; blokada wersji (lock_version).', 'calc'],
  [6, 'Zadania follow-up', 2, 'Plan d+3 / d+7 z wartownikiem statusu; jedno otwarte na typ.', 'calc'],
  [7, 'Powiadomienia e-mail', 3, 'Treść z szablonu, adres z bazy, podpisany link do PDF.', 'calc'],
  [8, 'Zapis — jedna transakcja', 3, 'START → zapisy → COMMIT. Wartownik sprawdzany w UPDATE.', 'write'],
  [9, 'Wyjście i kolejka', 2, 'Odpowiedź + kolejka e-mail uruchamiana PO COMMIT.', 'out'],
];
const DC = {
  gate: { f: '#eef4ff', s: '#2563eb' },
  read: { f: '#e0f2fe', s: '#0284c7' },
  sec: { f: '#fee2e2', s: '#dc2626' },
  calc: { f: '#f8fafc', s: '#94a3b8' },
  write: { f: '#f3e8ff', s: '#7c3aed' },
  out: { f: '#e7f8f1', s: '#10b981' },
};

function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function escA(s) { return esc(s).replace(/\n/g, '&#10;'); }
function wrap(s, max) { const w = s.split(' '); const o = []; let c = ''; for (const x of w) { if ((c + ' ' + x).trim().length > max) { o.push(c); c = x; } else c = (c + ' ' + x).trim(); } if (c) o.push(c); return o; }

/* ---------- geometria ---------- */
const PW = 1300, PH = 1010;
const X0 = 40, FULLW = PW - 80;
const CHW = 295, CHH = 84, CHGAP = 18, CHY = 108;
const GATEY = 218, GATEH = 74;
const DEPW = 390, DEPH = 108, DEPGX = 25, DEPGY = 18, DEPY0 = 326;
const TXY = 706, TXH = 108;
const OUTY = 852, OUTH = 96;

const depPos = (i) => ({ x: X0 + (i % 3) * (DEPW + DEPGX), y: DEPY0 + Math.floor(i / 3) * (DEPH + DEPGY) });

/* ============================ SVG (→ PDF) ============================ */
let g = `<svg xmlns="http://www.w3.org/2000/svg" width="${PW}" height="${PH}" viewBox="0 0 ${PW} ${PH}" font-family="DejaVu Sans, Arial, sans-serif">`;
g += `<rect width="${PW}" height="${PH}" fill="#ffffff"/>`;
g += `<defs><marker id="arr" markerWidth="12" markerHeight="12" refX="9" refY="5" orient="auto"><path d="M1 1 L9 5 L1 9 z" fill="#94a3b8"/></marker>`;
g += `<marker id="arrR" markerWidth="12" markerHeight="12" refX="9" refY="5" orient="auto"><path d="M1 1 L9 5 L1 9 z" fill="#dc2626"/></marker></defs>`;
g += `<text x="${X0}" y="42" font-size="20" font-weight="bold" fill="#1f2a44">Schemat techniczny — MP Sales Workflow (LP.3)</text>`;
g += `<text x="${X0}" y="66" font-size="12" fill="#64748b">Pipeline 9 działów · 25 par Agent+Krytyk · bramka jakości po każdym dziale · 1 odczyt, 1 transakcja zapisu, 0 wywołań sieciowych w żądaniu</text>`;
g += `<text x="${X0}" y="88" font-size="11" fill="#94a3b8">Odrzucenie przez krytyka albo bramkę zatrzymuje cały przebieg — nic nie zostaje zapisane (transakcja jeszcze się nie zaczęła).</text>`;

// --- kanały wejścia ---
channels.forEach((ch, i) => {
  const x = X0 + i * (CHW + CHGAP);
  g += `<rect x="${x}" y="${CHY}" width="${CHW}" height="${CHH}" rx="9" fill="${ch.f}" stroke="${ch.c}" stroke-width="1.5"/>`;
  g += `<text x="${x + 14}" y="${CHY + 24}" font-size="12.5" font-weight="bold" fill="#1f2a44">${esc(ch.t)}</text>`;
  g += `<text x="${x + 14}" y="${CHY + 44}" font-size="10" fill="${ch.c}" font-family="monospace">${esc(ch.s)}</text>`;
  wrap(ch.e, 44).forEach((ln, k) => { g += `<text x="${x + 14}" y="${CHY + 62 + k * 13}" font-size="9.5" fill="#64748b">${esc(ln)}</text>`; });
  g += `<path d="M${x + CHW / 2} ${CHY + CHH} L${x + CHW / 2} ${GATEY}" fill="none" stroke="#94a3b8" stroke-width="1.6" marker-end="url(#arr)"/>`;
});

// --- brama pochodzenia ---
g += `<rect x="${X0}" y="${GATEY}" width="${FULLW}" height="${GATEH}" rx="9" fill="#fff1f2" stroke="#dc2626" stroke-width="1.8"/>`;
g += `<rect x="${X0}" y="${GATEY}" width="6" height="${GATEH}" rx="3" fill="#dc2626"/>`;
g += `<text x="${X0 + 20}" y="${GATEY + 27}" font-size="13.5" font-weight="bold" fill="#7f1d1d">BRAMA POCHODZENIA ZDARZEŃ — macierz typ ↔ kanał (domknięta domyślnie)</text>`;
g += `<text x="${X0 + 20}" y="${GATEY + 48}" font-size="10.5" fill="#991b1b">Typ zdarzenia dozwolony tylko z przypisanego mu kanału. Nieznany typ nie ma żadnego dozwolonego źródła.</text>`;
g += `<text x="${X0 + 20}" y="${GATEY + 65}" font-size="10.5" fill="#991b1b">Odmowa → kod MP3-Exxx + wpis do dziennika technicznego (error_log). Zero zapisów w bazie.</text>`;
g += `<text x="${PW - X0 - 14}" y="${GATEY + 44}" font-size="11" fill="#dc2626" text-anchor="end" font-weight="bold">MP3-E110 / E111</text>`;
g += `<path d="M${PW / 2} ${GATEY + GATEH} L${PW / 2} ${DEPY0 - 10}" fill="none" stroke="#94a3b8" stroke-width="1.8" marker-end="url(#arr)"/>`;

// --- działy ---
deps.forEach(([n, label, pairs, desc, kind], i) => {
  const { x, y } = depPos(i), col = DC[kind];
  g += `<rect x="${x}" y="${y}" width="${DEPW}" height="${DEPH}" rx="9" fill="${col.f}" stroke="${col.s}" stroke-width="1.5"/>`;
  g += `<circle cx="${x + 26}" cy="${y + 28}" r="15" fill="${col.s}"/><text x="${x + 26}" y="${y + 33}" font-size="13" font-weight="bold" fill="#ffffff" text-anchor="middle">${n}</text>`;
  g += `<text x="${x + 50}" y="${y + 26}" font-size="12.5" font-weight="bold" fill="#1f2a44">${esc(label)}</text>`;
  g += `<text x="${x + 50}" y="${y + 43}" font-size="10" fill="#64748b">${pairs} ${pairs === 1 ? 'para' : pairs < 5 ? 'pary' : 'par'} Agent+Krytyk · bramka jakości</text>`;
  wrap(desc, 58).forEach((ln, k) => { g += `<text x="${x + 16}" y="${y + 70 + k * 14}" font-size="10.5" fill="#334155">${esc(ln)}</text>`; });
  if ((i + 1) % 3 !== 0 && i < deps.length - 1) {
    g += `<path d="M${x + DEPW} ${y + DEPH / 2} L${x + DEPW + DEPGX} ${y + DEPH / 2}" fill="none" stroke="#94a3b8" stroke-width="1.6" marker-end="url(#arr)"/>`;
  }
});
// zawijanie wierszy (koniec rzędu → początek następnego)
[0, 1].forEach((r) => {
  const yEnd = DEPY0 + r * (DEPH + DEPGY) + DEPH;
  const yNext = DEPY0 + (r + 1) * (DEPH + DEPGY);
  g += `<path d="M${X0 + 2 * (DEPW + DEPGX) + DEPW - 40} ${yEnd} L${X0 + 2 * (DEPW + DEPGX) + DEPW - 40} ${yEnd + 9} L${X0 + 40} ${yEnd + 9} L${X0 + 40} ${yNext}" fill="none" stroke="#cbd5e1" stroke-width="1.4" stroke-dasharray="4 3" marker-end="url(#arr)"/>`;
});

// --- transakcja / BD-1 ---
g += `<rect x="${X0}" y="${TXY}" width="${FULLW}" height="${TXH}" rx="9" fill="#faf5ff" stroke="#7c3aed" stroke-width="1.8"/>`;
g += `<text x="${X0 + 20}" y="${TXY + 26}" font-size="13.5" font-weight="bold" fill="#4c1d95">JEDNA TRANSAKCJA (tylko Dział 8) → BD-1</text>`;
const tbls = ['wp_mp_sw_flow', 'wp_mp_sw_tasks', 'wp_mp_sw_notifications', 'wp_mp_sw_activity', 'wp_mp_sw_events'];
tbls.forEach((t, i) => {
  const bw = 218, bx = X0 + 20 + i * (bw + 12);
  g += `<rect x="${bx}" y="${TXY + 40}" width="${bw}" height="26" rx="5" fill="#ffffff" stroke="#c4b5fd"/>`;
  g += `<text x="${bx + bw / 2}" y="${TXY + 57}" font-size="10" fill="#5b21b6" text-anchor="middle" font-family="monospace">${esc(t)}</text>`;
});
g += `<text x="${X0 + 20}" y="${TXY + 88}" font-size="10.5" fill="#6d28d9">Wartownik statusu i lock_version sprawdzane WEWNĄTRZ transakcji (WHERE status = … AND lock_version = …) — konflikt = 409, bez nadpisania.</text>`;
g += `<path d="M${PW / 2} ${DEPY0 + 3 * (DEPH + DEPGY) - DEPGY} L${PW / 2} ${TXY}" fill="none" stroke="#94a3b8" stroke-width="1.8" marker-end="url(#arr)"/>`;
g += `<path d="M${PW / 2} ${TXY + TXH} L${PW / 2} ${OUTY}" fill="none" stroke="#10b981" stroke-width="2" marker-end="url(#arr)"/>`;
g += `<text x="${PW / 2 + 12}" y="${OUTY - 12}" font-size="11" font-weight="bold" fill="#059669">COMMIT</text>`;

// --- wyjście po COMMIT ---
const outs = [
  { t: 'Kolejka e-mail', d: 'wp_mail() dopiero po COMMIT · limit 200/h · ponowienia z odstępem', c: '#10b981', f: '#e7f8f1' },
  { t: 'Podpisany link do PDF', d: 'HMAC-SHA256 · ważność 14 dni · jedyne publiczne wejście', c: '#0284c7', f: '#e0f2fe' },
  { t: 'Dziennik aktywności', d: 'kto, kiedy, co zmienił · bez adresu e-mail i bez IP', c: '#334155', f: '#f1f5f9' },
];
outs.forEach((o, i) => {
  const bw = (FULLW - 2 * 20) / 3, x = X0 + i * (bw + 20);
  g += `<rect x="${x}" y="${OUTY}" width="${bw}" height="${OUTH}" rx="9" fill="${o.f}" stroke="${o.c}" stroke-width="1.5"/>`;
  g += `<text x="${x + 14}" y="${OUTY + 26}" font-size="12.5" font-weight="bold" fill="#1f2a44">${esc(o.t)}</text>`;
  wrap(o.d, 48).forEach((ln, k) => { g += `<text x="${x + 14}" y="${OUTY + 48 + k * 14}" font-size="10.5" fill="#334155">${esc(ln)}</text>`; });
});

// --- legenda ---
g += `<text x="${X0}" y="${PH - 16}" font-size="10" fill="#64748b">Kolory działów: niebieski = brama · błękitny = odczyt · czerwony = kontrola dostępu · fioletowy = zapis · zielony = wyjście · szary = obliczenia bez dostępu do bazy.</text>`;
g += `</svg>`;
fs.writeFileSync(OUT + '.svg', g);

/* ============================ .drawio ============================ */
function dbox(id, val, x, y, w, h, style) { return `<mxCell id="${id}" value="${escA(val)}" style="${style}" vertex="1" parent="1"><mxGeometry x="${x}" y="${y}" width="${w}" height="${h}" as="geometry"/></mxCell>`; }
function dedge(id, src, tgt, label) { return `<mxCell id="${id}" value="${escA(label || '')}" style="edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;endArrow=block;strokeColor=#94a3b8;fontSize=10;" edge="1" parent="1" source="${src}" target="${tgt}"><mxGeometry relative="1" as="geometry"/></mxCell>`; }

let c = dbox('t1', 'Schemat techniczny — MP Sales Workflow (LP.3)', X0, 16, 900, 30, 'text;html=1;fontSize=18;fontStyle=1;fontColor=#1f2a44;');
c += dbox('t2', 'Pipeline 9 działów · 25 par Agent+Krytyk · bramka jakości po każdym dziale · 1 odczyt, 1 transakcja zapisu, 0 wywołań sieciowych', X0, 46, 1100, 20, 'text;html=1;fontSize=11;fontColor=#64748b;');

channels.forEach((ch, i) => {
  const x = X0 + i * (CHW + CHGAP);
  c += dbox(ch.id, `${ch.t}\n${ch.s}\n\n${ch.e}`, x, CHY, CHW, CHH, `rounded=1;whiteSpace=wrap;html=1;fillColor=${ch.f};strokeColor=${ch.c};fontColor=#1f2a44;align=left;verticalAlign=top;spacingLeft=10;spacingTop=6;fontSize=11;`);
  c += dedge('e_' + ch.id, ch.id, 'gate');
});
c += dbox('gate', 'BRAMA POCHODZENIA ZDARZEŃ — macierz typ ↔ kanał (domknięta domyślnie)\n\nTyp zdarzenia dozwolony tylko z przypisanego mu kanału; nieznany typ nie ma żadnego źródła.\nOdmowa → kod MP3-Exxx + dziennik techniczny (error_log). Zero zapisów w bazie.', X0, GATEY, FULLW, GATEH, 'rounded=1;whiteSpace=wrap;html=1;fillColor=#fff1f2;strokeColor=#dc2626;fontColor=#7f1d1d;align=left;verticalAlign=top;spacingLeft=14;spacingTop=6;fontSize=11;fontStyle=0;');

deps.forEach(([n, label, pairs, desc, kind], i) => {
  const { x, y } = depPos(i), col = DC[kind];
  c += dbox('d' + n, `${n}. ${label}\n${pairs} par Agent+Krytyk · bramka jakości\n\n${desc}`, x, y, DEPW, DEPH, `rounded=1;whiteSpace=wrap;html=1;fillColor=${col.f};strokeColor=${col.s};fontColor=#1f2a44;align=left;verticalAlign=top;spacingLeft=10;spacingTop=6;fontSize=11;`);
  if (i > 0) c += dedge('ed' + n, 'd' + (n - 1), 'd' + n);
});
c += dedge('e_gate_d1', 'gate', 'd1');

c += dbox('tx', 'JEDNA TRANSAKCJA (tylko Dział 8) → BD-1\n\nwp_mp_sw_flow · wp_mp_sw_tasks · wp_mp_sw_notifications · wp_mp_sw_activity · wp_mp_sw_events\n\nWartownik statusu i lock_version sprawdzane WEWNĄTRZ transakcji — konflikt = 409, bez nadpisania.', X0, TXY, FULLW, TXH, 'rounded=1;whiteSpace=wrap;html=1;fillColor=#faf5ff;strokeColor=#7c3aed;fontColor=#4c1d95;align=left;verticalAlign=top;spacingLeft=14;spacingTop=6;fontSize=11;');
// Transakcję prowadzi Dział 8, nie 9 — strzałka wychodzi stamtąd, gdzie
// naprawdę zaczyna się zapis.
c += dedge('e_d8_tx', 'd8', 'tx');

outs.forEach((o, i) => {
  const bw = Math.round((FULLW - 2 * 20) / 3), x = X0 + i * (bw + 20);
  c += dbox('o' + i, `${o.t}\n\n${o.d}`, x, OUTY, bw, OUTH, `rounded=1;whiteSpace=wrap;html=1;fillColor=${o.f};strokeColor=${o.c};fontColor=#1f2a44;align=left;verticalAlign=top;spacingLeft=10;spacingTop=6;fontSize=11;`);
  c += dedge('e_tx_o' + i, 'tx', 'o' + i, i === 0 ? 'COMMIT' : '');
});

const drawio = `<mxfile host="app.diagrams.net" type="device"><diagram name="Schemat techniczny LP.3"><mxGraphModel dx="1200" dy="1000" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="${PW + 40}" pageHeight="${PH + 40}" math="0" shadow="0"><root><mxCell id="0"/><mxCell id="1" parent="0"/>${c}</root></mxGraphModel></diagram></mxfile>`;
fs.writeFileSync(OUT + '.drawio', drawio);
console.log('OK', OUT, '| działy:', deps.length, '| par razem:', deps.reduce((a, d) => a + d[2], 0));
