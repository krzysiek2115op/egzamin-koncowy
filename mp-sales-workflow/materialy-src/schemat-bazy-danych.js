// Generator schematu BD-1 (ERD) → .drawio (kształty table/tableRow, edytowalny
// w draw.io) + .svg (→ PDF przez rsvg-convert). JEDNO źródło = realny CREATE
// TABLE z includes/db/class-mp-sales-workflow-db.php (DB_VERSION 0.3.0).
//
// Uwaga o więzach: dbDelta NIE tworzy kluczy obcych, więc dwa twarde więzy
// (tasks.flow_id, notifications.flow_id → flow.id ON DELETE CASCADE) zakłada
// osobny ALTER w maybe_add_foreign_keys() — tylko na InnoDB i tylko gdy nie ma
// wierszy-sierot. Dziennik aktywności i rejestr zdarzeń więzu NIE mają celowo:
// audyt ma przetrwać usunięcie procesu.
//
// Użycie: node schemat-bazy-danych.js out/schemat-bazy-danych
const fs = require('fs');
const OUT = process.argv[2] || 'out/schemat-bazy-danych';

const RH = 22;      // wysokość wiersza
const HDR = 30;     // wysokość nagłówka tabeli
const SEC = 'SEC';  // znacznik kolumny dodanej przy utwardzeniu (0.3.0)

// [nazwa, typ, tag(PK|UQ|K|K→P1|K→P2|''), SEC?]
const tables = [
  {
    id: 'flow', title: 'wp_mp_sw_flow', sub: 'Proces sprzedażowy — jeden wiersz na leada',
    x: 430, y: 110, w: 310, hdr: '#2563eb', hs: '#1d4ed8',
    cols: [
      ['id', 'bigint', 'PK'], ['lead_id', 'bigint', 'UQ → P1'], ['offer_id', 'bigint', '→ P2'],
      ['offer_number', 'varchar(32)', ''], ['status', 'varchar(32)', 'K'],
      ['assigned_user_id', 'bigint', 'K'], ['assigned_at', 'datetime', ''],
      ['assign_reason', 'varchar(255)', ''], ['assign_fallback', 'tinyint(1)', ''],
      ['sla_due_at', 'datetime', 'K'], ['lang', 'varchar(5)', ''], ['country', 'char(2)', ''],
      ['segment', 'varchar(64)', ''], ['client_name', 'varchar(191)', ''],
      ['client_email', 'varchar(191)', ''], ['lock_version', 'bigint', ''],
      ['created_at', 'datetime', ''], ['updated_at', 'datetime', 'K'],
    ],
  },
  {
    id: 'tasks', title: 'wp_mp_sw_tasks', sub: 'Zadania follow-up d+3 / d+7',
    x: 60, y: 110, w: 310, hdr: '#f59e0b', hs: '#d97706',
    cols: [
      ['id', 'bigint', 'PK'], ['flow_id', 'bigint', 'FK'], ['type', 'varchar(32)', ''],
      ['due_at', 'datetime', 'K'], ['guard_status', 'varchar(32)', ''], ['status', 'varchar(20)', ''],
      ['assignee', 'bigint', ''], ['event_id', 'char(36)', 'K'], ['open_key', 'varchar(64)', 'UQ'],
      ['claimed_at', 'datetime', 'K', SEC], ['claim_token', 'char(36)', '', SEC],
      ['created_at', 'datetime', ''], ['updated_at', 'datetime', ''],
    ],
  },
  {
    id: 'notif', title: 'wp_mp_sw_notifications', sub: 'Kolejka powiadomień e-mail',
    x: 800, y: 110, w: 310, hdr: '#10b981', hs: '#059669',
    cols: [
      ['id', 'bigint', 'PK'], ['flow_id', 'bigint', 'FK'], ['event_id', 'char(36)', 'K'],
      ['template', 'varchar(64)', ''], ['template_version', 'varchar(16)', ''],
      ['lang', 'varchar(5)', ''], ['recipient', 'varchar(191)', ''],
      ['recipient_user_id', 'bigint', ''], ['subject', 'varchar(255)', ''], ['body', 'longtext', ''],
      ['status', 'varchar(20)', 'K'], ['attempts', 'smallint', ''],
      ['last_error', 'varchar(255)', ''], ['sent_at', 'datetime', ''],
      ['created_at', 'datetime', 'K'], ['updated_at', 'datetime', ''],
    ],
  },
  {
    id: 'events', title: 'wp_mp_sw_events', sub: 'Rejestr zdarzeń — idempotencja',
    x: 60, y: 560, w: 310, hdr: '#7c3aed', hs: '#6d28d9',
    cols: [
      ['id', 'bigint', 'PK'], ['event_id', 'char(36)', 'UQ'], ['type', 'varchar(64)', 'K'],
      ['lead_id', 'bigint', ''], ['offer_id', 'bigint', ''], ['actor_id', 'bigint', ''],
      ['status', 'varchar(20)', ''], ['result_json', 'longtext', ''], ['trace_id', 'char(36)', ''],
      ['created_at', 'datetime', 'K'], ['updated_at', 'datetime', ''],
    ],
  },
  {
    id: 'act', title: 'wp_mp_sw_activity', sub: 'Dziennik aktywności (kryterium 5.5)',
    x: 800, y: 560, w: 310, hdr: '#334155', hs: '#1f2a44',
    cols: [
      ['id', 'bigint', 'PK'], ['event_id', 'char(36)', 'K'], ['flow_id', 'bigint', 'K'],
      ['entity_ref', 'varchar(64)', ''], ['action', 'varchar(64)', 'K'], ['old_value', 'text', ''],
      ['new_value', 'text', ''], ['actor_type', 'varchar(20)', ''], ['actor_id', 'bigint', ''],
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
const PW = 1170, PH = 900;
let svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${PW}" height="${PH}" viewBox="0 0 ${PW} ${PH}" font-family="DejaVu Sans, Arial, sans-serif">`;
svg += `<rect width="${PW}" height="${PH}" fill="#ffffff"/>`;
svg += `<defs><marker id="one" markerWidth="14" markerHeight="14" refX="11" refY="7" orient="auto"><path d="M6 2 L6 12" stroke="#94a3b8" stroke-width="1.6"/></marker>`;
svg += `<marker id="many" markerWidth="16" markerHeight="16" refX="2" refY="8" orient="auto"><path d="M14 8 L2 2 M14 8 L2 14 M14 8 L2 8" stroke="#94a3b8" stroke-width="1.4" fill="none"/></marker></defs>`;
svg += `<text x="40" y="42" font-size="20" font-weight="bold" fill="#1f2a44">Schemat bazy danych — BD-1 (MP Sales Workflow)</text>`;
svg += `<text x="40" y="66" font-size="12" fill="#64748b">5 tabel · InnoDB · utf8mb4 · czas w GMT (datetime) · DB_VERSION 0.3.0 · dwa twarde więzy ON DELETE CASCADE, reszta relacji logiczna</text>`;
svg += `<text x="40" y="86" font-size="11" fill="#94a3b8">Wtyczka 3 nie czyta tabel wtyczek 1 i 2 przez klucze obce — granice modułów biegną po zdarzeniach.</text>`;

function midY(t, i) { return t.y + HDR + i * RH + RH / 2; }
function conn(a, ai, b, bi, label, dashed) {
  const acx = a.x + a.w / 2, bcx = b.x + b.w / 2;
  const p1 = { x: bcx > acx ? a.x + a.w : a.x, y: midY(a, ai) };
  const p2 = { x: bcx > acx ? b.x : b.x + b.w, y: midY(b, bi) };
  const mx = (p1.x + p2.x) / 2;
  const dd = dashed ? ' stroke-dasharray="5 4"' : '';
  let s = `<path d="M${p1.x} ${p1.y} L${mx} ${p1.y} L${mx} ${p2.y} L${p2.x} ${p2.y}" fill="none" stroke="#94a3b8" stroke-width="1.5"${dd} marker-start="url(#many)" marker-end="url(#one)"/>`;
  const ly = (p1.y + p2.y) / 2;
  const bw = label.length * 5.2 + 12;
  s += `<rect x="${mx - bw / 2}" y="${ly - 9}" width="${bw}" height="16" rx="3" fill="#ffffff" stroke="#cbd5e1"/><text x="${mx}" y="${ly + 3}" font-size="9" fill="#475569" text-anchor="middle">${esc(label)}</text>`;
  return s;
}
// dziecko → rodzic (N:1). Twarde FK ciągłą linią, relacje logiczne przerywaną.
svg += conn(byId.tasks, idx(byId.tasks, 'flow_id'), byId.flow, 0, 'N:1 · FK', false);
svg += conn(byId.notif, idx(byId.notif, 'flow_id'), byId.flow, 0, 'N:1 · FK', false);
svg += conn(byId.act, idx(byId.act, 'flow_id'), byId.flow, 0, 'N:1 · bez FK', true);
// Rejestr zdarzeń nie jest dzieckiem procesu — wiąże go ten sam `lead_id`, bez
// więzu. Rysowanie tego strzałką sugerowałoby relację, której w bazie nie ma.
svg += `<text x="${byId.events.x}" y="${byId.events.y + th(byId.events) + 18}" font-size="10.5" fill="#64748b">Bez więzu do procesu — wspólny jest tylko lead_id.</text>`;

// zewnętrzne źródła danych (wtyczki 1 i 2) — odniesienia miękkie
function extBox(x, y, w, h, title, note) {
  let s = `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="8" fill="#f1f5f9" stroke="#64748b" stroke-width="1.4" stroke-dasharray="6 4"/>`;
  s += `<text x="${x + 14}" y="${y + 26}" font-size="12.5" font-weight="bold" fill="#334155">${esc(title)}</text>`;
  s += `<text x="${x + 14}" y="${y + 47}" font-size="10.5" fill="#64748b">${esc(note)}</text>`;
  return s;
}
const EX = 430, EW = 310;
svg += extBox(EX, 620, EW, 66, 'wp_mp_leads — Plugin 1 (BD-3)', 'adres i firma klienta czytane po lead_id');
svg += extBox(EX, 720, EW, 66, 'wp_mp_ob_offers — Plugin 2 (BD-2)', 'ścieżka PDF i numer oferty czytane po offer_id');
(() => {
  const p = { x: byId.flow.x + byId.flow.w / 2, y: byId.flow.y + th(byId.flow) };
  svg += `<path d="M${p.x} ${p.y} L${p.x} 620" fill="none" stroke="#94a3b8" stroke-width="1.4" stroke-dasharray="5 4" marker-end="url(#one)"/>`;
  svg += `<path d="M${p.x + 90} 686 L${p.x + 90} 720" fill="none" stroke="#94a3b8" stroke-width="1.4" stroke-dasharray="5 4" marker-end="url(#one)"/>`;
})();

function svgTable(t) {
  const H = th(t);
  let s = `<text x="${t.x}" y="${t.y - 7}" font-size="11" fill="#64748b">${esc(t.sub)}</text>`;
  s += `<rect x="${t.x}" y="${t.y}" width="${t.w}" height="${H}" fill="#ffffff" stroke="${t.hs}" stroke-width="1.4"/>`;
  s += `<rect x="${t.x}" y="${t.y}" width="${t.w}" height="${HDR}" fill="${t.hdr}"/>`;
  s += `<text x="${t.x + 12}" y="${t.y + 20}" font-size="12.5" font-weight="bold" fill="#ffffff">${esc(t.title)}</text>`;
  t.cols.forEach((c, i) => {
    const ry = t.y + HDR + i * RH, isNew = c[3] === SEC;
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
const leg = [['PK', 'klucz główny'], ['UQ', 'unikalny'], ['FK', 'twardy więz ON DELETE CASCADE'], ['K', 'indeks'], ['●', 'dodane przy utwardzeniu 0.3.0']];
let lx = 100;
leg.forEach(([k, d]) => { svg += `<text x="${lx}" y="${ly}" font-size="10" fill="#1f2a44" font-weight="bold">${k}</text><text x="${lx + (k === '●' ? 14 : k.length * 7 + 6)}" y="${ly}" font-size="10" fill="#475569">${esc(d)}</text>`; lx += 40 + k.length * 7 + d.length * 5.5; });
svg += `</svg>`;
fs.writeFileSync(OUT + '.svg', svg);

/* ============================ .drawio ============================ */
function dTable(t) {
  const nw = NAMEW(t);
  let s = `<mxCell id="cap_${t.id}" value="${escA(t.sub)}" style="text;html=1;fontSize=11;fontColor=#64748b;align=left;" vertex="1" parent="1"><mxGeometry x="${t.x}" y="${t.y - 22}" width="${t.w}" height="18" as="geometry"/></mxCell>`;
  s += `<mxCell id="${t.id}" value="${escA(t.title)}" style="shape=table;startSize=${HDR};container=1;collapsible=0;childLayout=tableLayout;fillColor=${t.hdr};strokeColor=${t.hs};fontColor=#ffffff;fontStyle=1;fontSize=13" vertex="1" parent="1"><mxGeometry x="${t.x}" y="${t.y}" width="${t.w}" height="${th(t)}" as="geometry"/></mxCell>`;
  t.cols.forEach((c, i) => {
    const rid = `${t.id}_r${i}`, isNew = c[3] === SEC;
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

let cells = `<mxCell id="t1" value="Schemat bazy danych — BD-1 (MP Sales Workflow)" style="text;html=1;fontSize=18;fontStyle=1;fontColor=#1f2a44;" vertex="1" parent="1"><mxGeometry x="40" y="16" width="820" height="30" as="geometry"/></mxCell>`;
cells += `<mxCell id="t2" value="5 tabel · InnoDB · utf8mb4 · czas w GMT · DB_VERSION 0.3.0 · dwa twarde więzy ON DELETE CASCADE, reszta relacji logiczna" style="text;html=1;fontSize=11;fontColor=#64748b;" vertex="1" parent="1"><mxGeometry x="40" y="46" width="980" height="20" as="geometry"/></mxCell>`;
cells += `<mxCell id="p1leads" value="wp_mp_leads — Plugin 1 (BD-3)&#10;adres i firma klienta czytane po lead_id" style="rounded=1;whiteSpace=wrap;html=1;dashed=1;fillColor=#f1f5f9;strokeColor=#64748b;fontColor=#334155;fontSize=11;align=left;spacingLeft=10;verticalAlign=middle;" vertex="1" parent="1"><mxGeometry x="430" y="620" width="310" height="66" as="geometry"/></mxCell>`;
cells += `<mxCell id="p2offers" value="wp_mp_ob_offers — Plugin 2 (BD-2)&#10;ścieżka PDF i numer oferty czytane po offer_id" style="rounded=1;whiteSpace=wrap;html=1;dashed=1;fillColor=#f1f5f9;strokeColor=#64748b;fontColor=#334155;fontSize=11;align=left;spacingLeft=10;verticalAlign=middle;" vertex="1" parent="1"><mxGeometry x="430" y="720" width="310" height="66" as="geometry"/></mxCell>`;
cells += `<mxCell id="evnote" value="Bez więzu do procesu — wspólny jest tylko lead_id." style="text;html=1;fontSize=10;fontColor=#64748b;align=left;" vertex="1" parent="1"><mxGeometry x="60" y="${560 + th(byId.events) + 6}" width="330" height="18" as="geometry"/></mxCell>`;
tables.forEach((t) => { cells += dTable(t); });
cells += dEdge('tasks', 'flow', 'N:1 · FK CASCADE');
cells += dEdge('notif', 'flow', 'N:1 · FK CASCADE');
cells += dEdge('act', 'flow', 'N:1 · bez FK (audyt przeżywa)', true);
cells += dEdge('flow', 'p1leads', 'lead_id (miękkie)', true);
cells += dEdge('flow', 'p2offers', 'offer_id (miękkie)', true);

const drawio = `<mxfile host="app.diagrams.net" type="device"><diagram name="ERD BD-1"><mxGraphModel dx="1100" dy="900" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1200" pageHeight="920" math="0" shadow="0"><root><mxCell id="0"/><mxCell id="1" parent="0"/>${cells}</root></mxGraphModel></diagram></mxfile>`;
fs.writeFileSync(OUT + '.drawio', drawio);
console.log('OK', OUT, '| tabele:', tables.length, '| flow kolumny:', byId.flow.cols.length);
