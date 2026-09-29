/* content-ui.js — QUY ƯỚC GIAO DIỆN cho mọi màn nội dung (Trang · Bài viết · Sản phẩm · Danh mục…).
   Một nguồn duy nhất: sửa ở đây là đổi mọi chỗ dùng. Không màn nào tự chế màu/khoảng cách/kiểu chữ.
   Bám token AdminKit (_ds/…/tokens/*.css) và CLAUDE.md §8.
   Bộ ba nguồn dùng chung:
     · ai-quota.js        (window.BSAI)  — gói, hạn ngạch, quy đổi "đủ khoảng N …"
     · content-workflow.js(window.BSCW)  — trạng thái, vai trò, quy trình duyệt
     · content-ui.js      (window.BSUI)  — token + pattern giao diện  ← file này
   Dùng: window.BSUI (+ event 'bs-ui-ready'). Mọi hàm trả về CHUỖI style để gắn inline. */
(function () {
  var T = {
    /* màu — chỉ lấy trong token AdminKit */
    brand: '#405189', brand600: '#364574',
    brandTint: 'rgba(64,81,137,0.06)', brandTint10: 'rgba(64,81,137,0.10)',
    brandTint14: 'rgba(64,81,137,0.14)', brandLine: 'rgba(64,81,137,0.22)',
    ring: '0 0 0 3px rgba(64,81,137,0.15)',
    canvas: '#f3f3f9', surface: '#fff', surfaceAlt: '#fcfcfd', fill: '#f3f6f9',
    line: '#e9ebec', lineSoft: '#eff2f7', hairline: '#f0f1f3',
    ink: '#343a40', body: '#495057', muted: '#878a99', faint: '#adb5bd', off: '#ced4da',
    success: '#0ab39c', info: '#299cdb', warn: '#f7b84b', warnInk: '#a9761d',
    danger: '#f06548', dangerInk: '#c9412a', secondary: '#3577f1',
    /* hình khối */
    rControl: '4px', rCard: '6px', rPill: '20px', rModal: '8px',
    shCard: '0 1px 2px rgb(56 65 74 / 0.15)', shPop: '0 5px 15px rgb(30 32 37 / 0.12)',
    shModal: '0 12px 32px rgba(56,65,74,0.22)',
    /* khoảng cách (bước 2px/4px) */
    sp: { xs: '4px', s: '6px', m: '8px', l: '12px', xl: '16px', xxl: '24px' },
    cardPad: '16px', cardHeadPad: '12px 16px', fieldGap: '16px', groupGap: '16px',
    /* chữ */
    fsH1: '20px', fsCardTitle: '13px', fsLabel: '12.5px', fsInput: '13px',
    fsMeta: '12px', fsMicro: '11.5px', fsChip: '11px',
    /* vùng bấm */
    hControl: '38px', hRow: '38px', hTouch: '44px'
  };

  function j() { return Array.prototype.slice.call(arguments).filter(Boolean).join(''); }

  /* ---- vỏ thẻ: tiêu đề là thanh trên cùng của thẻ, ngăn bằng một đường kẻ (CLAUDE.md §8) ---- */
  function card(extra) {
    return j('background:', T.surface, ';border:1px solid ', T.lineSoft, ';border-radius:', T.rCard,
      ';box-shadow:', T.shCard, ';', extra || '');
  }
  function cardHead(extra) {
    return j('display:flex;align-items:center;gap:8px;padding:', T.cardHeadPad,
      ';border-bottom:1px solid ', T.lineSoft, ';', extra || '');
  }
  function cardTitle() {
    return j('font-size:', T.fsCardTitle, ';font-weight:600;color:', T.ink, ';white-space:nowrap;');
  }
  function cardBody(extra) {
    return j('padding:', T.cardPad, ';display:flex;flex-direction:column;gap:', T.fieldGap, ';', extra || '');
  }

  /* ---- trường nhập ---- */
  function labelRow() { return 'display:flex;align-items:center;gap:8px;margin-bottom:6px;min-width:0;'; }
  function label() { return j('font-size:', T.fsLabel, ';font-weight:600;color:', T.body, ';white-space:nowrap;'); }
  function control(o) {
    o = o || {};
    return j('width:100%;box-sizing:border-box;min-height:', T.hControl, ';padding:9px 12px;border:1px solid ',
      T.line, ';border-radius:', T.rControl, ';background:', T.surface, ';font:inherit;font-size:',
      o.big ? '15px' : T.fsInput, ';', o.big ? 'font-weight:500;color:' + T.ink + ';' : 'color:' + T.body + ';',
      'outline:none;', o.mono ? 'font-family:ui-monospace,Menlo,monospace;font-size:12.5px;' : '',
      o.area ? 'line-height:1.6;resize:vertical;' : '',
      o.locked ? 'opacity:0.6;cursor:not-allowed;' : '');
  }
  function focusRing() { return j('border-color:', T.brand, ';box-shadow:', T.ring, ';'); }
  function counter(state) {
    var c = state === 'over' ? T.warnInk : (state === 'ok' ? T.success : T.faint);
    return j('margin-left:auto;font-size:', T.fsMeta, ';color:', c, ';font-variant-numeric:tabular-nums;');
  }

  /* ---- nút ---- */
  function btn(kind, off) {
    var base = j('display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:', T.hControl,
      ';padding:8px 14px;border-radius:', T.rControl, ';font:inherit;font-size:13.5px;font-weight:600;white-space:nowrap;',
      off ? 'cursor:not-allowed;' : 'cursor:pointer;');
    if (off) return j(base, 'border:0;background:#eef0f3;color:', T.faint, ';');
    if (kind === 'primary') return j(base, 'border:0;background:', T.brand, ';color:#fff;box-shadow:', T.shCard, ';');
    if (kind === 'danger') return j(base, 'border:1px solid #f7d3ce;background:', T.surface, ';color:', T.danger, ';');
    if (kind === 'ai') return j(base, 'border:1px solid ', T.brandLine, ';background:', T.brandTint10, ';color:', T.brand, ';');
    if (kind === 'ghost') return j(base, 'border:0;background:transparent;color:', T.brand, ';');
    return j(base, 'border:1px solid ', T.line, ';background:', T.surface, ';color:', T.body, ';font-weight:500;');
  }
  /* nút AI nhỏ đứng cạnh nhãn trường (§9: nền brand nhạt + ri-sparkling-2-line) */
  function aiChipBtn(off) {
    return j('display:inline-flex;align-items:center;gap:4px;min-height:24px;padding:0 10px;border:1px solid ',
      T.brandLine, ';border-radius:', T.rPill, ';background:', off ? '#f3f4f6' : 'rgba(64,81,137,0.07)',
      ';font:inherit;font-size:', T.fsChip, ';font-weight:700;color:', off ? T.faint : T.brand,
      ';flex-shrink:0;white-space:nowrap;cursor:', off ? 'not-allowed' : 'pointer', ';');
  }
  function iconBtn(tone) {
    return j('display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:0;border-radius:',
      T.rControl, ';background:transparent;cursor:pointer;color:', tone === 'off' ? '#dee2e6' : T.muted, ';');
  }

  /* ---- chip / huy hiệu ---- */
  function chip(tone) {
    var m = {
      neutral: [T.muted, T.fill], brand: [T.brand, T.brandTint10],
      success: [T.success, 'rgba(10,179,156,0.12)'], warn: [T.warnInk, 'rgba(247,184,75,0.18)'],
      danger: [T.danger, 'rgba(240,101,72,0.12)'], info: [T.info, 'rgba(41,156,219,0.12)']
    }[tone || 'neutral'];
    return j('display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:', T.rPill,
      ';font-size:', T.fsChip, ';font-weight:600;white-space:nowrap;flex-shrink:0;color:', m[0], ';background:', m[1], ';');
  }
  /* chip mã ngôn ngữ ở cuối nhãn trường đa ngữ (§5) */
  function langChip() {
    return j('display:inline-flex;align-items:center;padding:1px 6px;border-radius:3px;font-size:9.5px;font-weight:700;',
      'letter-spacing:.02em;color:#7d8bb3;background:', T.brandTint10, ';flex-shrink:0;');
  }
  /* huy hiệu trạng thái — màu lấy từ BSCW.ui(key) */
  function badge(meta) {
    return j('display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border:0;font:inherit;font-size:',
      T.fsMeta, ';font-weight:500;line-height:1;border-radius:', T.rControl, ';white-space:nowrap;color:',
      meta.c, ';background:', meta.bg, ';');
  }
  function dot(c, hollow) {
    return j('width:8px;height:8px;border-radius:50%;flex-shrink:0;',
      hollow ? 'border:1px dashed ' + T.off + ';' : 'background:' + c + ';');
  }

  /* ---- dải thông báo trong thẻ (blockers, cảnh báo hạn ngạch, ghi chú) ---- */
  function banner(tone) {
    var m = {
      warn: [T.warnInk, 'rgba(247,184,75,0.14)', 'rgba(247,184,75,0.3)'],
      danger: [T.dangerInk, 'rgba(240,101,72,0.10)', 'rgba(240,101,72,0.28)'],
      info: ['#1d6f9e', 'rgba(41,156,219,0.08)', 'rgba(41,156,219,0.22)'],
      brand: [T.brand, T.brandTint, T.brandLine],
      neutral: [T.muted, T.surfaceAlt, T.lineSoft]
    }[tone || 'neutral'];
    return j('display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border-radius:', T.rControl,
      ';background:', m[1], ';border:1px solid ', m[2], ';font-size:', T.fsMeta, ';line-height:1.5;color:', m[0], ';');
  }
  /* khối "Phương án AI" chèn NGAY DƯỚI trường đang sửa (§15) */
  function aiPanel() {
    return j('margin-top:10px;border:1px solid rgba(64,81,137,0.25);background:rgba(64,81,137,0.04);border-radius:',
      T.rCard, ';padding:12px;');
  }
  function aiOption(best) {
    return j('padding:10px 11px;background:', T.surface, ';border-radius:', T.rControl, ';border:1px solid ',
      best ? 'rgba(10,179,156,0.4)' : T.line, ';', best ? 'box-shadow:0 0 0 3px rgba(10,179,156,0.08);' : '');
  }
  /* popover ⓘ cạnh nhãn (§8: white-space normal, z-index trên ô nhập, neo phải khi ở cột phải) */
  function popover(right) {
    return j('position:absolute;top:calc(100% + 6px);', right ? 'right:0;' : 'left:0;', 'z-index:70;width:260px;',
      'max-width:min(260px, calc(100vw - 32px));white-space:normal;background:', T.surface, ';border:1px solid ',
      T.line, ';border-radius:', T.rCard, ';box-shadow:', T.shPop, ';padding:10px 12px;font-size:', T.fsMeta,
      ';line-height:1.55;color:', T.body, ';');
  }
  function menu() {
    return j('position:absolute;z-index:60;background:', T.surface, ';border:1px solid ', T.line, ';border-radius:',
      T.rCard, ';box-shadow:', T.shPop, ';overflow:hidden;padding:4px 0;');
  }
  function menuItem(active) {
    return j('display:flex;align-items:center;gap:8px;width:100%;box-sizing:border-box;min-height:', T.hRow,
      ';padding:8px 12px;border:0;background:', active ? '#f6f8fc' : 'transparent', ';font:inherit;font-size:',
      T.fsInput, ';color:', active ? T.brand : T.body, ';', active ? 'font-weight:600;' : '', 'cursor:pointer;text-align:left;');
  }
  /* hàng chọn một-trong-nhiều (trạng thái, bước duyệt) */
  function pickRow(active, locked) {
    return j('display:flex;align-items:center;gap:8px;width:100%;box-sizing:border-box;min-height:', T.hRow,
      ';padding:9px 11px;border:1px solid ', active ? T.brand : T.lineSoft, ';border-radius:', T.rControl,
      ';background:', active ? 'rgba(64,81,137,0.05)' : T.surface, ';font:inherit;font-size:', T.fsInput,
      ';color:', locked ? T.off : (active ? T.brand : T.body), ';', active ? 'font-weight:600;' : '',
      'text-align:left;cursor:', locked ? 'not-allowed' : 'pointer', ';');
  }
  /* khung ảnh: fit theo tỷ lệ gốc trong khung tối đa, không crop không méo (§7d) */
  function mediaBox(ratio, filled, tint) {
    return j('width:100%;aspect-ratio:', ratio || '16/9', ';box-sizing:border-box;border-radius:', T.rCard,
      ';overflow:hidden;display:flex;align-items:center;justify-content:center;',
      filled ? 'background:' + (tint || '#dfe4ee') + ';' : 'background:' + T.surfaceAlt + ';border:2px dashed #dfe3ea;');
  }
  function metaLine() { return j('display:flex;align-items:center;gap:6px;font-size:', T.fsMicro, ';color:', T.muted, ';min-width:0;'); }
  /* \u00d4 \u0111\u01b0\u1eddng d\u1eabn (slug) \u2014 m\u1eabu chu\u1ea9n: nh\u00e3n + chip ng\u00f4n ng\u1eef + g\u1ee3i \u00fd d\u1ea1t ph\u1ea3i \u2192 khung full-width
     (ti\u1ec1n t\u1ed1 x\u00e1m kh\u00f4ng s\u1eeda \u0111\u01b0\u1ee3c + \u00f4 nh\u1eadp mono) \u2192 M\u1edeT d\u00f2ng meta: \u0111\u1ecba ch\u1ec9 \u0111\u1ea7y \u0111\u1ee7 + m\u1edf tab m\u1edbi + sao ch\u00e9p. */
  function slugBox() {
    return j('display:flex;align-items:center;width:100%;box-sizing:border-box;border:1px solid ', T.line,
      ';border-radius:', T.rControl, ';background:', T.surface, ';overflow:hidden;');
  }
  function slugPrefix() {
    return j('padding:9px 0 9px 12px;font-family:ui-monospace,Menlo,monospace;font-size:12.5px;color:', T.faint,
      ';white-space:nowrap;flex:0 0 auto;');
  }
  function slugInput() {
    return j('flex:1 1 auto;min-width:0;font-family:ui-monospace,Menlo,monospace;font-size:12.5px;color:', T.body,
      ';border:0;padding:9px 12px 9px 2px;outline:none;background:transparent;');
  }
  function slugHint() { return j('margin-left:auto;display:inline-flex;align-items:center;gap:5px;font-size:', T.fsMeta, ';color:', T.faint, ';white-space:nowrap;'); }
  function linkAction(tone) {
    return j('display:inline-flex;align-items:center;gap:4px;border:0;background:transparent;font:inherit;font-size:',
      T.fsMicro, ';color:', tone === 'danger' ? T.danger : T.brand, ';cursor:pointer;padding:2px;flex-shrink:0;');
  }

  window.BSUI = {
    tok: T, card: card, cardHead: cardHead, cardTitle: cardTitle, cardBody: cardBody,
    labelRow: labelRow, label: label, control: control, focusRing: focusRing, counter: counter,
    btn: btn, aiChipBtn: aiChipBtn, iconBtn: iconBtn,
    chip: chip, langChip: langChip, badge: badge, dot: dot,
    banner: banner, aiPanel: aiPanel, aiOption: aiOption,
    popover: popover, menu: menu, menuItem: menuItem, pickRow: pickRow,
    mediaBox: mediaBox, metaLine: metaLine, linkAction: linkAction,
    slugBox: slugBox, slugPrefix: slugPrefix, slugInput: slugInput, slugHint: slugHint
  };
  try { document.dispatchEvent(new CustomEvent('bs-ui-ready')); } catch (e) {}
})();
