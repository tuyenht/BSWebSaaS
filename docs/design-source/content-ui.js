/* content-ui.js — QUY ƯỚC GIAO DIỆN cho mọi màn nội dung (Trang · Bài viết · Sản phẩm · Danh mục…).
   Một nguồn duy nhất: sửa ở đây là đổi mọi chỗ dùng. Không màn nào tự chế màu/khoảng cách/kiểu chữ.
   Bám token AdminKit (_ds/…/tokens/*.css) và CLAUDE.md §8.
   Bộ ba nguồn dùng chung:
     · ai-quota.js        (window.BSAI)  — gói, hạn ngạch, quy đổi "đủ khoảng N …"
     · content-workflow.js(window.BSCW)  — trạng thái, vai trò, quy trình duyệt
     · content-ui.js      (window.BSUI)  — token + pattern giao diện  ← file này
   Dùng: window.BSUI (+ event 'bs-ui-ready'). Hầu hết hàm trả về CHUỖI style để gắn inline.
   Ngoại lệ — hàm THAO TÁC DOM (D0-2s): trapFocus(e) giữ Tab trong hộp thoại; watchBottomBar(el) đo --sbar-h + --kb, trả về hàm gỡ;
   copy(text) chép vào bộ nhớ tạm, trả Promise<boolean> (04/10). */
(function () {
  var T = {
    /* màu — chỉ lấy trong token AdminKit */
    /* D0-2 (04/10/2026): mọi màu qua token để chế độ tối tự lật; giá trị sau dấu phẩy chỉ là dự phòng */
    brand: 'var(--color-accent,#2050a0)', brand600: 'var(--color-admin-primary,#2050a0)',
    brandSolid: 'var(--color-admin-primary,#2050a0)',
    brandTint: 'color-mix(in srgb,var(--color-accent,#2050a0) 6%,transparent)',
    brandTint10: 'color-mix(in srgb,var(--color-accent,#2050a0) 10%,transparent)',
    brandTint14: 'color-mix(in srgb,var(--color-accent,#2050a0) 14%,transparent)',
    brandLine: 'color-mix(in srgb,var(--color-accent,#2050a0) 24%,transparent)',
    ring: 'outline:var(--focus-ring-width,2px) solid var(--focus-ring-color,#2050a0);outline-offset:var(--focus-ring-offset,2px);',
    canvas: 'var(--color-body,#f4f5fa)', surface: 'var(--color-surface,#fff)', surfaceAlt: 'var(--color-surface-muted,#f8f9fb)',
    fill: 'var(--color-surface-hover,#f1f3f9)',
    line: 'var(--color-border-strong,#e3e7f0)', lineSoft: 'var(--color-border,#e9ecf4)', hairline: 'var(--color-border-soft,#f3f6f9)',
    lineInput: 'var(--color-border-input,#8a90a2)',
    ink: 'var(--color-heading,#1f2b4d)', body: 'var(--color-body-text,#3c4765)', muted: 'var(--color-body-muted,#5b6275)',
    faint: 'var(--color-body-faint,#adb5bd)' /* faint chỉ trang trí */, off: 'var(--color-body-muted,#5b6275)',
    success: '#0ab39c', info: '#299cdb', warn: '#f7b84b', /* màu gốc: chỉ chấm trạng thái + nền 10% */
    warnInk: 'var(--color-admin-warning-text,#906405)',
    successInk: 'var(--color-admin-success-text,#047767)', infoInk: 'var(--color-admin-info-text,#026fa2)',
    danger: '#f06548', dangerInk: 'var(--color-admin-danger-text,#bf371a)', secondary: 'var(--color-admin-secondary-text,#2f69d4)',
    successSolid: 'var(--color-admin-success-solid,#038573)', dangerSolid: 'var(--color-admin-danger-solid,#d0472b)',
    onWarning: 'var(--color-on-warning,#212529)',
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
  function focusRing() { return j('border-color:', T.brand, ';', T.ring); }
  function counter(state) {
    var c = state === 'over' ? T.warnInk : (state === 'ok' ? T.successInk : T.muted);
    return j('margin-left:auto;font-size:', T.fsMeta, ';color:', c, ';font-variant-numeric:tabular-nums;');
  }

  /* ---- nút ---- */
  function btn(kind, off) {
    var base = j('display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:', T.hControl,
      ';padding:8px 14px;border-radius:', T.rControl, ';font:inherit;font-size:13.5px;font-weight:600;white-space:nowrap;',
      off ? 'cursor:not-allowed;' : 'cursor:pointer;');
    if (off) return j(base, 'border:0;background:', T.fill, ';color:', T.muted, ';opacity:.6;');
    if (kind === 'primary') return j(base, 'border:0;background:', T.brandSolid, ';color:#fff;box-shadow:', T.shCard, ';');
    if (kind === 'danger') return j(base, 'border:1px solid ', T.dangerInk, ';background:', T.surface, ';color:', T.dangerInk, ';');
    if (kind === 'dangerSolid') return j(base, 'border:0;background:', T.dangerSolid, ';color:#fff;');
    if (kind === 'ai') return j(base, 'border:1px solid ', T.brandLine, ';background:', T.brandTint10, ';color:', T.brand, ';');
    if (kind === 'ghost') return j(base, 'border:0;background:transparent;color:', T.brand, ';');
    return j(base, 'border:1px solid ', T.line, ';background:', T.surface, ';color:', T.body, ';font-weight:500;');
  }
  /* nút AI nhỏ đứng cạnh nhãn trường (§9: nền brand nhạt + ri-sparkling-2-line) */
  function aiChipBtn(off) {
    return j('display:inline-flex;align-items:center;gap:4px;min-height:24px;padding:0 10px;border:1px solid ',
      T.brandLine, ';border-radius:', T.rPill, ';background:', off ? T.fill : T.brandTint,
      ';font:inherit;font-size:', T.fsChip, ';font-weight:700;color:', off ? T.muted : T.brand, off ? ';opacity:.6' : '',
      ';flex-shrink:0;white-space:nowrap;cursor:', off ? 'not-allowed' : 'pointer', ';');
  }
  function iconBtn(tone) {
    return j('display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:0;border-radius:',
      T.rControl, ';background:transparent;cursor:pointer;color:', T.muted, tone === 'off' ? ';opacity:.5' : '', ';');
  }

  /* ---- chip / huy hiệu ---- */
  function chip(tone) {
    var m = {
      neutral: [T.muted, T.fill], brand: [T.brand, T.brandTint10],
      success: [T.successInk, 'rgba(10,179,156,0.12)'], warn: [T.warnInk, 'rgba(247,184,75,0.18)'],
      danger: [T.dangerInk, 'rgba(240,101,72,0.12)'], info: [T.infoInk, 'rgba(41,156,219,0.12)']
    }[tone || 'neutral'];
    return j('display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:', T.rPill,
      ';font-size:', T.fsChip, ';font-weight:600;white-space:nowrap;flex-shrink:0;color:', m[0], ';background:', m[1], ';');
  }
  /* chip mã ngôn ngữ ở cuối nhãn trường đa ngữ (§5) */
  function langChip() {
    return j('display:inline-flex;align-items:center;padding:1px 6px;border-radius:3px;font-size:12px;font-weight:700;',
      'letter-spacing:.02em;color:var(--color-body-muted,#6b6e7f);background:', T.brandTint10, ';flex-shrink:0;');
  }
  /* huy hiệu trạng thái — màu lấy từ BSCW.ui(key) */
  function badge(meta) {
    return j('display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border:0;font:inherit;font-size:',
      T.fsMeta, ';font-weight:500;line-height:1;border-radius:', T.rControl, ';white-space:nowrap;color:',
      meta.c, ';background:', meta.bg, ';');
  }
  function dot(c, hollow) {
    return j('width:8px;height:8px;border-radius:50%;flex-shrink:0;',
      hollow ? 'border:1px dashed ' + T.lineInput + ';box-sizing:border-box;' : 'background:' + c + ';');
  }

  /* ---- dải thông báo trong thẻ (blockers, cảnh báo hạn ngạch, ghi chú) ---- */
  function banner(tone) {
    var m = {
      warn: [T.warnInk, 'rgba(247,184,75,0.14)', 'rgba(247,184,75,0.3)'],
      danger: [T.dangerInk, 'rgba(240,101,72,0.10)', 'rgba(240,101,72,0.28)'],
      info: [T.infoInk, 'rgba(41,156,219,0.08)', 'rgba(41,156,219,0.22)'],
      brand: [T.brand, T.brandTint, T.brandLine],
      neutral: [T.muted, T.surfaceAlt, T.lineSoft]
    }[tone || 'neutral'];
    return j('display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border-radius:', T.rControl,
      ';background:', m[1], ';border:1px solid ', m[2], ';font-size:', T.fsMeta, ';line-height:1.5;color:', m[0], ';');
  }
  /* khối "Phương án AI" chèn NGAY DƯỚI trường đang sửa (§15) */
  function aiPanel() {
    return j('margin-top:10px;border:1px solid ', T.brandLine, ';background:', T.brandTint, ';border-radius:',
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
      ';padding:8px 12px;border:0;background:', active ? T.brandTint : 'transparent', ';font:inherit;font-size:',
      T.fsInput, ';color:', active ? T.brand : T.body, ';', active ? 'font-weight:600;' : '', 'cursor:pointer;text-align:left;');
  }
  /* hàng chọn một-trong-nhiều (trạng thái, bước duyệt) */
  function pickRow(active, locked) {
    return j('display:flex;align-items:center;gap:8px;width:100%;box-sizing:border-box;min-height:', T.hRow,
      ';padding:9px 11px;border:1px solid ', active ? T.brand : T.lineSoft, ';border-radius:', T.rControl,
      ';background:', active ? T.brandTint : T.surface, ';font:inherit;font-size:', T.fsInput,
      ';color:', locked ? T.muted : (active ? T.brand : T.body), ';', locked ? 'opacity:.6;' : '', active ? 'font-weight:600;' : '',
      'text-align:left;cursor:', locked ? 'not-allowed' : 'pointer', ';');
  }
  /* khung ảnh: fit theo tỷ lệ gốc trong khung tối đa, không crop không méo (§7d) */
  function mediaBox(ratio, filled, tint) {
    return j('width:100%;aspect-ratio:', ratio || '16/9', ';box-sizing:border-box;border-radius:', T.rCard,
      ';overflow:hidden;display:flex;align-items:center;justify-content:center;',
      filled ? 'background:' + (tint || T.fill) + ';' : 'background:' + T.surfaceAlt + ';border:2px dashed ' + T.line + ';');
  }
  function metaLine() { return j('display:flex;align-items:center;gap:6px;font-size:', T.fsMicro, ';color:', T.muted, ';min-width:0;'); }
  /* \u00d4 \u0111\u01b0\u1eddng d\u1eabn (slug) \u2014 m\u1eabu chu\u1ea9n: nh\u00e3n + chip ng\u00f4n ng\u1eef + g\u1ee3i \u00fd d\u1ea1t ph\u1ea3i \u2192 khung full-width
     (ti\u1ec1n t\u1ed1 x\u00e1m kh\u00f4ng s\u1eeda \u0111\u01b0\u1ee3c + \u00f4 nh\u1eadp mono) \u2192 M\u1edeT d\u00f2ng meta: \u0111\u1ecba ch\u1ec9 \u0111\u1ea7y \u0111\u1ee7 + m\u1edf tab m\u1edbi + sao ch\u00e9p. */
  function slugBox() {
    return j('display:flex;align-items:center;width:100%;box-sizing:border-box;border:1px solid ', T.lineInput,
      ';border-radius:', T.rControl, ';background:', T.surface, ';overflow:hidden;');
  }
  function slugPrefix() {
    return j('padding:9px 0 9px 12px;font-family:ui-monospace,Menlo,monospace;font-size:12.5px;color:', T.muted,
      ';white-space:nowrap;flex:0 0 auto;');
  }
  function slugInput() {
    return j('flex:1 1 auto;min-width:0;font-family:ui-monospace,Menlo,monospace;font-size:12.5px;color:', T.body,
      ';border:0;padding:9px 12px 9px 2px;outline:none;background:transparent;');
  }
  function slugHint() { return j('margin-left:auto;display:inline-flex;align-items:center;gap:5px;font-size:', T.fsMeta, ';color:', T.muted, ';white-space:nowrap;'); }
  function linkAction(tone) {
    return j('display:inline-flex;align-items:center;gap:4px;border:0;background:transparent;font:inherit;font-size:',
      T.fsMicro, ';color:', tone === 'danger' ? T.dangerInk : T.brand, ';cursor:pointer;padding:2px;flex-shrink:0;');
  }

  /* ===== D0-2: thuật toán màu — chép đúng từ repo, KHÔNG tự chế bảng =====
     brandPalette ← app/Modules/Theme/Theming/BrandPalette.php (OKLCH, 10 bậc, màu gốc nằm nguyên ở bậc gần nhất)
     readable/readableOnAll/ratio/mix ← app/Modules/Theme/Theming/ReadableColor.php (WCAG 2, trộn sRGB 20 bước)
     primaryText/primaryOnInk ← ThemeResolver.php:85/89 */
  var LIGHT = { 50: .971, 100: .936, 200: .885, 300: .808, 400: .707, 500: .623, 600: .546, 700: .488, 800: .424, 900: .379 };
  var CHROMA = { 50: .12, 100: .25, 200: .45, 300: .7, 400: .9, 500: 1, 600: 1, 700: .9, 800: .8, 900: .7 };
  var STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];
  function parseHex(c) {
    var m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(c || '').trim()); if (!m) return null;
    var h = m[1].toLowerCase(); if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
  }
  function toHex(rgb) { return '#' + rgb.map(function (v) { var n = Math.round(Math.max(0, Math.min(255, v))); return (n < 16 ? '0' : '') + n.toString(16); }).join(''); }
  function lin(v) { v /= 255; return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }
  function gam(v) { return v <= 0.0031308 ? v * 12.92 : 1.055 * Math.pow(v, 1 / 2.4) - 0.055; }
  function lum(rgb) { return 0.2126 * lin(rgb[0]) + 0.7152 * lin(rgb[1]) + 0.0722 * lin(rgb[2]); }
  function ratio(a, b) {
    var x = parseHex(a), y = parseHex(b); if (!x || !y) return null;
    var l1 = lum(x), l2 = lum(y); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
  }
  function mix(a, b, share) {
    var x = parseHex(a), y = parseHex(b); if (!x || !y) return null;
    return toHex([0, 1, 2].map(function (i) { return x[i] * share + y[i] * (1 - share); }));
  }
  function readableOnAll(color, bgs, min) {
    min = min || 4.5; var rgb = parseHex(color); if (!rgb || !bgs || !bgs.length) return null;
    for (var i = 0; i < bgs.length; i++) if (!parseHex(bgs[i])) return null;
    var sl = lum(parseHex(bgs[0]));
    var tgt = (sl + 0.05) / 0.05 >= 1.05 / (sl + 0.05) ? [0, 0, 0] : [255, 255, 255];
    for (var s = 0; s <= 20; s++) {
      var w = s / 20, hex = toHex([0, 1, 2].map(function (k) { return rgb[k] + (tgt[k] - rgb[k]) * w; }));
      if (bgs.every(function (b) { return ratio(hex, b) >= min; })) return hex;
    }
    return toHex(tgt);
  }
  function readable(color, bg, min) { return readableOnAll(color, [bg], min); }
  function cbrt(v) { return v < 0 ? -Math.pow(-v, 1 / 3) : Math.pow(v, 1 / 3); }
  function toOklch(rgb) {
    var r = lin(rgb[0]), g = lin(rgb[1]), b = lin(rgb[2]);
    var l = cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
    var m = cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
    var s = cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
    var L = 0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s;
    var A = 1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s;
    var B = 0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s;
    return [L, Math.sqrt(A * A + B * B), Math.atan2(B, A)];
  }
  function fromOklch(L, C, H) {
    var a = C * Math.cos(H), b = C * Math.sin(H);
    var l = Math.pow(L + 0.3963377774 * a + 0.2158037573 * b, 3);
    var m = Math.pow(L - 0.1055613458 * a - 0.0638541728 * b, 3);
    var s = Math.pow(L - 0.0894841775 * a - 1.2914855480 * b, 3);
    var ln = [4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
      -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
      -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s];
    for (var i = 0; i < 3; i++) if (ln[i] < -0.0001 || ln[i] > 1.0001) return null;
    return ln.map(function (c) { return gam(Math.max(0, Math.min(1, c))) * 255; });
  }
  function fromOklchInGamut(L, C, H) {
    var f = fromOklch(L, C, H); if (f) return f;
    var lo = 0, hi = C, best = fromOklch(L, 0, H) || [L * 255, L * 255, L * 255];
    for (var i = 0; i < 24; i++) { var mid = (lo + hi) / 2, c = fromOklch(L, mid, H); if (!c) hi = mid; else { best = c; lo = mid; } }
    return best;
  }
  /* → [{step, hex, isBrand, ink, inkRatio}] ; null nếu không phải mã hex */
  function brandPalette(brand) {
    var rgb = parseHex(brand); if (!rgb) return null;
    var o = toOklch(rgb), anchor = 500, d = Infinity;
    STEPS.forEach(function (st) { if (Math.abs(LIGHT[st] - o[0]) < d) { d = Math.abs(LIGHT[st] - o[0]); anchor = st; } });
    return STEPS.map(function (st) {
      var hex = st === anchor ? toHex(rgb) : toHex(fromOklchInGamut(LIGHT[st], o[1] * CHROMA[st], o[2]));
      var t = inkOn(hex);
      return { step: st, hex: hex, isBrand: st === anchor, ink: t.ink, inkRatio: t.ratio };
    });
  }
  /* Chữ đặt trên một nền màu: đen hoặc trắng — chọn theo đúng ngưỡng của ReadableColor.onAll (đen thắng khi độ sáng > ≈0.18) */
  function inkOn(bg) {
    var rgb = parseHex(bg); if (!rgb) return { ink: '#ffffff', ratio: null, ok: false };
    var L = lum(rgb), black = (L + 0.05) / 0.05 >= 1.05 / (L + 0.05);
    var ink = black ? '#000000' : '#ffffff', r = ratio(ink, bg);
    return { ink: ink, ratio: r, ok: r >= 4.5 };
  }
  /* --primary-text: chữ màu thương hiệu trên nền sáng — đạt ≥4.5 trên thẻ (--surface) VÀ nền trang (surface 97% + ink) */
  function primaryText(primary, surface, ink) {
    surface = surface || '#ffffff'; ink = ink || '#0f172a';
    return readableOnAll(primary, [surface, mix(surface, ink, 0.97)]) || ink;
  }
  /* --primary-on-ink: chữ màu thương hiệu trên nền tối (--ink) */
  function primaryOnInk(primary, ink, surface) { return readableOnAll(primary, [ink || '#0f172a']) || surface || '#ffffff'; }

  /* ===== D0-2: định dạng Việt Nam (P13) ===== */
  function vnd(n) { var v = Math.round(Number(n) || 0); return v.toLocaleString('vi-VN').replace(/,/g, '.') + ' ₫'; }
  function parseVnd(s) { var d = String(s || '').replace(/[^0-9]/g, ''); return d ? Math.min(parseInt(d, 10), 999999999999) : null; }
  function groupVnd(s) { var n = parseVnd(s); return n === null ? '' : n.toLocaleString('vi-VN').replace(/,/g, '.'); }
  /* ngày giờ theo múi giờ site: dd/mm/yyyy HH:mm, 24h */
  function fmtDate(d, tz, withTime) {
    var p = new Intl.DateTimeFormat('en-GB', { timeZone: tz || 'Asia/Ho_Chi_Minh', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(d);
    var g = {}; p.forEach(function (x) { g[x.type] = x.value; });
    return g.day + '/' + g.month + '/' + g.year + (withTime === false ? '' : ' ' + g.hour + ':' + g.minute);
  }
  /* ===== D0-3 C2: định dạng theo ngôn ngữ giao diện (vi mặc định) ===== */
  function loc(l) { l = String(l || 'vi').slice(0, 2); return l === 'en' ? 'en-US' : l === 'ja' ? 'ja-JP' : l === 'zh' ? 'zh-CN' : 'vi-VN'; }
  function fmtNum(n, l) { var v = Number(n) || 0; if (loc(l) !== 'vi-VN') return v.toLocaleString(loc(l), { maximumFractionDigits: 2 }); return v.toLocaleString('en-US', { maximumFractionDigits: 2 }).replace(/,/g, '§').replace(/\./g, ',').replace(/§/g, '.'); }
  function dec1(x, l) { var r = Math.round(x * 10) / 10; return (r % 1 === 0 ? String(r) : r.toFixed(1)).replace('.', loc(l) === 'vi-VN' ? ',' : '.'); }
  /* 1 MB = 1.048.576 byte (như repo) */
  function fmtBytes(b, l) {
    var v = Math.max(0, Number(b) || 0), u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
    while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
    return (i === 0 ? fmtNum(v, l) : dec1(v, l)) + ' ' + u[i];
  }
  /* dưới 24 giờ: "5 phút trước"; từ 24 giờ: ngày của fmtDate. opt {locale, tz, now} */
  function fmtAgo(d, opt) {
    opt = opt || {}; var t = new Date(d), now = opt.now ? new Date(opt.now) : new Date(), s = Math.round((now - t) / 1000), l = loc(opt.locale);
    if (s >= 86400 || s < 0) return fmtDate(t, opt.tz, true);
    if (s < 60) return l === 'vi-VN' ? 'Vừa xong' : l === 'en-US' ? 'Just now' : new Intl.RelativeTimeFormat(l).format(0, 'second');
    var m = Math.floor(s / 60);
    if (l === 'vi-VN') return m < 60 ? m + ' phút trước' : Math.floor(m / 60) + ' giờ trước';
    var rtf = new Intl.RelativeTimeFormat(l, { numeric: 'always' });
    return m < 60 ? rtf.format(-m, 'minute') : rtf.format(-Math.floor(m / 60), 'hour');
  }
  /* số rút gọn cho biểu đồ: vi K/Tr/Tỷ không khoảng trắng; en/ja/zh theo Intl compact của CHÍNH ngôn ngữ đó (ja 万/億, zh 万/亿);
     `max` = giá trị lớn nhất của biểu đồ → cả biểu đồ 1 đơn vị (đơn vị suy từ max). Giá trị > 0 mà làm tròn ra 0 → "<0,1<đơn vị>".
     Trình đọc màn hình: chữ rút gọn aria-hidden + chữ ẩn ghi fmtNum(n) (D0-3s). */
  function fmtCompact(n, opt) {
    opt = opt || {}; var v = Number(n) || 0, l = loc(opt.locale), ref = Math.abs(opt.max != null ? Number(opt.max) : v), div, sfx;
    if (l === 'vi-VN') {
      if (ref < 1000) return fmtNum(v, 'vi');
      var U = ref >= 1e9 ? [1e9, 'Tỷ'] : ref >= 1e6 ? [1e6, 'Tr'] : [1e3, 'K']; div = U[0]; sfx = U[1];
    } else {
      var parts = new Intl.NumberFormat(l, { notation: 'compact', maximumFractionDigits: 1 }).formatToParts(ref);
      sfx = parts.filter(function (p) { return p.type === 'compact'; }).map(function (p) { return p.value; }).join('');
      if (!sfx) return fmtNum(v, opt.locale);
      var x = Number(parts.filter(function (p) { return p.type === 'integer' || p.type === 'fraction' || p.type === 'decimal'; }).map(function (p) { return p.type === 'decimal' ? '.' : p.value; }).join(''));
      div = Math.pow(10, Math.round(Math.log10(ref / x)));
    }
    var r = Math.round(v / div * 10) / 10;
    if (r === 0 && v > 0) return '<' + (l === 'vi-VN' ? '0,1' : new Intl.NumberFormat(l).format(0.1)) + sfx;
    return (l === 'vi-VN' ? dec1(r, 'vi') : new Intl.NumberFormat(l, { maximumFractionDigits: 1 }).format(r)) + sfx;
  }
  /* đường dẫn tiếng Việt: bỏ dấu, đ→d, chữ thường, số, gạch nối */
  /* cắt 200 ký tự (khớp repo max:200) RỒI mới bỏ "-" hai đầu — không để "-" cuối (repo từ chối) */
  function slugify(s) {
    return String(s || '').toLowerCase().replace(/đ/g, 'd').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-').slice(0, 200).replace(/^-+|-+$/g, '');
  }

  /* ===== D0-2s: hàm thao tác DOM dùng chung ===== */
  /* Gọi trong onKeyDown của hộp thoại: Tab/Shift+Tab vòng trong hộp (e.currentTarget là gốc hộp) */
  function trapFocus(e) {
    if (!e || e.key !== 'Tab') return;
    var root = e.currentTarget; if (!root || !root.querySelectorAll) return;
    var f = Array.prototype.slice.call(root.querySelectorAll('button:not([disabled]),[href],input:not([disabled]),select:not([disabled]),textarea,[tabindex]:not([tabindex="-1"])'))
      .filter(function (x) { return x.offsetParent !== null; });
    if (!f.length) return;
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }
  /* Thanh dính đáy: --sbar-h = chiều cao thật (ResizeObserver), --kb = phần bàn phím ảo che (visualViewport). Trả về hàm gỡ. */
  function watchBottomBar(el) {
    var d = document.documentElement, ro = null, vv = window.visualViewport, onVv = null;
    if (el && window.ResizeObserver) {
      ro = new ResizeObserver(function (en) { d.style.setProperty('--sbar-h', Math.round(en[0].target.offsetHeight) + 'px'); });
      ro.observe(el);
    }
    if (vv) {
      onVv = function () { var kb = Math.max(0, window.innerHeight - vv.height - vv.offsetTop); d.style.setProperty('--kb', Math.round(kb) + 'px'); };
      vv.addEventListener('resize', onVv); vv.addEventListener('scroll', onVv); onVv();
    }
    return function () {
      if (ro) ro.disconnect();
      if (vv && onVv) { vv.removeEventListener('resize', onVv); vv.removeEventListener('scroll', onVv); }
      d.style.setProperty('--sbar-h', '0px'); d.style.setProperty('--kb', '0px');
    };
  }

  /* Chép chữ: vùng chọn ẩn + execCommand → không được thì Clipboard API.
     Trả Promise<true|false>; false = cả hai cách đều bị chặn → màn tự bôi đen nội dung + chỉ cách chép tay. */
  function copy(text) {
    text = String(text == null ? '' : text);
    var legacy = function () {
      try {
        var ta = document.createElement('textarea');
        ta.value = text; ta.setAttribute('readonly', ''); ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;';
        var act = document.activeElement; document.body.appendChild(ta); ta.select(); ta.setSelectionRange(0, text.length);
        var ok = document.execCommand && document.execCommand('copy'); document.body.removeChild(ta);
        if (act && act.focus) act.focus(); return !!ok;
      } catch (e) { return false; }
    };
    if (!text) return Promise.resolve(false);
    /* Thử vùng chọn ẩn TRƯỚC, đồng bộ trong lúc bấm — còn giữ "thao tác của người dùng" nên chạy được cả khi
       Clipboard API bị chặn (khung nhúng không có quyền). Không được mới thử Clipboard API. */
    if (legacy()) return Promise.resolve(true);
    try {
      if (navigator.clipboard && navigator.clipboard.writeText)
        return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return false; });
    } catch (e) {}
    return Promise.resolve(false);
  }

  window.BSUI = {
    tok: T, card: card, cardHead: cardHead, cardTitle: cardTitle, cardBody: cardBody,
    labelRow: labelRow, label: label, control: control, focusRing: focusRing, counter: counter,
    btn: btn, aiChipBtn: aiChipBtn, iconBtn: iconBtn,
    chip: chip, langChip: langChip, badge: badge, dot: dot,
    banner: banner, aiPanel: aiPanel, aiOption: aiOption,
    popover: popover, menu: menu, menuItem: menuItem, pickRow: pickRow,
    mediaBox: mediaBox, metaLine: metaLine, linkAction: linkAction,
    slugBox: slugBox, slugPrefix: slugPrefix, slugInput: slugInput, slugHint: slugHint,
    /* D0-2 */
    ratio: ratio, mix: mix, readable: readable, readableOnAll: readableOnAll, brandPalette: brandPalette, inkOn: inkOn,
    primaryText: primaryText, primaryOnInk: primaryOnInk,
    vnd: vnd, parseVnd: parseVnd, groupVnd: groupVnd, fmtDate: fmtDate, slugify: slugify,
    /* D0-3 C2 */
    fmtNum: fmtNum, fmtBytes: fmtBytes, fmtAgo: fmtAgo, fmtCompact: fmtCompact,
    /* D0-2s — thao tác DOM */
    trapFocus: trapFocus, watchBottomBar: watchBottomBar, copy: copy
  };
  try { document.dispatchEvent(new CustomEvent('bs-ui-ready')); } catch (e) {}
})();
