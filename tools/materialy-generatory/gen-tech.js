// Schemat TECHNICZNY P2 → SVG (PDF) + .drawio. Pipeline 11 działów (wężyk).
const fs = require('fs');
const OUT = process.argv[2] || 'p2/schemat-techniczny';

const deps = [
  { n: 1, t: 'Kontrakt wejścia i uprawnienia', s: '2 tryby: nowa / dokończenie draftu · idempotencja · IDOR', c: 'b' },
  { n: 2, t: 'Integracja WooCommerce', s: 'ceny produktów + krajowa stawka VAT (jeden odczyt)', c: 'a' },
  { n: 3, t: 'Walidacja pozycji', s: 'produkty istnieją, ilości > 0, spójność', c: 'b' },
  { n: 4, t: 'Ceny w groszach', s: 'BCMath, liczby całkowite — zero błędów float', c: 'b' },
  { n: 5, t: 'Rabaty', s: 'reguły wersjonowane w kodzie, rabat na sumie', c: 'b' },
  { n: 6, t: 'Mechanizm VAT', s: 'krajowy / odwrotne obciążenie / poza zakresem', c: 'a' },
  { n: 7, t: 'Szablon i treść', s: 'podstawienie znaczników {{...}} w HTML', c: 'b' },
  { n: 8, t: 'Numeracja i wersja', s: 'OF/RRRR/NNNNNN · korekta = ten sam numer, wersja+1', c: 'b' },
  { n: 9, t: 'Render PDF', s: 'Dompdf + DejaVu Sans (polskie znaki)', c: 'a' },
  { n: 10, t: 'Zapis transakcyjny (BD-2)', s: 'nagłówek + pozycje + wersja + log w jednej transakcji', c: 'g' },
  { n: 11, t: 'Odpowiedź i zdarzenie', s: 'mp_offer_created wystawiane PO COMMIT', c: 'g' },
];
const COL = { a: { f: '#fef3c7', s: '#f59e0b' }, b: { f: '#eef4ff', s: '#2563eb' }, g: { f: '#e7f8f1', s: '#10b981' } };

const W = 1180, CW = 262, CH = 92, GX = 24, GY = 42, X0 = 30, Y0 = 128, COLS = 4;
function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function wrap(s, max) { const w = s.split(' '); const o = []; let c = ''; for (const x of w) { if ((c + ' ' + x).trim().length > max) { o.push(c); c = x; } else c = (c + ' ' + x).trim(); } if (c) o.push(c); return o; }
// pozycja wężykiem
function pos(i) { const row = Math.floor(i / COLS); let col = i % COLS; if (row % 2 === 1) col = COLS - 1 - col; return { x: X0 + col * (CW + GX), y: Y0 + row * (CH + GY), row, col }; }

let svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="590" viewBox="0 0 ${W} 590" font-family="DejaVu Sans">`;
svg += `<rect width="${W}" height="590" fill="#ffffff"/>`;
svg += `<text x="30" y="40" font-size="22" font-weight="bold" fill="#1f2a44">Schemat techniczny — pipeline MP Offer Builder (11 działów)</text>`;
// wejścia
svg += `<rect x="30" y="62" width="540" height="46" rx="8" fill="#1f2a44"/>`;
svg += `<text x="46" y="82" font-size="12" font-weight="bold" fill="#ffffff">Wejście „1 AJAX" (wp_ajax) — handlowiec buduje ofertę</text>`;
svg += `<text x="46" y="99" font-size="10.5" fill="#c7d2fe">albo hook mp_lead_created (Plugin 1) → automatyczny szkic (draft)</text>`;
svg += `<text x="600" y="82" font-size="10.5" fill="#64748b">Dane między działami: JSON (MP_OB_Context) · pipeline jednokierunkowy</text>`;
svg += `<text x="600" y="99" font-size="10.5" fill="#64748b">Po KAŻDYM dziale: bramka QA (Agent QA + Krytyk QA) · błąd = STOP + log</text>`;

// łączniki (wężyk)
for (let i = 0; i < deps.length - 1; i++) {
  const a = pos(i), b = pos(i + 1);
  const ay = a.y + CH / 2, by = b.y + CH / 2;
  if (a.row === b.row) {
    const dir = b.x > a.x ? 1 : -1;
    const sx = dir === 1 ? a.x + CW : a.x, ex = dir === 1 ? b.x : b.x + CW;
    svg += `<path d="M ${sx} ${ay} L ${ex - dir * 6} ${ay}" stroke="#94a3b8" stroke-width="2"/><path d="M ${ex} ${by} l ${-dir * 7} -4 v 8 z" fill="#94a3b8"/>`;
  } else { // zejście w dół w tej samej kolumnie
    const cx = a.x + CW / 2;
    svg += `<path d="M ${cx} ${a.y + CH} L ${cx} ${b.y - 6}" stroke="#94a3b8" stroke-width="2"/><path d="M ${cx} ${b.y} l -4 -7 h 8 z" fill="#94a3b8"/>`;
  }
}
// karty
deps.forEach((d, i) => {
  const p = pos(i), col = COL[d.c];
  svg += `<rect x="${p.x}" y="${p.y}" width="${CW}" height="${CH}" rx="9" fill="${col.f}" stroke="${col.s}" stroke-width="1.6"/>`;
  svg += `<circle cx="${p.x + 24}" cy="${p.y + 25}" r="14" fill="${col.s}"/>`;
  svg += `<text x="${p.x + 24}" y="${p.y + 30}" font-size="14" font-weight="bold" fill="#ffffff" text-anchor="middle">${d.n}</text>`;
  wrap(d.t, 26).forEach((ln, k) => svg += `<text x="${p.x + 46}" y="${p.y + 22 + k * 15}" font-size="12" font-weight="bold" fill="#1f2a44">${esc(ln)}</text>`);
  wrap(d.s, 40).forEach((ln, k) => svg += `<text x="${p.x + 14}" y="${p.y + 58 + k * 14}" font-size="9.8" fill="#334155">${esc(ln)}</text>`);
});
// klamra transakcji przy D10
const p10 = pos(9);
svg += `<rect x="${p10.x - 4}" y="${p10.y - 4}" width="${CW + 8}" height="${CH + 8}" rx="11" fill="none" stroke="#10b981" stroke-width="1.4" stroke-dasharray="6 4"/>`;
svg += `<text x="${p10.x + CW / 2}" y="${p10.y + CH + 20}" font-size="10" fill="#059669" text-anchor="middle" font-weight="bold">Transakcja: Działy 1–10 · COMMIT przed Działem 11</text>`;

// legenda kolorów
const ly = 562;
svg += `<text x="30" y="${ly}" font-size="10.5" fill="#64748b">Legenda:</text>`;
const leg = [['#eef4ff', '#2563eb', 'logika / walidacja'], ['#fef3c7', '#f59e0b', 'integracje zewnętrzne (WooCommerce, Dompdf, VAT)'], ['#e7f8f1', '#10b981', 'zapis / zdarzenie']];
let lx = 92; leg.forEach(([f, s, d]) => { svg += `<rect x="${lx}" y="${ly - 11}" width="16" height="12" rx="3" fill="${f}" stroke="${s}"/><text x="${lx + 22}" y="${ly}" font-size="10" fill="#475569">${esc(d)}</text>`; lx += 40 + d.length * 5.6; });
svg += `</svg>`;
fs.writeFileSync(OUT + '.svg', svg);

// ---------- .drawio ----------
function dcell(id, val, x, y, w, h, style) { return `<mxCell id="${id}" value="${val.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '&#10;')}" style="${style}" vertex="1" parent="1"><mxGeometry x="${x}" y="${y}" width="${w}" height="${h}" as="geometry"/></mxCell>`; }
let cells = dcell('t', 'Schemat techniczny — pipeline MP Offer Builder (11 działów)', 30, 16, 900, 30, 'text;html=1;fontSize=18;fontStyle=1;fontColor=#1f2a44;');
cells += dcell('in', 'Wejście „1 AJAX" (handlowiec) albo hook mp_lead_created (Plugin 1) → szkic. Dane w JSON (MP_OB_Context). Po KAŻDYM dziale bramka QA; błąd = STOP + log.', 30, 60, 900, 50, 'rounded=1;whiteSpace=wrap;html=1;fillColor=#1f2a44;fontColor=#ffffff;align=left;verticalAlign=middle;spacingLeft=10;fontSize=10;');
deps.forEach((d, i) => { const p = pos(i), c = COL[d.c]; cells += dcell('d' + d.n, `${d.n}. ${d.t}\n${d.s}`, p.x, p.y + 20, CW, CH, `rounded=1;whiteSpace=wrap;html=1;fillColor=${c.f};strokeColor=${c.s};fontColor=#1f2a44;verticalAlign=top;spacingTop=8;spacingLeft=8;spacingRight=8;fontSize=11;`);
  if (i > 0) cells += `<mxCell id="e${i}" style="html=1;endArrow=block;strokeColor=#94a3b8" edge="1" parent="1" source="d${i}" target="d${i + 1}"><mxGeometry relative="1" as="geometry"/></mxCell>`; });
cells += dcell('tx', 'Transakcja: Działy 1–10 · COMMIT przed Działem 11 (mp_offer_created PO COMMIT)', pos(9).x - 10, pos(9).y + CH + 26, CW + 20, 40, 'rounded=1;whiteSpace=wrap;html=1;dashed=1;fillColor=none;strokeColor=#10b981;fontColor=#059669;fontSize=10;');
const drawio = `<mxfile host="app.diagrams.net" type="device"><diagram name="Schemat techniczny"><mxGraphModel dx="1100" dy="620" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1200" pageHeight="600" math="0" shadow="0"><root><mxCell id="0"/><mxCell id="1" parent="0"/>${cells}</root></mxGraphModel></diagram></mxfile>`;
fs.writeFileSync(OUT + '.drawio', drawio);
console.log('OK', OUT);
