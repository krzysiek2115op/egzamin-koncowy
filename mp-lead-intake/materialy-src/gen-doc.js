// Reużywalny generator dokumentów A4 (PDF) — pdf-lib + osadzony DejaVu Sans.
// Auto-zawijanie tekstu, paginacja, nagłówki/stopki, ramki-uwagi.
// Użycie: node gen-doc.js <content.json> <output.pdf>
const fs = require('fs');
const { PDFDocument, rgb } = require('pdf-lib');
const fontkit = require('@pdf-lib/fontkit');

const REG = '/usr/share/fonts/TTF/DejaVuSans.ttf';
const BLD = '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf';

const A4 = [595.28, 841.89];
const M = { left: 56, right: 56, top: 64, bottom: 58 };
const CW = A4[0] - M.left - M.right; // szerokość kolumny

function hex(h) {
  const n = h.replace('#', '');
  return rgb(parseInt(n.slice(0, 2), 16) / 255, parseInt(n.slice(2, 4), 16) / 255, parseInt(n.slice(4, 6), 16) / 255);
}
const INK = hex('1f2a44');
const MUT = hex('5b6b86');

// Wersja wtyczki czytana Z NAGŁÓWKA jej pliku głównego, nie wpisywana w JSON.
// Stopki materiałów mówiły „v1.0.3" i „v1.2.3", gdy wtyczki miały od dawna
// 1.3.x: numer wpisany z ręki starzeje się przy każdym wydaniu, a jedynym
// czytelnikiem tej pomyłki jest klient trzymający w ręku wydruk.
function wersjaWtyczki() {
  const katalog = require('path').resolve(__dirname, '..');
  for (const plik of fs.readdirSync(katalog)) {
    if (!plik.endsWith('.php')) continue;
    const naglowek = fs.readFileSync(require('path').join(katalog, plik), 'utf8').slice(0, 4096);
    const m = naglowek.match(/^\s*\*\s*Version:\s*(\S+)/m);
    if (m) return m[1];
  }
  throw new Error('Nie znalazlem naglowka Version: w pliku glownym wtyczki (' + katalog + ')');
}

async function main() {
  const spec = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
  const out = process.argv[3];
  const accent = hex(spec.accent || '2563eb');
  const wersja = wersjaWtyczki();

  for (const pole of ['title', 'subtitle', 'footer']) {
    if (typeof spec[pole] === 'string') spec[pole] = spec[pole].split('{wersja}').join(wersja);
  }

  const doc = await PDFDocument.create();
  doc.registerFontkit(fontkit);
  const reg = await doc.embedFont(fs.readFileSync(REG), { subset: true });
  const bld = await doc.embedFont(fs.readFileSync(BLD), { subset: true });

  let page, y;
  let pageNo = 0;
  const pages = [];

  function newPage() {
    page = doc.addPage(A4);
    pages.push(page);
    pageNo++;
    y = A4[1] - M.top;
  }
  function ensure(h) { if (y - h < M.bottom) newPage(); }

  function wrap(text, font, size, maxw) {
    const words = String(text).split(/\s+/);
    const lines = [];
    let cur = '';
    for (const w of words) {
      const test = cur ? cur + ' ' + w : w;
      if (font.widthOfTextAtSize(test, size) > maxw && cur) { lines.push(cur); cur = w; }
      else cur = test;
    }
    if (cur) lines.push(cur);
    return lines;
  }
  function drawLines(text, { font, size, color, x = M.left, maxw = CW, lead = 1.42, gap = 0 }) {
    for (const ln of wrap(text, font, size, maxw)) {
      ensure(size * lead);
      page.drawText(ln, { x, y: y - size, size, font, color });
      y -= size * lead;
    }
    y -= gap;
  }

  newPage();

  // Blok tytułowy (str. 1)
  if (spec.title) {
    page.drawRectangle({ x: M.left, y: y - 6, width: 46, height: 6, color: accent });
    y -= 26;
    drawLines(spec.title, { font: bld, size: 23, color: INK, lead: 1.2, gap: 2 });
    if (spec.subtitle) drawLines(spec.subtitle, { font: reg, size: 12.5, color: MUT, lead: 1.35, gap: 8 });
    page.drawLine({ start: { x: M.left, y: y }, end: { x: A4[0] - M.right, y }, thickness: 0.8, color: hex('e2e8f0') });
    y -= 18;
  }

  for (const b of spec.blocks) {
    switch (b.t) {
      case 'h1':
        y -= 6; ensure(40);
        page.drawRectangle({ x: M.left, y: y - 4, width: 22, height: 4, color: accent });
        y -= 18;
        drawLines(b.text, { font: bld, size: 16, color: INK, lead: 1.2, gap: 6 });
        break;
      case 'h2':
        y -= 4; ensure(28);
        drawLines(b.text, { font: bld, size: 12.5, color: accent, lead: 1.25, gap: 4 });
        break;
      case 'p':
        drawLines(b.text, { font: reg, size: 10.5, color: INK, lead: 1.5, gap: 6 });
        break;
      case 'bullet': {
        ensure(15);
        page.drawText('•', { x: M.left + 4, y: y - 10.5, size: 10.5, font: bld, color: accent });
        const save = M.left; const x = M.left + 18;
        for (const ln of wrap(b.text, reg, 10.5, CW - 18)) {
          ensure(10.5 * 1.5);
          page.drawText(ln, { x, y: y - 10.5, size: 10.5, font: reg, color: INK });
          y -= 10.5 * 1.5;
        }
        y -= 4;
        break;
      }
      case 'num': {
        ensure(15);
        page.drawText(String(b.n) + '.', { x: M.left + 2, y: y - 10.5, size: 10.5, font: bld, color: accent });
        const x = M.left + 22;
        for (const ln of wrap(b.text, reg, 10.5, CW - 22)) {
          ensure(10.5 * 1.5);
          page.drawText(ln, { x, y: y - 10.5, size: 10.5, font: reg, color: INK });
          y -= 10.5 * 1.5;
        }
        y -= 4;
        break;
      }
      case 'note': {
        const inner = CW - 24;
        const lines = [];
        if (b.title) lines.push({ f: bld, s: 10.5, t: b.title });
        for (const ln of wrap(b.text, reg, 10, inner)) lines.push({ f: reg, s: 10, t: ln });
        const boxh = 20 + lines.length * 14.5;
        ensure(boxh + 6);
        const top = y;
        page.drawRectangle({ x: M.left, y: top - boxh, width: CW, height: boxh, color: hex(b.bg || 'fef3c7'), borderColor: hex(b.bc || 'f59e0b'), borderWidth: 1 });
        page.drawRectangle({ x: M.left, y: top - boxh, width: 4, height: boxh, color: hex(b.bc || 'f59e0b') });
        let yy = top - 16;
        for (const l of lines) { page.drawText(l.t, { x: M.left + 14, y: yy - l.s, size: l.s, font: l.f, color: INK }); yy -= 14.5; }
        y = top - boxh - 10;
        break;
      }
      case 'space': y -= (b.h || 10); break;
      case 'pagebreak': newPage(); break;
    }
  }

  // Stopki
  const total = pages.length;
  pages.forEach((p, i) => {
    p.drawLine({ start: { x: M.left, y: M.bottom - 14 }, end: { x: A4[0] - M.right, y: M.bottom - 14 }, thickness: 0.6, color: hex('e2e8f0') });
    if (spec.footer) p.drawText(spec.footer, { x: M.left, y: M.bottom - 26, size: 8, font: reg, color: MUT });
    const pn = `${i + 1} / ${total}`;
    p.drawText(pn, { x: A4[0] - M.right - reg.widthOfTextAtSize(pn, 8), y: M.bottom - 26, size: 8, font: reg, color: MUT });
  });

  fs.writeFileSync(out, await doc.save());
  console.log('OK', out, total, 'stron');
}
main().catch(e => { console.error(e); process.exit(1); });
