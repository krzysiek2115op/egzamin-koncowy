// Schemat NIETECHNICZNY P2 → SVG (PDF) + .drawio. Proces prostym językiem.
const fs = require('fs');
const OUT = process.argv[2] || 'p2/schemat-nietechniczny';

const steps = [
  { n: 1, title: 'Skąd startuje oferta', body: 'Dane klienta wchodzą automatycznie ze zwalidowanego zapytania (Plugin 1) albo handlowiec wpisuje je ręcznie.', fill: '#eef4ff', stroke: '#2563eb' },
  { n: 2, title: 'Handlowiec dobiera produkty', body: 'Wybiera pozycje z katalogu WooCommerce, ilości i wariant cenowy (Standard / Partner).', fill: '#eef4ff', stroke: '#2563eb' },
  { n: 3, title: 'System liczy ofertę', body: 'Pobiera ceny z WooCommerce, nalicza rabaty i właściwy VAT (krajowy / odwrotne obciążenie / poza zakresem).', fill: '#fef3c7', stroke: '#f59e0b' },
  { n: 4, title: 'Powstaje PDF', body: 'Generuje ofertę PDF z unikalnym numerem OF/RRRR/NNNNNN i poprawnymi polskimi znakami.', fill: '#eef4ff', stroke: '#2563eb' },
  { n: 5, title: 'Zapis, historia, pobranie', body: 'Oferta zapisana z pełną historią wersji. PDF dostępny tylko dla właściciela (chroniony link).', fill: '#e7f8f1', stroke: '#10b981' },
];
const summary = {
  title: 'Co to daje?',
  lines: [
    'Szybko i bez pomyłek — ceny prosto z WooCommerce, żadnych ręcznych rachunków.',
    'Poprawny VAT — automatyczne odwrotne obciążenie dla firm z UE z potwierdzonym ważnym VAT.',
    'Bezpieczeństwo — każdy handlowiec widzi tylko swoje oferty; PDF za chronionym linkiem.',
    'Pełna historia — każda korekta to nowa wersja pod tym samym numerem oferty.',
  ],
};

const W = 1180, CARDW = 210, CARDH = 190, GAP = 24, X0 = 30, Y0 = 80;
function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function wrapSvg(s, max) { // proste zawijanie po ~max znakach
  const words = s.split(' '); const out = []; let cur = '';
  for (const w of words) { if ((cur + ' ' + w).trim().length > max) { out.push(cur); cur = w; } else cur = (cur + ' ' + w).trim(); }
  if (cur) out.push(cur); return out;
}

let svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="560" viewBox="0 0 ${W} 560" font-family="DejaVu Sans">`;
svg += `<rect width="${W}" height="560" fill="#ffffff"/>`;
svg += `<text x="30" y="44" font-size="22" font-weight="bold" fill="#1f2a44">Jak działa MP Offer Builder — w skrócie</text>`;
svg += `<text x="30" y="66" font-size="12" fill="#64748b">Od zapytania klienta do gotowej oferty PDF — krok po kroku.</text>`;

steps.forEach((s, i) => {
  const x = X0 + i * (CARDW + GAP);
  svg += `<rect x="${x}" y="${Y0}" width="${CARDW}" height="${CARDH}" rx="10" fill="${s.fill}" stroke="${s.stroke}" stroke-width="1.6"/>`;
  svg += `<circle cx="${x + 26}" cy="${Y0 + 28}" r="15" fill="${s.stroke}"/>`;
  svg += `<text x="${x + 26}" y="${Y0 + 33}" font-size="15" font-weight="bold" fill="#ffffff" text-anchor="middle">${s.n}</text>`;
  wrapSvg(s.title, 20).forEach((ln, k) => svg += `<text x="${x + 48}" y="${Y0 + 24 + k * 16}" font-size="12.5" font-weight="bold" fill="#1f2a44">${esc(ln)}</text>`);
  wrapSvg(s.body, 32).forEach((ln, k) => svg += `<text x="${x + 16}" y="${Y0 + 68 + k * 16}" font-size="10.7" fill="#334155">${esc(ln)}</text>`);
  if (i < steps.length - 1) {
    const ax = x + CARDW + 3, ay = Y0 + CARDH / 2;
    svg += `<path d="M ${ax} ${ay} L ${ax + GAP - 6} ${ay}" stroke="#94a3b8" stroke-width="2"/><path d="M ${ax + GAP - 6} ${ay} l -6 -4 v 8 z" fill="#94a3b8"/>`;
  }
});

// Ramka podsumowania
const sy = Y0 + CARDH + 40, sh = 150;
svg += `<rect x="${X0}" y="${sy}" width="${W - 60}" height="${sh}" rx="10" fill="#ffffff" stroke="#e2e8f0" stroke-width="1.4"/>`;
svg += `<rect x="${X0}" y="${sy}" width="6" height="${sh}" rx="3" fill="#10b981"/>`;
svg += `<text x="${X0 + 22}" y="${sy + 28}" font-size="14" font-weight="bold" fill="#1f2a44">${esc(summary.title)}</text>`;
summary.lines.forEach((l, k) => {
  svg += `<circle cx="${X0 + 28}" cy="${sy + 50 + k * 24}" r="3" fill="#10b981"/>`;
  svg += `<text x="${X0 + 40}" y="${sy + 54 + k * 24}" font-size="11.3" fill="#334155">${esc(l)}</text>`;
});
svg += `</svg>`;
fs.writeFileSync(OUT + '.svg', svg);

// ---------- .drawio ----------
function dcell(id, val, x, y, w, h, style) {
  return `<mxCell id="${id}" value="${val.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '&#10;')}" style="${style}" vertex="1" parent="1"><mxGeometry x="${x}" y="${y}" width="${w}" height="${h}" as="geometry"/></mxCell>`;
}
let cells = dcell('t', 'Jak działa MP Offer Builder — w skrócie', 30, 16, 800, 30, 'text;html=1;fontSize=20;fontStyle=1;fontColor=#1f2a44;');
const dw = 210, dh = 170, dgap = 30, dx0 = 30, dy0 = 70;
steps.forEach((s, i) => {
  const x = dx0 + i * (dw + dgap);
  cells += dcell('s' + s.n, `${s.n}. ${s.title}\n\n${s.body}`, x, dy0, dw, dh, `rounded=1;whiteSpace=wrap;html=1;fillColor=${s.fill};strokeColor=${s.stroke};fontColor=#1f2a44;verticalAlign=top;spacingTop=10;spacingLeft=8;spacingRight=8;fontSize=11;`);
  if (i > 0) cells += `<mxCell id="e${i}" style="html=1;endArrow=block;strokeColor=#94a3b8" edge="1" parent="1" source="s${i}" target="s${i + 1}"><mxGeometry relative="1" as="geometry"/></mxCell>`;
});
cells += dcell('sum', summary.title + '\n' + summary.lines.map(l => '• ' + l).join('\n'), dx0, dy0 + dh + 40, (dw + dgap) * 5 - dgap, 150, 'rounded=1;whiteSpace=wrap;html=1;fillColor=#ffffff;strokeColor=#10b981;fontColor=#1f2a44;align=left;verticalAlign=top;spacingLeft=10;spacingTop=10;fontSize=11;');
const drawio = `<mxfile host="app.diagrams.net" type="device"><diagram name="Schemat nietechniczny"><mxGraphModel dx="1100" dy="600" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1200" pageHeight="560" math="0" shadow="0"><root><mxCell id="0"/><mxCell id="1" parent="0"/>${cells}</root></mxGraphModel></diagram></mxfile>`;
fs.writeFileSync(OUT + '.drawio', drawio);
console.log('OK', OUT);
