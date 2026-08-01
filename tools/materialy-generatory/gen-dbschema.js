// Generator schematu BD-2 → SVG (do PDF) + .drawio (edytowalny). Jedno źródło.
const fs = require('fs');
const OUT = process.argv[2] || 'p2/schemat-bazy-danych';

// Specyfikacja tabel: [nazwa, [ [kolumna, typ, tag] ... ]]  tag: PK|UQ|K|FK|''
const tables = {
  templates: {
    title: 'wp_mp_ob_offer_templates', x: 40, y: 70, sub: 'Szablony ofert (HTML + znaczniki)',
    cols: [
      ['id', 'bigint', 'PK'], ['name', 'varchar(191)', ''], ['lang', 'varchar(5)', 'K'],
      ['content', 'longtext', ''], ['variables', 'longtext', ''], ['version', 'varchar(20)', ''],
      ['status', 'varchar(20)', 'K'], ['created_at', 'datetime', ''],
    ],
  },
  offers: {
    title: 'wp_mp_ob_offers', x: 40, y: 300, sub: 'Oferta (nagłówek, klient, kwoty)',
    cols: [
      ['id', 'bigint', 'PK'], ['offer_number', 'varchar(30)', 'UQ'], ['version', 'int', 'UQ'],
      ['lock_version', 'int', ''], ['status', 'varchar(20)', 'K'], ['lang', 'varchar(5)', ''],
      ['lead_id', 'bigint', 'K→P1'], ['client_name', 'varchar(191)', ''], ['client_email', 'varchar(191)', ''],
      ['client_nip', 'varchar(20)', ''], ['client_country', 'char(2)', ''], ['client_vat_status', 'varchar(20)', ''],
      ['net_grosze', 'bigint', ''], ['vat_grosze', 'bigint', ''], ['gross_grosze', 'bigint', ''],
      ['currency', 'char(3)', ''], ['tax_mechanism', 'varchar(20)', ''], ['template_id', 'bigint', 'FK'],
      ['pdf_path', 'varchar(255)', ''], ['pdf_sha256', 'char(64)', ''], ['request_id', 'char(36)', 'UQ'],
      ['created_by', 'bigint', 'K'], ['created_at', 'datetime', ''], ['updated_at', 'datetime', ''],
    ],
  },
  items: {
    title: 'wp_mp_ob_offer_items', x: 620, y: 70, sub: 'Pozycje oferty (produkty, ceny)',
    cols: [
      ['id', 'bigint', 'PK'], ['offer_id', 'bigint', 'FK'], ['product_id', 'bigint', ''],
      ['variation_id', 'bigint', ''], ['qty', 'int', ''], ['price_base_grosze', 'bigint', ''],
      ['discount_grosze', 'bigint', ''], ['price_final_grosze', 'bigint', ''], ['tax_rate', 'decimal(5,2)', ''],
    ],
  },
  versions: {
    title: 'wp_mp_ob_offer_versions', x: 620, y: 350, sub: 'Historia wersji (pełny snapshot)',
    cols: [
      ['id', 'bigint', 'PK'], ['offer_id', 'bigint', 'FK'], ['version_number', 'int', ''],
      ['data_json', 'longtext', ''], ['pdf_path', 'varchar(255)', ''], ['created_at', 'datetime', ''],
      ['created_by', 'bigint', ''], ['change_log', 'text', ''],
    ],
  },
  log: {
    title: 'wp_mp_ob_offer_activity_log', x: 620, y: 600, sub: 'Dziennik audytu (kto/co/kiedy)',
    cols: [
      ['id', 'bigint', 'PK'], ['offer_id', 'bigint', 'K'], ['action', 'varchar(100)', 'K'],
      ['description', 'text', ''], ['user_id', 'bigint', ''], ['meta_json', 'longtext', ''],
      ['created_at', 'datetime', 'K'],
    ],
  },
};

const W = 540, HDR = 46, ROW = 21;
const CANVAS_W = 1180, CANVAS_H = 960;
const tagColor = { PK: '#b45309', UQ: '#7c3aed', K: '#2563eb', FK: '#0e7490', 'K→P1': '#9333ea' };

function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function tblH(t) { return HDR + t.cols.length * ROW + 8; }

// ---------- SVG ----------
let svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${CANVAS_W}" height="${CANVAS_H}" viewBox="0 0 ${CANVAS_W} ${CANVAS_H}" font-family="DejaVu Sans">`;
svg += `<rect width="${CANVAS_W}" height="${CANVAS_H}" fill="#ffffff"/>`;
svg += `<text x="40" y="40" font-size="22" font-weight="bold" fill="#1f2a44">Schemat bazy danych — MP Offer Builder (BD-2)</text>`;

// relacje (rysujemy PRZED tabelami, żeby były pod spodem)
function edge(x1, y1, x2, y2, label, dashed) {
  const mx = (x1 + x2) / 2;
  svg += `<path d="M ${x1} ${y1} C ${mx} ${y1} ${mx} ${y2} ${x2} ${y2}" fill="none" stroke="#94a3b8" stroke-width="1.6"${dashed ? ' stroke-dasharray="5 4"' : ''}/>`;
  svg += `<circle cx="${x2}" cy="${y2}" r="3.2" fill="#94a3b8"/>`;
  if (label) svg += `<text x="${mx}" y="${(y1 + y2) / 2 - 4}" font-size="10.5" fill="#64748b" text-anchor="middle">${esc(label)}</text>`;
}
const O = tables.offers, T = tables.templates, I = tables.items, V = tables.versions, L = tables.log;
// offers.template_id -> templates.id
edge(O.x + W, O.y + HDR + 17 * ROW - 8, T.x + W, T.y + HDR + 4, 'template_id', false);
// items/versions/log .offer_id -> offers.id
edge(O.x + W, O.y + 30, I.x, I.y + HDR + ROW + 2, 'offer_id', false);
edge(O.x + W, O.y + 60, V.x, V.y + HDR + ROW + 2, 'offer_id', false);
edge(O.x + W, O.y + 90, L.x, L.y + HDR + ROW + 2, 'offer_id', false);

function drawTable(t) {
  const h = tblH(t);
  svg += `<rect x="${t.x}" y="${t.y}" width="${W}" height="${h}" rx="8" fill="#ffffff" stroke="#cbd5e1" stroke-width="1.4"/>`;
  svg += `<path d="M ${t.x} ${t.y + HDR} L ${t.x} ${t.y + 8} Q ${t.x} ${t.y} ${t.x + 8} ${t.y} L ${t.x + W - 8} ${t.y} Q ${t.x + W} ${t.y} ${t.x + W} ${t.y + 8} L ${t.x + W} ${t.y + HDR} Z" fill="#1f2a44"/>`;
  svg += `<text x="${t.x + 14}" y="${t.y + 20}" font-size="13.5" font-weight="bold" fill="#ffffff">${esc(t.title)}</text>`;
  svg += `<text x="${t.x + 14}" y="${t.y + 37}" font-size="10" fill="#c7d2fe">${esc(t.sub)}</text>`;
  t.cols.forEach((c, i) => {
    const ry = t.y + HDR + i * ROW;
    if (i % 2 === 1) svg += `<rect x="${t.x + 1}" y="${ry}" width="${W - 2}" height="${ROW}" fill="#f8fafc"/>`;
    svg += `<text x="${t.x + 14}" y="${ry + 15}" font-size="11" fill="#1f2a44"${c[2] === 'PK' ? ' font-weight="bold"' : ''}>${esc(c[0])}</text>`;
    svg += `<text x="${t.x + 215}" y="${ry + 15}" font-size="10" fill="#64748b">${esc(c[1])}</text>`;
    if (c[2]) {
      const col = tagColor[c[2]] || '#64748b';
      const tw = c[2].length * 6.4 + 12;
      svg += `<rect x="${t.x + W - tw - 12}" y="${ry + 3}" width="${tw}" height="15" rx="7.5" fill="${col}"/>`;
      svg += `<text x="${t.x + W - tw / 2 - 12}" y="${ry + 14}" font-size="9" font-weight="bold" fill="#ffffff" text-anchor="middle">${esc(c[2])}</text>`;
    }
  });
}
Object.values(tables).forEach(drawTable);

// legenda
const ly = 905;
svg += `<text x="40" y="${ly}" font-size="10.5" fill="#64748b">Legenda:</text>`;
const leg = [['PK', 'klucz główny'], ['UQ', 'unikalny (UNIQUE)'], ['K', 'indeks (KEY)'], ['FK', 'odniesienie do offers.id'], ['K→P1', 'miękkie odniesienie do wp_mp_leads (Plugin 1, bez FK)']];
let lx = 100;
leg.forEach(([k, d]) => {
  const col = tagColor[k] || '#64748b';
  svg += `<rect x="${lx}" y="${ly - 11}" width="${k.length * 6.4 + 12}" height="15" rx="7.5" fill="${col}"/>`;
  svg += `<text x="${lx + (k.length * 6.4 + 12) / 2}" y="${ly}" font-size="9" font-weight="bold" fill="#fff" text-anchor="middle">${esc(k)}</text>`;
  svg += `<text x="${lx + k.length * 6.4 + 18}" y="${ly}" font-size="10" fill="#475569">${esc(d)}</text>`;
  lx += k.length * 6.4 + 30 + d.length * 5.4;
});
svg += `<text x="40" y="${ly + 24}" font-size="10" fill="#94a3b8">Wszystkie tabele: ENGINE=InnoDB, utf8mb4. Kwoty w groszach (bigint) — zero błędów zaokrągleń typu float.</text>`;
svg += `</svg>`;
fs.writeFileSync(OUT + '.svg', svg);

// ---------- .drawio ----------
function cell(id, val, x, y, w, h, style) {
  return `<mxCell id="${id}" value="${val.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '&#10;')}" style="${style}" vertex="1" parent="1"><mxGeometry x="${x}" y="${y}" width="${w}" height="${h}" as="geometry"/></mxCell>`;
}
let cells = '';
cells += cell('title', 'Schemat bazy danych — MP Offer Builder (BD-2)', 40, 20, 700, 30, 'text;html=1;fontSize=18;fontStyle=1;fontColor=#1f2a44;');
const ids = {};
Object.entries(tables).forEach(([key, t]) => {
  const val = t.title + '\n' + t.sub + '\n\n' + t.cols.map(c => `${c[0]} : ${c[1]}${c[2] ? '  [' + c[2] + ']' : ''}`).join('\n');
  ids[key] = 'tb_' + key;
  cells += cell(ids[key], val, t.x, t.y, W, tblH(t), 'rounded=1;whiteSpace=wrap;html=1;fillColor=#ffffff;strokeColor=#1f2a44;fontColor=#1f2a44;align=left;verticalAlign=top;spacingLeft=10;spacingTop=8;fontSize=11;');
});
function dedge(a, b, label) {
  return `<mxCell id="e_${a}_${b}" value="${label}" style="html=1;endArrow=open;strokeColor=#94a3b8;fontSize=10;fontColor=#64748b" edge="1" parent="1" source="${ids[a]}" target="${ids[b]}"><mxGeometry relative="1" as="geometry"/></mxCell>`;
}
cells += dedge('offers', 'templates', 'template_id');
cells += dedge('items', 'offers', 'offer_id');
cells += dedge('versions', 'offers', 'offer_id');
cells += dedge('log', 'offers', 'offer_id');
const drawio = `<mxfile host="app.diagrams.net" type="device"><diagram name="Schemat bazy danych (BD-2)"><mxGraphModel dx="1100" dy="820" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1200" pageHeight="920" math="0" shadow="0"><root><mxCell id="0"/><mxCell id="1" parent="0"/>${cells}</root></mxGraphModel></diagram></mxfile>`;
fs.writeFileSync(OUT + '.drawio', drawio);
console.log('OK', OUT + '.svg', OUT + '.drawio');
