// Schemat TECHNICZNY P1 → .drawio (edytowalny w draw.io) + .svg (→ PDF).
// Pipeline 11 działów (wężyk) z aktorem wejścia, integracjami zewnętrznymi,
// magazynami BD-3 i zdarzeniem wyjściowym. Zgodny z realnym kodem:
//   - jedno żądanie AJAX (wp_ajax_nopriv_mp_lead_intake_submit) niesie cały formularz,
//   - zapis leada powstaje w Dziale 7, historia w Dziale 8, telemetria w 9,
//   - zdarzenie mp_lead_created wystawia DZIAŁ 11, już po zamknięciu pipeline'u.
// Użycie: node schemat-techniczny.js out/schemat-techniczny
const fs = require('fs');
const OUT = process.argv[2] || 'out/schemat-techniczny';

const deps = [
  { n: 1, t: 'Pobranie danych z BD-3', s: 'wszystko czego pipeline potrzebuje — jednym strzałem, jeden AJAX', c: 'in' },
  { n: 2, t: 'Walidacja formularza', s: 'struktura · pola wymagane · formaty (e-mail, telefon, NIP)', c: 'in' },
  { n: 3, t: 'NIP / VAT', s: 'suma kontrolna NIP · VIES (VAT UE) · Biała lista KAS', c: 'ext' },
  { n: 4, t: 'Kraj i segment', s: 'ISO 3166-1 · segment · kategoria klienta', c: 'in' },
  { n: 5, t: 'Zabezpieczenie formularza', s: 'nonce (CSRF) · pułapka na roboty · ogranicznik żądań', c: 'in' },
  { n: 6, t: 'Zgody', s: 'marketing + RODO · data i WERSJA treści zgody', c: 'save' },
  { n: 7, t: 'Utworzenie leada', s: 'dedup po (kraj, NIP) · scoring · handlowiec · zapis wp_mp_leads', c: 'save' },
  { n: 8, t: 'Historia aktywności', s: 'wpis operacji do wp_mp_activity_log', c: 'save' },
  { n: 9, t: 'Telemetria startu obsługi', s: 'znacznik czasu początku obsługi · wpis uzupełniający', c: 'save' },
  { n: 10, t: 'Odpowiedź do przeglądarki', s: 'success · lead_id · komunikat dla człowieka', c: 'in' },
  { n: 11, t: 'Zamknięcie i zdarzenie', s: 'sprzątanie · raport · mp_lead_created → Plugin 2', c: 'save' },
];
const COL = {
  in: { f: '#eef4ff', s: '#2563eb' },
  ext: { f: '#fef3c7', s: '#f59e0b' },
  save: { f: '#e7f8f1', s: '#10b981' },
};

const CW = 250, CH = 84, GX = 32, GY = 56, X0 = 40, Y0 = 168, COLS = 4;
function pos(i) { const row = Math.floor(i / COLS); let col = i % COLS; if (row % 2 === 1) col = COLS - 1 - col; return { x: X0 + col * (CW + GX), y: Y0 + row * (CH + GY), row, col }; }
function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function escA(s) { return esc(s).replace(/\n/g, '&#10;'); }
function wrap(s, max) { const w = s.split(' '); const o = []; let c = ''; for (const x of w) { if ((c + ' ' + x).trim().length > max) { o.push(c); c = x; } else c = (c + ' ' + x).trim(); } if (c) o.push(c); return o; }

const P7 = pos(6), P9 = pos(8), P11 = pos(10);
const stores = ['wp_mp_leads', 'wp_mp_offers', 'wp_mp_activity_log'];
const STY = 600, STH = 42, STW = 250, STGX = 24;

/* ============================ SVG (→ PDF) ============================ */
const PW = 1200, PH = 720;
let g = `<svg xmlns="http://www.w3.org/2000/svg" width="${PW}" height="${PH}" viewBox="0 0 ${PW} ${PH}" font-family="DejaVu Sans, Arial, sans-serif">`;
g += `<rect width="${PW}" height="${PH}" fill="#ffffff"/>`;
g += `<defs><marker id="arr" markerWidth="12" markerHeight="12" refX="9" refY="5" orient="auto"><path d="M1 1 L9 5 L1 9 z" fill="#94a3b8"/></marker></defs>`;
g += `<text x="40" y="40" font-size="20" font-weight="bold" fill="#1f2a44">Schemat techniczny — pipeline MP Lead Intake (11 działów)</text>`;

// pasek wejścia (aktorzy)
g += `<rect x="40" y="60" width="1120" height="60" rx="8" fill="#1f2a44"/>`;
g += `<text x="56" y="84" font-size="12.5" font-weight="bold" fill="#ffffff">Wejście: gość na stronie — formularz [mp_lead_intake_form] → JEDNO żądanie AJAX (wp_ajax_nopriv_mp_lead_intake_submit)</text>`;
g += `<text x="56" y="105" font-size="11" fill="#cbd5e1">Dane płyną jednokierunkowo w JSON (MP_Context). Po KAŻDYM dziale bramka QA (Agent → Krytyk → QA); błąd = STOP + wpis do BD-3 + alarm do administratora.</text>`;

function edge(a, b) {
  let p1, p2, my;
  if (a.row === b.row) {
    const rightward = b.x > a.x;
    p1 = { x: rightward ? a.x + CW : a.x, y: a.y + CH / 2 };
    p2 = { x: rightward ? b.x : b.x + CW, y: b.y + CH / 2 };
    return `<path d="M${p1.x} ${p1.y} L${p2.x} ${p2.y}" fill="none" stroke="#94a3b8" stroke-width="1.6" marker-end="url(#arr)"/>`;
  }
  p1 = { x: a.x + CW / 2, y: a.y + CH };
  p2 = { x: b.x + CW / 2, y: b.y };
  my = (p1.y + p2.y) / 2;
  return `<path d="M${p1.x} ${p1.y} L${p1.x} ${my} L${p2.x} ${my} L${p2.x} ${p2.y}" fill="none" stroke="#94a3b8" stroke-width="1.6" marker-end="url(#arr)"/>`;
}
// wejście → D1
g += `<path d="M${pos(0).x + CW / 2} 120 L${pos(0).x + CW / 2} ${pos(0).y}" fill="none" stroke="#94a3b8" stroke-width="1.6" marker-end="url(#arr)"/>`;
for (let i = 0; i < deps.length - 1; i++) g += edge(pos(i), pos(i + 1));

// kafle działów
deps.forEach((d, i) => {
  const p = pos(i), col = COL[d.c];
  g += `<rect x="${p.x}" y="${p.y}" width="${CW}" height="${CH}" rx="9" fill="${col.f}" stroke="${col.s}" stroke-width="1.6"/>`;
  g += `<circle cx="${p.x + 22}" cy="${p.y + 23}" r="13" fill="${col.s}"/><text x="${p.x + 22}" y="${p.y + 28}" font-size="13" font-weight="bold" fill="#ffffff" text-anchor="middle">${d.n}</text>`;
  wrap(d.t, 24).forEach((ln, k) => { g += `<text x="${p.x + 44}" y="${p.y + 21 + k * 15}" font-size="12" font-weight="bold" fill="#1f2a44">${esc(ln)}</text>`; });
  wrap(d.s, 42).forEach((ln, k) => { g += `<text x="${p.x + 14}" y="${p.y + 52 + k * 13}" font-size="9.5" fill="#334155">${esc(ln)}</text>`; });
});

// jedyny wiersz leada powstaje w Dziale 7 — zaznaczone, bo to punkt bez odwrotu
g += `<rect x="${P7.x - 8}" y="${P7.y - 8}" width="${CW + 16}" height="${CH + 16}" rx="12" fill="none" stroke="#10b981" stroke-width="1.6" stroke-dasharray="6 4"/>`;
g += `<text x="${P7.x + CW / 2}" y="${P7.y - 14}" font-size="10" fill="#059669" text-anchor="middle" font-weight="bold">Tu powstaje wiersz leada · dedup po (kraj, NIP) · dalej już tylko dopiski</text>`;

// magazyny BD-3
g += `<text x="${40 + STW + STGX}" y="${STY - 10}" font-size="11" font-weight="bold" fill="#059669">BD-3 — trzy tabele (zapis w działach 6–9):</text>`;
stores.forEach((name, i) => {
  const x = 40 + i * (STW + STGX);
  g += `<rect x="${x}" y="${STY}" width="${STW}" height="${STH}" rx="6" fill="#e7f8f1" stroke="#10b981" stroke-width="1.4"/>`;
  g += `<text x="${x + STW / 2}" y="${STY + 26}" font-size="11" fill="#065f46" text-anchor="middle" font-family="DejaVu Sans Mono, monospace">${esc(name)}</text>`;
});
// Strzalka do magazynow wychodzi z Dzialu 9 (ostatniego piszacego), a nie z 7 —
// z 7 przecinalaby kafel Dzialu 10 lezacy w wezyku dokladnie pod nia.
g += `<path d="M${P9.x + CW / 2} ${P9.y + CH} L${P9.x + CW / 2} ${STY}" fill="none" stroke="#10b981" stroke-width="1.6" marker-end="url(#arr)"/>`;

// D11 → zdarzenie → Plugin 2
const evx = P11.x + CW + 40;
g += `<path d="M${P11.x + CW} ${P11.y + CH / 2} L${evx} ${P11.y + CH / 2}" fill="none" stroke="#94a3b8" stroke-width="1.6" marker-end="url(#arr)"/>`;
g += `<rect x="${evx}" y="${P11.y + 8}" width="230" height="${CH - 16}" rx="9" fill="#f1f5f9" stroke="#64748b" stroke-width="1.4" stroke-dasharray="6 4"/>`;
g += `<text x="${evx + 115}" y="${P11.y + 34}" font-size="11.5" font-weight="bold" fill="#334155" text-anchor="middle">mp_lead_created (Dział 11)</text>`;
g += `<text x="${evx + 115}" y="${P11.y + 54}" font-size="10" fill="#64748b" text-anchor="middle">→ Plugin 2 (Offer Builder) — szkic oferty</text>`;

// legenda
const ly = PH - 22;
g += `<text x="40" y="${ly}" font-size="10.5" fill="#64748b">Legenda:</text>`;
const leg = [['#eef4ff', '#2563eb', 'logika / walidacja'], ['#fef3c7', '#f59e0b', 'rejestry zewnętrzne (VIES · Biała lista KAS)'], ['#e7f8f1', '#10b981', 'zapis do BD-3 / zdarzenie']];
let lx = 100;
leg.forEach(([f, s, d]) => { g += `<rect x="${lx}" y="${ly - 11}" width="16" height="12" rx="3" fill="${f}" stroke="${s}"/><text x="${lx + 22}" y="${ly}" font-size="10" fill="#475569">${esc(d)}</text>`; lx += 44 + d.length * 5.7; });
g += `</svg>`;
fs.writeFileSync(OUT + '.svg', g);

/* ============================ .drawio ============================ */
function dbox(id, val, x, y, w, h, style) { return `<mxCell id="${id}" value="${escA(val)}" style="${style}" vertex="1" parent="1"><mxGeometry x="${x}" y="${y}" width="${w}" height="${h}" as="geometry"/></mxCell>`; }
function dedge(id, src, tgt, style) { return `<mxCell id="${id}" style="${style}" edge="1" parent="1" source="${src}" target="${tgt}"><mxGeometry relative="1" as="geometry"/></mxCell>`; }
const EDGE = 'edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;endArrow=block;strokeColor=#94a3b8;';

let c = dbox('t1', 'Schemat techniczny — pipeline MP Lead Intake (11 działów)', 40, 16, 900, 30, 'text;html=1;fontSize=18;fontStyle=1;fontColor=#1f2a44;');
c += dbox('in', 'Wejście: gość na stronie — formularz [mp_lead_intake_form] → JEDNO żądanie AJAX (wp_ajax_nopriv_mp_lead_intake_submit).\nDane jednokierunkowo w JSON (MP_Context). Po KAŻDYM dziale bramka QA; błąd = STOP + wpis do BD-3 + alarm do administratora.', 40, 60, 1120, 60, 'rounded=1;whiteSpace=wrap;html=1;fillColor=#1f2a44;fontColor=#ffffff;align=left;verticalAlign=middle;spacingLeft=12;fontSize=11;');
deps.forEach((d, i) => {
  const p = pos(i), col = COL[d.c];
  c += dbox('d' + d.n, `${d.n}. ${d.t}\n${d.s}`, p.x, p.y, CW, CH, `rounded=1;whiteSpace=wrap;html=1;fillColor=${col.f};strokeColor=${col.s};fontColor=#1f2a44;verticalAlign=top;spacingTop=8;spacingLeft=10;spacingRight=10;fontSize=11;`);
});
c += dedge('e_in', 'in', 'd1', EDGE);
for (let i = 0; i < deps.length - 1; i++) c += dedge('e' + i, 'd' + deps[i].n, 'd' + deps[i + 1].n, EDGE);
// punkt bez odwrotu — Dział 7
c += dbox('lead', 'Tu powstaje wiersz leada · dedup po (kraj, NIP) · dalej już tylko dopiski', P7.x - 10, P7.y - 34, CW + 20, 26, 'text;html=1;fontSize=10;fontColor=#059669;fontStyle=1;align=center;');
c += dbox('leadbox', '', P7.x - 8, P7.y - 8, CW + 16, CH + 16, 'rounded=1;html=1;dashed=1;fillColor=none;strokeColor=#10b981;');
// magazyny
c += dbox('stlbl', 'BD-3 — trzy tabele (zapis w działach 6–9):', 40 + STW + STGX, STY - 24, 500, 18, 'text;html=1;fontSize=11;fontStyle=1;fontColor=#059669;');
stores.forEach((name, i) => { const x = 40 + i * (STW + STGX); c += dbox('st' + i, name, x, STY, STW, STH, 'rounded=1;whiteSpace=wrap;html=1;fillColor=#e7f8f1;strokeColor=#10b981;fontColor=#065f46;fontFamily=Courier New;fontSize=11;'); });
c += dedge('e_store', 'd9', 'st0', 'edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;endArrow=block;strokeColor=#10b981;');
// zdarzenie → Plugin 2
const EVX = P11.x + CW + 40;
c += dbox('ev', 'mp_lead_created (Dział 11)\n→ Plugin 2 (Offer Builder) — szkic oferty', EVX, P11.y + 8, 230, CH - 16, 'rounded=1;whiteSpace=wrap;html=1;dashed=1;fillColor=#f1f5f9;strokeColor=#64748b;fontColor=#334155;fontSize=10;align=center;');
c += dedge('e_ev', 'd11', 'ev', EDGE);

const drawio = `<mxfile host="app.diagrams.net" type="device"><diagram name="Schemat techniczny"><mxGraphModel dx="1100" dy="720" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1250" pageHeight="740" math="0" shadow="0"><root><mxCell id="0"/><mxCell id="1" parent="0"/>${c}</root></mxGraphModel></diagram></mxfile>`;
fs.writeFileSync(OUT + '.drawio', drawio);
console.log('OK', OUT, '| działy:', deps.length);
