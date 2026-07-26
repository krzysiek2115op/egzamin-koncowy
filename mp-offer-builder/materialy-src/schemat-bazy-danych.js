// Generator schematu BD-2 (ERD) → .drawio (kształty table/tableRow, edytowalny
// w draw.io) + .svg (→ PDF przez rsvg-convert). JEDNO źródło = realny CREATE
// TABLE z includes/db/class-mp-offer-builder-db.php (DB_VERSION 0.7.0).
// Uwaga: dbDelta NIE tworzy twardych kluczy obcych — wszystkie relacje są
// logiczne (egzekwowane w kodzie); kolumny wiążące mają indeks (tag K).
// Użycie: node schemat-bazy-danych.js out/schemat-bazy-danych
const fs = require('fs');
const OUT = process.argv[2] || 'out/schemat-bazy-danych';

const RH = 22;      // wysokość wiersza
const HDR = 30;     // wysokość nagłówka tabeli
const NEW = 'NOWE'; // znacznik kolumny/indeksu dodanego w audycie

// [nazwa, typ, tag(PK|UQ|UQ*|K|K→P1|''), NEW?]  (UQ* = unikalny łącznie: offer_number+version)
const tables = [
  {
    id: 'offers', title: 'wp_mp_ob_offers', sub: 'Oferta — nagłówek, klient, kwoty (grosze)',
    x: 430, y: 96, w: 300, hdr: '#2563eb', hs: '#1d4ed8',
    cols: [
      ['id', 'bigint', 'PK'], ['offer_number', 'varchar(30)', 'UQ*'], ['version', 'int', 'UQ*'],
      ['lock_version', 'int', ''], ['status', 'varchar(20)', 'K'], ['lang', 'varchar(5)', ''],
      ['lead_id', 'bigint', 'K→P1'], ['client_name', 'varchar(191)', ''], ['client_email', 'varchar(191)', ''],
      ['client_nip', 'varchar(20)', ''], ['client_country', 'char(2)', ''], ['client_vat_status', 'varchar(20)', '', NEW],
      ['net_grosze', 'bigint', ''], ['vat_grosze', 'bigint', ''], ['gross_grosze', 'bigint', ''],
      ['currency', 'char(3)', ''], ['tax_mechanism', 'varchar(20)', ''], ['template_id', 'bigint', ''],
      ['pdf_path', 'varchar(255)', ''], ['pdf_sha256', 'char(64)', ''], ['request_id', 'char(36)', 'UQ'],
      ['created_by', 'bigint', 'K'], ['created_at', 'datetime', ''], ['updated_at', 'datetime', 'K', NEW],
    ],
  },
  {
    id: 'templates', title: 'wp_mp_ob_offer_templates', sub: 'Szablony ofert (HTML + znaczniki)',
    x: 60, y: 96, w: 300, hdr: '#10b981', hs: '#059669',
    cols: [
      ['id', 'bigint', 'PK'], ['name', 'varchar(191)', ''], ['lang', 'varchar(5)', 'K'],
      ['content', 'longtext', ''], ['variables', 'longtext', ''], ['version', 'varchar(20)', ''],
      ['status', 'varchar(20)', 'K'], ['created_at', 'datetime', ''],
    ],
  },
  {
    id: 'items', title: 'wp_mp_ob_offer_items', sub: 'Pozycje oferty',
    x: 800, y: 96, w: 300, hdr: '#f59e0b', hs: '#d97706',
    cols: [
      ['id', 'bigint', 'PK'], ['offer_id', 'bigint', 'K'], ['product_id', 'bigint', ''],
      ['variation_id', 'bigint', ''], ['qty', 'int', ''], ['price_base_grosze', 'bigint', ''],
      ['discount_grosze', 'bigint', ''], ['price_final_grosze', 'bigint', ''], ['tax_rate', 'decimal(5,2)', ''],
    ],
  },
  {
    id: 'versions', title: 'wp_mp_ob_offer_versions', sub: 'Historia wersji (tylko dopisywanie)',
    x: 800, y: 360, w: 300, hdr: '#7c3aed', hs: '#6d28d9',
    cols: [
      ['id', 'bigint', 'PK'], ['offer_id', 'bigint', 'K'], ['version_number', 'int', ''],
      ['data_json', 'longtext', ''], ['pdf_path', 'varchar(255)', ''], ['created_at', 'datetime', ''],
      ['created_by', 'bigint', ''], ['change_log', 'text', ''],
    ],
  },
  {
    id: 'log', title: 'wp_mp_ob_offer_activity_log', sub: 'Log audytowy',
    x: 800, y: 610, w: 300, hdr: '#334155', hs: '#1f2a44',
    cols: [
      ['id', 'bigint', 'PK'], ['offer_id', 'bigint', 'K'], ['action', 'varchar(100)', 'K'],
      ['description', 'text', ''], ['user_id', 'bigint', ''], ['meta_json', 'longtext', ''],
      ['created_at', 'datetime', 'K'],
    ],
  },
];
const byId = Object.fromEntries(tables.map((t) => [t.id, t]));
const idx = (t, name) => t.cols.findIndex((c) => c[0] === name);
const th = (t) => HDR + t.cols.length * RH;
const NAMEW = (t) => Math.round(t.w * 0.56);

function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function escA(s) { return esc(s).replace(/\n/g, '&#10;'); }

/* ============================ SVG (→ PDF) ============================ */
const PW = 1160, PH = 830;
let svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${PW}" height="${PH}" viewBox="0 0 ${PW} ${PH}" font-family="DejaVu Sans, Arial, sans-serif">`;
svg += `<rect width="${PW}" height="${PH}" fill="#ffffff"/>`;
svg += `<defs><marker id="one" markerWidth="14" markerHeight="14" refX="11" refY="7" orient="auto"><path d="M6 2 L6 12" stroke="#94a3b8" stroke-width="1.6"/></marker>`;
svg += `<marker id="many" markerWidth="16" markerHeight="16" refX="2" refY="8" orient="auto"><path d="M14 8 L2 2 M14 8 L2 14 M14 8 L2 8" stroke="#94a3b8" stroke-width="1.4" fill="none"/></marker></defs>`;
svg += `<text x="40" y="42" font-size="20" font-weight="bold" fill="#1f2a44">Schemat bazy danych — BD-2 (MP Offer Builder)</text>`;
svg += `<text x="40" y="66" font-size="12" fill="#64748b">5 tabel · InnoDB · utf8mb4 · kwoty w groszach (bigint) · DB_VERSION 0.7.0 · relacje logiczne (dbDelta nie tworzy twardych FK)</text>`;

function midY(t, i) { return t.y + HDR + i * RH + RH / 2; }
function conn(a, ai, b, bi, label, dashed) {
  const acx = a.x + a.w / 2, bcx = b.x + b.w / 2;
  const p1 = { x: bcx > acx ? a.x + a.w : a.x, y: midY(a, ai) };
  const p2 = { x: bcx > acx ? b.x : b.x + b.w, y: midY(b, bi) };
  const mx = (p1.x + p2.x) / 2;
  const dd = dashed ? ' stroke-dasharray="5 4"' : '';
  let s = `<path d="M${p1.x} ${p1.y} L${mx} ${p1.y} L${mx} ${p2.y} L${p2.x} ${p2.y}" fill="none" stroke="#94a3b8" stroke-width="1.5"${dd} marker-start="url(#many)" marker-end="url(#one)"/>`;
  const ly = (p1.y + p2.y) / 2;
  s += `<rect x="${mx - 17}" y="${ly - 9}" width="34" height="16" rx="3" fill="#ffffff" stroke="#cbd5e1"/><text x="${mx}" y="${ly + 3}" font-size="9" fill="#475569" text-anchor="middle">${label}</text>`;
  return s;
}
// relacje dziecko → rodzic (N:1); offers→templates jest logiczna bez indeksu (przerywana)
svg += conn(byId.offers, idx(byId.offers, 'template_id'), byId.templates, 0, 'N:1', true);
svg += conn(byId.items, idx(byId.items, 'offer_id'), byId.offers, 0, 'N:1', false);
svg += conn(byId.versions, idx(byId.versions, 'offer_id'), byId.offers, 0, 'N:1', false);
svg += conn(byId.log, idx(byId.log, 'offer_id'), byId.offers, 0, 'N:1', false);

// zewnętrzny węzeł Pluginu 1 + miękkie odniesienie lead_id
const p1x = 60, p1y = 470, p1w = 300, p1h = 66;
svg += `<rect x="${p1x}" y="${p1y}" width="${p1w}" height="${p1h}" rx="8" fill="#f1f5f9" stroke="#64748b" stroke-width="1.4" stroke-dasharray="6 4"/>`;
svg += `<text x="${p1x + 14}" y="${p1y + 26}" font-size="12.5" font-weight="bold" fill="#334155">wp_mp_leads — Plugin 1 (BD-3)</text>`;
svg += `<text x="${p1x + 14}" y="${p1y + 47}" font-size="10.5" fill="#64748b">miękkie odniesienie przez lead_id (izolacja wtyczek)</text>`;
(() => {
  const p = { x: byId.offers.x, y: midY(byId.offers, idx(byId.offers, 'lead_id')) };
  const q = { x: p1x + p1w, y: p1y + p1h / 2 };
  const mx = (p.x + q.x) / 2;
  svg += `<path d="M${p.x} ${p.y} L${mx} ${p.y} L${mx} ${q.y} L${q.x} ${q.y}" fill="none" stroke="#94a3b8" stroke-width="1.4" stroke-dasharray="5 4" marker-end="url(#one)"/>`;
})();

function svgTable(t) {
  const H = th(t);
  let s = `<text x="${t.x}" y="${t.y - 7}" font-size="11" fill="#64748b">${esc(t.sub)}</text>`;
  s += `<rect x="${t.x}" y="${t.y}" width="${t.w}" height="${H}" fill="#ffffff" stroke="${t.hs}" stroke-width="1.4"/>`;
  s += `<rect x="${t.x}" y="${t.y}" width="${t.w}" height="${HDR}" fill="${t.hdr}"/>`;
  s += `<text x="${t.x + 12}" y="${t.y + 20}" font-size="12.5" font-weight="bold" fill="#ffffff">${esc(t.title)}</text>`;
  t.cols.forEach((c, i) => {
    const ry = t.y + HDR + i * RH, isNew = c[3] === NEW;
    if (isNew) s += `<rect x="${t.x + 1}" y="${ry}" width="${t.w - 2}" height="${RH}" fill="#fffbeb"/>`;
    s += `<line x1="${t.x}" y1="${ry + RH}" x2="${t.x + t.w}" y2="${ry + RH}" stroke="#eef2f7" stroke-width="1"/>`;
    const nc = isNew ? '#92600a' : '#1f2a44', tc = isNew ? '#b45309' : '#64748b';
    s += `<text x="${t.x + 10}" y="${ry + 15}" font-size="11" fill="${nc}"${isNew ? ' font-weight="bold"' : ''}>${esc(c[0])}${isNew ? ' ●' : ''}</text>`;
    const tt = c[2] ? `${c[1]} · ${c[2]}` : c[1];
    s += `<text x="${t.x + t.w - 10}" y="${ry + 15}" font-size="10" fill="${tc}" text-anchor="end">${esc(tt)}</text>`;
  });
  return s;
}
tables.forEach((t) => { svg += svgTable(t); });

// legenda
const ly = PH - 26;
svg += `<text x="40" y="${ly}" font-size="10.5" fill="#64748b">Legenda:</text>`;
const leg = [['PK', 'klucz główny'], ['UQ', 'unikalny'], ['UQ*', 'unikalny łącznie (offer_number+version)'], ['K', 'indeks / kolumna wiążąca'], ['●', 'dodane w audycie v1.0.3']];
let lx = 100;
leg.forEach(([k, d]) => { svg += `<text x="${lx}" y="${ly}" font-size="10" fill="#1f2a44" font-weight="bold">${k}</text><text x="${lx + (k === '●' ? 14 : k.length * 7 + 6)}" y="${ly}" font-size="10" fill="#475569">${esc(d)}</text>`; lx += 44 + k.length * 7 + d.length * 5.5; });
svg += `</svg>`;
fs.writeFileSync(OUT + '.svg', svg);

/* ============================ .drawio ============================ */
function dTable(t) {
  const nw = NAMEW(t);
  let s = `<mxCell id="cap_${t.id}" value="${escA(t.sub)}" style="text;html=1;fontSize=11;fontColor=#64748b;align=left;" vertex="1" parent="1"><mxGeometry x="${t.x}" y="${t.y - 22}" width="${t.w}" height="18" as="geometry"/></mxCell>`;
  s += `<mxCell id="${t.id}" value="${escA(t.title)}" style="shape=table;startSize=${HDR};container=1;collapsible=0;childLayout=tableLayout;fillColor=${t.hdr};strokeColor=${t.hs};fontColor=#ffffff;fontStyle=1;fontSize=13" vertex="1" parent="1"><mxGeometry x="${t.x}" y="${t.y}" width="${t.w}" height="${th(t)}" as="geometry"/></mxCell>`;
  t.cols.forEach((c, i) => {
    const rid = `${t.id}_r${i}`, isNew = c[3] === NEW;
    const rowFill = isNew ? '#fffbeb' : 'none';
    s += `<mxCell id="${rid}" style="shape=tableRow;horizontal=0;startSize=0;swimlaneHead=0;swimlaneBody=0;fillColor=${rowFill};strokeColor=#e2e8f0" vertex="1" parent="${t.id}"><mxGeometry y="${HDR + i * RH}" width="${t.w}" height="${RH}" as="geometry"/></mxCell>`;
    const nc = isNew ? '#92600a' : '#1f2a44', tc = isNew ? '#b45309' : '#64748b';
    const nameVal = escA(c[0]) + (isNew ? ' ●' : '');
    const typeVal = escA(c[2] ? `${c[1]} · ${c[2]}` : c[1]);
    s += `<mxCell id="${rid}a" value="${nameVal}" style="shape=partialRectangle;html=1;whiteSpace=wrap;connectable=0;fillColor=none;strokeColor=none;align=left;spacingLeft=8;fontColor=${nc}${isNew ? ';fontStyle=1' : ''}" vertex="1" parent="${rid}"><mxGeometry width="${nw}" height="${RH}" as="geometry"/></mxCell>`;
    s += `<mxCell id="${rid}b" value="${typeVal}" style="shape=partialRectangle;html=1;whiteSpace=wrap;connectable=0;fillColor=none;strokeColor=none;align=right;spacingRight=8;fontColor=${tc}" vertex="1" parent="${rid}"><mxGeometry x="${nw}" width="${t.w - nw}" height="${RH}" as="geometry"/></mxCell>`;
  });
  return s;
}
function dEdge(src, tgt, label, dashed) {
  const style = dashed
    ? `edgeStyle=entityRelationEdgeStyle;fontSize=9;html=1;endArrow=open;startArrow=none;dashed=1;rounded=0;strokeColor=#94a3b8`
    : `edgeStyle=entityRelationEdgeStyle;fontSize=9;html=1;endArrow=ERone;startArrow=ERmany;rounded=0;strokeColor=#94a3b8`;
  return `<mxCell id="e_${src}_${tgt}" value="${escA(label)}" style="${style}" edge="1" parent="1" source="${src}" target="${tgt}"><mxGeometry relative="1" as="geometry"/></mxCell>`;
}

let cells = `<mxCell id="t1" value="Schemat bazy danych — BD-2 (MP Offer Builder)" style="text;html=1;fontSize=18;fontStyle=1;fontColor=#1f2a44;" vertex="1" parent="1"><mxGeometry x="40" y="16" width="800" height="30" as="geometry"/></mxCell>`;
cells += `<mxCell id="t2" value="5 tabel · InnoDB · utf8mb4 · kwoty w groszach (bigint) · DB_VERSION 0.7.0 · relacje logiczne (bez twardych FK)" style="text;html=1;fontSize=11;fontColor=#64748b;" vertex="1" parent="1"><mxGeometry x="40" y="46" width="900" height="20" as="geometry"/></mxCell>`;
cells += `<mxCell id="p1leads" value="wp_mp_leads — Plugin 1 (BD-3)&#10;miękkie odniesienie przez lead_id (izolacja wtyczek)" style="rounded=1;whiteSpace=wrap;html=1;dashed=1;fillColor=#f1f5f9;strokeColor=#64748b;fontColor=#334155;fontSize=11;align=left;spacingLeft=10;verticalAlign=middle;" vertex="1" parent="1"><mxGeometry x="60" y="470" width="300" height="66" as="geometry"/></mxCell>`;
tables.forEach((t) => { cells += dTable(t); });
cells += dEdge('offers', 'templates', 'N:1 (logiczna)', true);
cells += dEdge('items', 'offers', 'N:1');
cells += dEdge('versions', 'offers', 'N:1');
cells += dEdge('log', 'offers', 'N:1');
cells += dEdge('offers', 'p1leads', 'lead_id (miękkie)', true);

const drawio = `<mxfile host="app.diagrams.net" type="device"><diagram name="ERD BD-2"><mxGraphModel dx="1100" dy="820" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1200" pageHeight="860" math="0" shadow="0"><root><mxCell id="0"/><mxCell id="1" parent="0"/>${cells}</root></mxGraphModel></diagram></mxfile>`;
fs.writeFileSync(OUT + '.drawio', drawio);
console.log('OK', OUT, '| tabele:', tables.length, '| offers kolumny:', byId.offers.cols.length);
