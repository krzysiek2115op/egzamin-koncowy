// Schemat NIETECHNICZNY P1 → .drawio (edytowalny) + .svg (→ PDF). Proces
// prostym językiem (5 kroków + „Co to daje?"). Zgodny z realnym zachowaniem:
// formularz idzie JEDNYM żądaniem AJAX, NIP i VAT UE są sprawdzane w rejestrach
// zewnętrznych (VIES, Biała lista KAS), a zakończenie pipeline'u wystawia
// zdarzenie mp_lead_created, na które czeka Plugin 2.
// Użycie: node schemat-nietechniczny.js out/schemat-nietechniczny
const fs = require('fs');
const OUT = process.argv[2] || 'out/schemat-nietechniczny';

const steps = [
  { n: 1, title: 'Klient wypełnia formularz', body: 'Na stronie „Zapytanie ofertowe" podaje dane firmy, NIP, kontakt i zaznacza zgody.', c: 'in' },
  { n: 2, title: 'System sprawdza dane', body: 'Kompletność pól, poprawność adresu e-mail i suma kontrolna NIP-u — zanim cokolwiek trafi do bazy.', c: 'in' },
  { n: 3, title: 'Weryfikacja w rejestrach', body: 'NIP i VAT UE sprawdzane w rejestrze VIES oraz na Białej liście podatników KAS.', c: 'ext' },
  { n: 4, title: 'Kwalifikacja zapytania', body: 'Kraj, segment i kategoria klienta nadawane automatycznie; zapytanie dostaje punktację i handlowca.', c: 'in' },
  { n: 5, title: 'Zapis i przekazanie dalej', body: 'Zapytanie zapisane w bazie razem ze zgodami i historią; Plugin 2 dostaje sygnał, żeby przygotować ofertę.', c: 'save' },
];
const summary = {
  title: 'Co to daje?',
  lines: [
    'Żadnych zapytań-widm — niekompletne albo podejrzane zgłoszenie nie zostaje zapisane po cichu, tylko wraca do klienta z komunikatem.',
    'Dane firmy potwierdzone u źródła — NIP i VAT UE sprawdzone w rejestrach państwowych, nie „na słowo".',
    'Zgody RODO z datą i wersją treści — wiadomo, na co dokładnie klient się zgodził i kiedy.',
    'Jeden klient to jedno zapytanie — powtórne zgłoszenie tej samej firmy nie tworzy duplikatu.',
    'Handlowiec przypisany od razu, a cała droga zapytania zapisana w historii aktywności.',
  ],
};
const COL = { in: { f: '#eef4ff', s: '#2563eb' }, ext: { f: '#fef3c7', s: '#f59e0b' }, save: { f: '#e7f8f1', s: '#10b981' } };

const CW = 210, CH = 196, GAP = 22, X0 = 40, Y0 = 96;
function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function escA(s) { return esc(s).replace(/\n/g, '&#10;'); }
function wrap(s, max) { const w = s.split(' '); const o = []; let c = ''; for (const x of w) { if ((c + ' ' + x).trim().length > max) { o.push(c); c = x; } else c = (c + ' ' + x).trim(); } if (c) o.push(c); return o; }

/* ============================ SVG (→ PDF) ============================ */
const PW = 1180, PH = 520;
let g = `<svg xmlns="http://www.w3.org/2000/svg" width="${PW}" height="${PH}" viewBox="0 0 ${PW} ${PH}" font-family="DejaVu Sans, Arial, sans-serif">`;
g += `<rect width="${PW}" height="${PH}" fill="#ffffff"/>`;
g += `<defs><marker id="arr" markerWidth="12" markerHeight="12" refX="9" refY="5" orient="auto"><path d="M1 1 L9 5 L1 9 z" fill="#94a3b8"/></marker></defs>`;
g += `<text x="40" y="42" font-size="21" font-weight="bold" fill="#1f2a44">Jak działa MP Lead Intake — w skrócie</text>`;
g += `<text x="40" y="66" font-size="12" fill="#64748b">Od formularza na stronie do zapytania gotowego do wyceny — w pięciu krokach.</text>`;

steps.forEach((st, i) => {
  const x = X0 + i * (CW + GAP), col = COL[st.c];
  g += `<rect x="${x}" y="${Y0}" width="${CW}" height="${CH}" rx="10" fill="${col.f}" stroke="${col.s}" stroke-width="1.6"/>`;
  g += `<rect x="${x}" y="${Y0}" width="${CW}" height="6" rx="3" fill="${col.s}"/>`;
  g += `<circle cx="${x + 26}" cy="${Y0 + 34}" r="15" fill="${col.s}"/><text x="${x + 26}" y="${Y0 + 39}" font-size="14" font-weight="bold" fill="#ffffff" text-anchor="middle">${st.n}</text>`;
  wrap(st.title, 18).forEach((ln, k) => { g += `<text x="${x + 50}" y="${Y0 + 30 + k * 16}" font-size="12.5" font-weight="bold" fill="#1f2a44">${esc(ln)}</text>`; });
  wrap(st.body, 30).forEach((ln, k) => { g += `<text x="${x + 16}" y="${Y0 + 74 + k * 15}" font-size="10.5" fill="#334155">${esc(ln)}</text>`; });
  if (i < steps.length - 1) g += `<path d="M${x + CW} ${Y0 + CH / 2} L${x + CW + GAP} ${Y0 + CH / 2}" fill="none" stroke="#94a3b8" stroke-width="1.8" marker-end="url(#arr)"/>`;
});

// podsumowanie — wysokość liczona z liczby zawiniętych wierszy
const wrapped = summary.lines.map((ln) => wrap(ln, 132));
const totalLines = wrapped.reduce((a, p) => a + p.length, 0);
const sy = Y0 + CH + 30, sw = PW - 80, sh = 44 + totalLines * 17 + 14;
g += `<rect x="40" y="${sy}" width="${sw}" height="${sh}" rx="10" fill="#ffffff" stroke="#e2e8f0" stroke-width="1.6"/>`;
g += `<rect x="40" y="${sy}" width="6" height="${sh}" rx="3" fill="#10b981"/>`;
g += `<text x="62" y="${sy + 28}" font-size="14" font-weight="bold" fill="#1f2a44">${esc(summary.title)}</text>`;
let yy = sy + 50;
wrapped.forEach((parts) => {
  g += `<text x="62" y="${yy}" font-size="11.5" fill="#10b981" font-weight="bold">✓</text>`;
  parts.forEach((pp, k) => { g += `<text x="80" y="${yy + k * 15}" font-size="11" fill="#334155">${esc(pp)}</text>`; });
  yy += parts.length * 15 + 4;
});
g += `</svg>`;
fs.writeFileSync(OUT + '.svg', g);

/* ============================ .drawio ============================ */
function dbox(id, val, x, y, w, h, style) { return `<mxCell id="${id}" value="${escA(val)}" style="${style}" vertex="1" parent="1"><mxGeometry x="${x}" y="${y}" width="${w}" height="${h}" as="geometry"/></mxCell>`; }
let c = dbox('t1', 'Jak działa MP Lead Intake — w skrócie', 40, 16, 800, 30, 'text;html=1;fontSize=20;fontStyle=1;fontColor=#1f2a44;');
c += dbox('t2', 'Od formularza na stronie do zapytania gotowego do wyceny — w pięciu krokach.', 40, 48, 800, 20, 'text;html=1;fontSize=12;fontColor=#64748b;');
steps.forEach((st, i) => {
  const x = X0 + i * (CW + GAP), col = COL[st.c];
  c += dbox('c' + st.n, `${st.n}. ${st.title}\n\n${st.body}`, x, Y0, CW, CH, `rounded=1;whiteSpace=wrap;html=1;fillColor=${col.f};strokeColor=${col.s};fontColor=#1f2a44;verticalAlign=top;spacingTop=10;spacingLeft=10;spacingRight=10;fontSize=12;`);
  if (i > 0) c += `<mxCell id="e${i}" style="edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;endArrow=block;strokeColor=#94a3b8" edge="1" parent="1" source="c${steps[i - 1].n}" target="c${st.n}"><mxGeometry relative="1" as="geometry"/></mxCell>`;
});
const sumVal = summary.title + '\n' + summary.lines.map((l) => '✓ ' + l).join('\n');
c += dbox('sum', sumVal, 40, Y0 + CH + 30, PW - 80, 150, 'rounded=1;whiteSpace=wrap;html=1;fillColor=#ffffff;strokeColor=#e2e8f0;fontColor=#1f2a44;align=left;verticalAlign=top;spacingLeft=14;spacingTop=10;fontSize=11;');

const drawio = `<mxfile host="app.diagrams.net" type="device"><diagram name="Schemat procesu"><mxGraphModel dx="1100" dy="520" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1200" pageHeight="540" math="0" shadow="0"><root><mxCell id="0"/><mxCell id="1" parent="0"/>${c}</root></mxGraphModel></diagram></mxfile>`;
fs.writeFileSync(OUT + '.drawio', drawio);
console.log('OK', OUT, '| kroki:', steps.length);
