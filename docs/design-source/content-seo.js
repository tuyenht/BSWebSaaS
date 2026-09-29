/* content-seo.js — BẢNG KIỂM SEO dùng chung cho mọi luồng biên tập (Bài viết · Trang · Sản phẩm…).
   Một nguồn duy nhất: 20 mục, 3 nhóm, kèm cách sửa (máy tự làm được / phải sinh chữ / người tự làm).
   Bộ nguồn dùng chung: ai-quota.js (BSAI) · content-workflow.js (BSCW) · content-ui.js (BSUI) · content-seo.js (BSSEO).
   Dùng: window.BSSEO.audit(ctx) → báo cáo; BSSEO.plan(report) → việc tự hoàn thiện được + số từ AI cần.
   Nguyên tắc: nói việc, không nói thuật ngữ ("mô tả trên Google", không "meta description") — CLAUDE.md §14. */
(function () {
  var STOP = ['va', 'cua', 'cho', 'voi', 'nhung', 'la', 'cac', 'mot', 'tai', 'tu', 'den', 'trong', 've',
    'khi', 'nay', 'do', 'se', 'duoc', 'co', 'the', 'nhu', 'thi', 'ma', 'ra', 'vao', 'the', 'and', 'the', 'for', 'with'];

  function el(html) { var d = document.createElement('div'); d.innerHTML = String(html || ''); return d; }
  function words(s) { return String(s || '').trim().split(/\s+/).filter(Boolean); }
  function wc(s) { return words(s).length; }
  function low(s) { return String(s || '').toLowerCase(); }
  function has(hay, needle) { return !!needle && low(hay).indexOf(low(needle)) >= 0; }
  function deaccent(s) {
    return low(s).normalize ? low(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd') : low(s);
  }
  function slugify(s) {
    return deaccent(s).replace(/[^a-z0-9\s-]/g, '').trim().replace(/\s+/g, '-').replace(/-+/g, '-');
  }

  /* ---------- đo nội dung thân bài ---------- */
  function scan(html, host) {
    var box = el(html), text = (box.textContent || '').replace(/\s+/g, ' ').trim();
    var ws = words(text);
    var heads = [].slice.call(box.querySelectorAll('h2,h3,h4'));
    var links = [].slice.call(box.querySelectorAll('a[href]'));
    var imgs = [].slice.call(box.querySelectorAll('img'));
    var paras = [].slice.call(box.querySelectorAll('p')).map(function (p) { return (p.textContent || '').trim(); }).filter(Boolean);
    var isOut = function (a) {
      var h = a.getAttribute('href') || '';
      return /^https?:\/\//i.test(h) && (!host || h.indexOf(host) < 0);
    };
    var jump = false, last = 0;
    heads.forEach(function (h) {
      var lv = +h.tagName.slice(1);
      if (last && lv > last + 1) jump = true;
      last = lv;
    });
    var sentences = text.split(/[.!?…]+\s/).map(function (s) { return s.trim(); }).filter(Boolean);
    return {
      text: text, words: ws.length, first100: ws.slice(0, 100).join(' '),
      heads: heads, headTexts: heads.map(function (h) { return (h.textContent || '').trim(); }),
      headJump: jump,
      linksIn: links.filter(function (a) { return !isOut(a); }),
      linksOut: links.filter(isOut),
      unsafeOut: links.filter(function (a) { return isOut(a) && !/noopener/.test(a.getAttribute('rel') || ''); }),
      imgs: imgs, imgsNoAlt: imgs.filter(function (i) { return !String(i.getAttribute('alt') || '').trim(); }),
      lists: box.querySelectorAll('ul,ol,table').length,
      paras: paras, longPara: paras.filter(function (p) { return wc(p) > 120; }).length,
      avgSentence: sentences.length ? Math.round(ws.length / sentences.length) : 0,
      faq: heads.filter(function (h) { return /\?$/.test((h.textContent || '').trim()); }).length ||
        paras.filter(function (p) { return /\?$/.test(p) && wc(p) < 25; }).length
    };
  }

  /* ---------- Giới hạn ký tự: MỞ RỘNG cho phép nhập, nhưng nói rõ vùng hiển thị ----------
     min      : dưới mức này là quá ngắn, chưa đủ thông tin
     best     : vùng đẹp nhất — xanh lá
     display  : mốc bị cắt khi hiển thị (Google / thẻ tin) — vượt là cảnh báo vàng, vẫn nhập được
     max      : mốc nên dừng — vượt là đỏ
     Mọi màn dùng đúng bảng này; không màn nào tự khai số đếm hay màu riêng. */
  var LIMITS = {
    title: { min: 20, best: [30, 60], display: 60, max: 100, cut: 'Google sẽ cắt bớt khi hiện' },
    seoTitle: { min: 20, best: [35, 60], display: 60, max: 80, cut: 'Google sẽ cắt bớt khi hiện' },
    seoDesc: { min: 70, best: [150, 160], display: 160, max: 200, cut: 'Google chỉ hiện khoảng 160 ký tự đầu' },
    excerpt: { min: 80, best: [120, 200], display: 200, max: 240, cut: 'thẻ tin sẽ cắt bớt' },
    alt: { min: 10, best: [25, 125], display: 125, max: 150, cut: 'trình đọc sẽ cắt bớt' },
    slug: { min: 3, best: [10, 60], display: 75, max: 90, cut: 'dài quá khó chia sẻ' }
  };

  /* Dải màu theo độ dài: xám (trống) → vàng (ngắn) → xanh dương (gần đủ) → xanh lá (đẹp)
     → vàng (vượt vùng hiển thị) → đỏ (vượt giới hạn). Luôn kèm nhãn chữ — không bao giờ chỉ dùng màu. */
  var BANDS = {
    empty: { ink: '#adb5bd', fill: '#dee2e6', note: '' },
    short: { ink: '#a9761d', fill: '#f7b84b', note: 'còn quá ngắn' },
    near: { ink: '#1c7ab0', fill: '#299cdb', note: 'gần đủ' },
    good: { ink: '#0a7f70', fill: '#0ab39c', note: 'vừa đẹp' },
    long: { ink: '#a9761d', fill: '#f7b84b', note: '' },
    over: { ink: '#c9412a', fill: '#f06548', note: 'quá dài, nên rút bớt' }
  };

  function limits(field) { return LIMITS[field] || LIMITS.title; }

  /* Mốc chặn gõ cứng: rộng hơn `max` một quãng để mức cảnh báo đỏ vẫn chạm tới được,
     nhưng không để người dùng gõ vô hạn vào một ô chỉ dài mấy dòng. */
  function hardCap(field) { var L = limits(field); return L.hard || Math.round(L.max * 1.25); }

  function meter(field, len) {
    var L = limits(field);
    len = Math.max(0, Number(len) || 0);
    var band = 'good';
    if (!len) band = 'empty';
    else if (len < L.min) band = 'short';
    else if (len < L.best[0]) band = 'near';
    else if (len <= L.best[1]) band = 'good';
    else if (len <= L.max) band = 'long';
    else band = 'over';
    var B = BANDS[band];
    var note = band === 'long' ? L.cut : B.note;
    /* Vượt vùng hiển thị thì không ghi kiểu "182/160" nữa — nói thẳng số đã nhập và hệ quả */
    var text = !len
      ? ('Nên ' + L.best[0] + '–' + L.best[1] + ' ký tự')
      : (len > L.display
        ? (len + ' ký tự — ' + note)
        : (len + '/' + L.display + ' ký tự' + (note ? ' — ' + note : '')));
    var pct = Math.min(100, (len / L.max) * 100);
    var tick = Math.min(100, (L.display / L.max) * 100);
    var lo = (L.best[0] / L.max) * 100, hi = (L.best[1] / L.max) * 100;
    return {
      band: band, len: len, note: note, text: text, over: len > L.display, limits: L,
      ink: B.ink, fill: B.fill, pct: pct,
      countStyle: 'margin-left:auto;min-width:0;font-size:12px;font-variant-numeric:tabular-nums;text-align:right;color:' + B.ink + ';',
      /* vùng đẹp nhất vẽ mờ trên nền rãnh để mắt thấy ngay đích cần tới */
      trackStyle: 'position:relative;display:block;height:4px;margin-top:7px;border-radius:2px;overflow:hidden;'
        + 'background:linear-gradient(90deg,#eff2f7 0,#eff2f7 ' + lo.toFixed(2) + '%,rgba(10,179,156,0.18) ' + lo.toFixed(2) + '%,rgba(10,179,156,0.18) ' + hi.toFixed(2) + '%,#eff2f7 ' + hi.toFixed(2) + '%);',
      fillStyle: 'position:absolute;left:0;top:0;bottom:0;border-radius:2px;transition:width .15s,background-color .15s;background:' + B.fill + ';width:' + pct.toFixed(2) + '%;',
      tickStyle: 'position:absolute;top:0;bottom:0;width:1px;background:#adb5bd;left:' + tick.toFixed(2) + '%;'
    };
  }

  /* ---------- 3 nhóm ---------- */
  var TIERS = [
    { key: 'must', name: 'Bắt buộc', note: 'Nên xong trước khi đăng — không chặn nút Đăng', tone: 'danger' },
    { key: 'should', name: 'Nên có', note: 'Giúp bài lên hạng nhanh hơn', tone: 'warn' },
    { key: 'plus', name: 'Nâng cao', note: 'Để vượt các bài đang đứng trên', tone: 'info' }
  ];

  /* fix: 'auto' = máy tự làm, KHÔNG trừ từ AI · 'ai' = phải sinh chữ, trừ từ AI · 'manual' = người tự làm */
  function audit(ctx) {
    ctx = ctx || {};
    var kind = ctx.kind === 'page' ? 'page' : 'post';
    var noun = kind === 'page' ? 'Trang' : 'Bài';
    var kw = String(ctx.keyword || '').trim();
    var kwLow = low(kw), kwSlug = slugify(kw);
    var b = scan(ctx.body, ctx.host);
    var title = String(ctx.seoTitle || ctx.title || '');
    var desc = String(ctx.seoDesc || '');
    var slug = String(ctx.slug || '').replace(/^\/+|\/+$/g, '');
    var slugParts = slug.split('-').filter(Boolean);
    var slugStop = slugParts.filter(function (w) { return STOP.indexOf(w) >= 0; });
    var kwHits = kwLow ? (low(b.text).split(kwLow).length - 1) : 0;
    var density = b.words ? (kwHits * words(kw).length / b.words) * 100 : 0;
    var exc = String(ctx.excerpt || '');
    var out = [];
    var add = function (o) { if (o.skip) return; out.push(o); };
    var need = function (n) { return Math.max(0, n); };

    /* ===== Nhóm 1 — bắt buộc ===== */
    add({
      id: 'kw', tier: 'must', ok: !!kw, fix: 'manual',
      name: 'Từ khoá chính',
      label: kw ? 'Đã có từ khoá chính: “' + kw + '”' : 'Chưa nhập từ khoá chính — Google khó biết ' + low(noun) + ' nói về gì',
      how: 'Nhập cụm 2–4 từ mà khách sẽ gõ lên Google để tìm.'
    });
    add({
      id: 'title', tier: 'must', ok: !!kw && has(title, kw) && title.length > 0 && title.length <= LIMITS.seoTitle.display,
      warn: !kw, fix: 'ai', words: 30, name: 'Tiêu đề trên Google',
      label: !kw ? 'Nhập từ khoá trước để kiểm tra tiêu đề'
        : (!title ? 'Chưa có tiêu đề hiển thị trên Google'
          : (!has(title, kw) ? 'Tiêu đề chưa nhắc tới “' + kw + '”'
            : (title.length > LIMITS.seoTitle.display ? 'Tiêu đề ' + title.length + ' ký tự — Google sẽ cắt bớt' : 'Tiêu đề có từ khoá, dài ' + title.length + ' ký tự'))),
      how: 'Đưa từ khoá lên đầu tiêu đề, giữ dưới ' + LIMITS.seoTitle.display + ' ký tự.'
    });
    add({
      id: 'desc', tier: 'must', ok: desc.length >= LIMITS.seoDesc.best[0] && desc.length <= LIMITS.seoDesc.display && (!kw || has(desc, kw)),
      warn: desc.length > 0, fix: desc.length > LIMITS.seoDesc.display ? 'auto' : 'ai', words: 40, name: 'Mô tả trên Google',
      label: !desc ? 'Chưa có mô tả hiển thị dưới tiêu đề trên Google'
        : (desc.length > LIMITS.seoDesc.display ? 'Mô tả ' + desc.length + ' ký tự — Google chỉ hiện khoảng ' + LIMITS.seoDesc.display + ' ký tự đầu'
          : (desc.length < LIMITS.seoDesc.best[0] ? 'Mô tả ' + desc.length + ' ký tự — hơi ngắn, nên viết đủ ' + LIMITS.seoDesc.best[0] + '–' + LIMITS.seoDesc.display
            : (kw && !has(desc, kw) ? 'Mô tả chưa nhắc tới “' + kw + '”' : 'Mô tả dài chuẩn (' + desc.length + ' ký tự)'))),
      how: 'Một câu nói rõ khách nhận được gì, có chứa từ khoá.'
    });
    add({
      id: 'slug', tier: 'must',
      ok: !!kw && !!slug && slugParts.length <= 5 && !slugStop.length && slug.indexOf(kwSlug.split('-')[0]) >= 0,
      warn: !kw, fix: 'auto', name: 'Đường dẫn',
      label: !kw ? 'Nhập từ khoá trước để kiểm tra đường dẫn'
        : (!slug ? 'Chưa có đường dẫn'
          : (slug.indexOf(kwSlug.split('-')[0]) < 0 ? 'Đường dẫn chưa có từ khoá — nên là “' + kwSlug + '”'
            : (slugParts.length > 5 ? 'Đường dẫn ' + slugParts.length + ' từ — rút còn tối đa 5 từ'
              : (slugStop.length ? 'Đường dẫn còn từ thừa: ' + slugStop.join(', ') : 'Đường dẫn gọn, có từ khoá')))),
      how: 'Rút về đúng từ khoá, bỏ từ nối.'
    });
    add({
      id: 'intro', tier: 'must', ok: !!kw && has(b.first100, kw), warn: !kw, fix: 'ai', words: 60,
      name: 'Từ khoá ở mở bài',
      label: !kw ? 'Nhập từ khoá trước để kiểm tra mở bài'
        : (has(b.first100, kw) ? 'Từ khoá xuất hiện ngay trong 100 từ đầu' : 'Từ khoá chưa xuất hiện trong 100 từ đầu — Google đọc mở bài trước'),
      how: 'Nhắc từ khoá tự nhiên ngay câu đầu hoặc câu thứ hai.'
    });
    add({
      id: 'len', tier: 'must', ok: b.words >= 300, warn: b.words >= 120, fix: 'ai', words: need(300 - b.words) + 40,
      name: 'Độ dài',
      label: b.words >= 300 ? noun + ' dài ' + b.words + ' từ — đủ để Google đánh giá'
        : noun + ' mới ' + b.words + ' từ, cần thêm khoảng ' + need(300 - b.words) + ' từ nữa',
      how: 'Viết thêm phần trả lời câu hỏi khách hay gặp.'
    });
    add({
      id: 'cover', tier: 'must', ok: !!ctx.hasImage && !!String(ctx.alt || '').trim(),
      warn: !!ctx.hasImage, fix: ctx.hasImage ? 'auto' : 'manual', name: 'Ảnh đại diện',
      label: !ctx.hasImage ? 'Chưa có ảnh đại diện — ' + low(noun) + ' sẽ kém nổi bật khi chia sẻ'
        : (String(ctx.alt || '').trim() ? 'Ảnh đại diện đã có mô tả' : 'Ảnh đại diện chưa có mô tả cho người khiếm thị'),
      how: 'Chọn một ảnh thật của bạn, rồi mô tả ảnh bằng một câu.'
    });

    /* ===== Nhóm 2 — nên có ===== */
    add({
      id: 'heads', tier: 'should',
      ok: b.heads.length >= 2 && !b.headJump && (!kw || b.headTexts.some(function (t) { return has(t, kw); })),
      fix: 'ai', words: 40, name: 'Tiêu đề phụ',
      label: b.heads.length < 2 ? 'Mới có ' + b.heads.length + ' tiêu đề phụ — nên chia thành ít nhất 2 mục'
        : (b.headJump ? 'Các mục đang nhảy cấp — mục con phải nằm dưới mục lớn'
          : (kw && !b.headTexts.some(function (t) { return has(t, kw); }) ? 'Chưa mục nào nhắc tới “' + kw + '”' : 'Có ' + b.heads.length + ' mục, chia cấp đúng')),
      how: 'Chia bài thành các mục, một mục có chứa từ khoá.'
    });
    add({
      id: 'linkin', tier: 'should', ok: b.linksIn.length >= 1, fix: 'auto', name: 'Liên kết trong site',
      label: b.linksIn.length ? 'Có ' + b.linksIn.length + ' liên kết tới nội dung khác trong website'
        : 'Chưa dẫn sang ' + (kind === 'page' ? 'trang' : 'bài') + ' nào khác của website — Google đánh giá site rời rạc',
      how: 'Dẫn sang 1–3 nội dung liên quan của chính bạn.'
    });
    add({
      id: 'linkout', tier: 'should', ok: b.linksOut.length >= 1 && !b.unsafeOut.length,
      fix: b.unsafeOut.length ? 'auto' : 'manual', name: 'Nguồn tham khảo',
      label: !b.linksOut.length ? 'Chưa dẫn nguồn bên ngoài nào — thêm 1 nguồn uy tín để tăng độ tin cậy'
        : (b.unsafeOut.length ? b.unsafeOut.length + ' liên kết ra ngoài chưa mở an toàn ở tab mới' : 'Đã dẫn nguồn bên ngoài, mở an toàn'),
      how: 'Dẫn một trang chính thống làm căn cứ cho số liệu.'
    });
    add({
      id: 'answer', tier: 'should',
      ok: !!b.paras.length && wc(b.paras[0]) >= 40 && wc(b.paras[0]) <= 60,
      fix: 'ai', words: 60, name: 'Đoạn trả lời nhanh',
      label: !b.paras.length ? 'Chưa có đoạn trả lời trực tiếp ở đầu bài'
        : (wc(b.paras[0]) < 40 ? 'Đoạn mở đầu chỉ ' + wc(b.paras[0]) + ' từ — nên 40–60 từ trả lời thẳng câu hỏi'
          : (wc(b.paras[0]) > 60 ? 'Đoạn mở đầu ' + wc(b.paras[0]) + ' từ — rút còn 40–60 từ để Google trích dẫn' : 'Mở đầu trả lời gọn trong ' + wc(b.paras[0]) + ' từ')),
      how: 'Trả lời thẳng câu hỏi chính trong 40–60 từ ngay đầu bài.'
    });
    add({
      id: 'list', tier: 'should', ok: b.lists >= 1, fix: 'ai', words: 80, name: 'Danh sách hoặc bảng',
      label: b.lists ? 'Có ' + b.lists + ' danh sách/bảng — dễ được Google trích' : 'Chưa có danh sách hay bảng nào — Google hay trích dạng này',
      how: 'Gom các ý ngang hàng thành gạch đầu dòng.'
    });
    add({
      id: 'imgalt', tier: 'should', ok: !b.imgs.length || !b.imgsNoAlt.length,
      fix: 'auto', name: 'Mô tả ảnh trong bài',
      label: !b.imgs.length ? 'Không có ảnh trong nội dung nên không cần mô tả ảnh'
        : (b.imgsNoAlt.length ? b.imgsNoAlt.length + '/' + b.imgs.length + ' ảnh trong bài chưa có mô tả' : 'Cả ' + b.imgs.length + ' ảnh trong bài đều có mô tả'),
      how: 'Mỗi ảnh một câu mô tả đúng nội dung ảnh.'
    });
    add({
      id: 'density', tier: 'should', ok: !!kw && density >= 0.5 && density <= 2.5,
      warn: density > 3 || !kw, fix: 'manual', name: 'Mật độ từ khoá',
      label: !kw || !b.words ? 'Chưa đủ dữ liệu để đo mật độ từ khoá'
        : (density > 3 ? 'Từ khoá lặp ' + kwHits + ' lần (' + density.toFixed(1) + '%) — đang nhồi, nên bớt'
          : (density < 0.5 ? 'Từ khoá mới xuất hiện ' + kwHits + ' lần (' + density.toFixed(1) + '%) — nên nhắc thêm' : 'Mật độ từ khoá ' + density.toFixed(1) + '% — vừa đẹp')),
      how: 'Giữ khoảng 1 lần nhắc mỗi 100 từ.'
    });
    add({
      id: 'excerpt', tier: 'should', skip: kind === 'page',
      ok: exc.length >= LIMITS.excerpt.best[0] && exc.length <= LIMITS.excerpt.best[1] && (!kw || has(exc, kw)), warn: exc.length > 0,
      fix: 'ai', words: 60, name: 'Mô tả ngắn',
      label: !exc ? 'Chưa có mô tả ngắn — dùng cho thẻ tin và khi chia sẻ'
        : (exc.length < LIMITS.excerpt.best[0] || exc.length > LIMITS.excerpt.best[1] ? 'Mô tả ngắn ' + exc.length + ' ký tự — nên để ' + LIMITS.excerpt.best[0] + '–' + LIMITS.excerpt.best[1]
          : (kw && !has(exc, kw) ? 'Mô tả ngắn chưa nhắc tới “' + kw + '”' : 'Mô tả ngắn đạt chuẩn (' + exc.length + ' ký tự)')),
      how: 'Tóm ý chính trong 2 câu, có từ khoá.'
    });

    /* ===== Nhóm 3 — nâng cao ===== */
    add({
      id: 'faq', tier: 'plus', ok: b.faq >= 2 && b.faq <= 4, fix: 'ai', words: 160, name: 'Câu hỏi thường gặp',
      label: b.faq >= 2 ? 'Có ' + b.faq + ' câu hỏi thường gặp' : 'Chưa có câu hỏi thường gặp — thêm 2–4 câu để hiện thêm ô trên Google',
      how: 'Thêm 2–4 câu khách hay hỏi, mỗi câu trả lời 2–3 dòng.'
    });
    add({
      id: 'readable', tier: 'plus', ok: !b.paras.length ? false : (!b.longPara && b.avgSentence > 0 && b.avgSentence < 25),
      fix: 'ai', words: 90, name: 'Dễ đọc',
      label: !b.paras.length ? 'Chưa có nội dung để đo'
        : (b.longPara ? b.longPara + ' đoạn dài quá 120 từ — nên tách nhỏ'
          : (b.avgSentence >= 25 ? 'Câu trung bình ' + b.avgSentence + ' từ — nên viết câu ngắn hơn' : 'Đoạn ngắn, câu trung bình ' + b.avgSentence + ' từ — dễ đọc')),
      how: 'Tách đoạn dài, mỗi câu một ý.'
    });
    add({
      id: 'uniq', tier: 'plus',
      ok: !!String(ctx.title || '').trim() && !(ctx.otherTitles || []).some(function (t) { return low(t).trim() === low(ctx.title).trim(); }),
      fix: 'manual', name: 'Tiêu đề không trùng',
      label: (ctx.otherTitles || []).some(function (t) { return low(t).trim() === low(ctx.title).trim(); })
        ? 'Tiêu đề trùng với nội dung khác trong website — hai bài sẽ tranh chỗ của nhau'
        : 'Tiêu đề không trùng nội dung nào khác',
      how: 'Đổi cho khác biệt, thêm chi tiết riêng.'
    });
    add({
      id: 'og', tier: 'plus', ok: !!ctx.ogImage, fix: 'auto', name: 'Ảnh khi chia sẻ',
      label: ctx.ogImage ? 'Đã có ảnh hiển thị khi chia sẻ' : 'Chưa có ảnh chia sẻ riêng — mạng xã hội sẽ cắt ảnh tuỳ ý',
      how: 'Dùng ảnh ngang khổ 1200×630.'
    });
    add({
      id: 'taxo', tier: 'plus', skip: kind === 'page',
      ok: !!(ctx.cats || []).length && (ctx.tags || []).length >= 2 && !!ctx.author,
      fix: 'manual', name: 'Chuyên mục & thẻ',
      label: !(ctx.cats || []).length ? 'Chưa chọn chuyên mục'
        : ((ctx.tags || []).length < 2 ? 'Mới có ' + (ctx.tags || []).length + ' thẻ — nên gắn ít nhất 2 thẻ'
          : (!ctx.author ? 'Chưa có tác giả — Google cần biết ai viết' : 'Đã đủ chuyên mục, thẻ và tác giả')),
      how: 'Chọn 1 chuyên mục và 2 thẻ sát nội dung.'
    });

    /* Chưa có từ khoá thì các mục bám từ khoá không nhờ AI hay máy làm thay được — phải nhập trước.
       Chỉ đổi những mục đang hỏng ĐÚNG vì thiếu từ khoá; 'heads' còn lỗi số lượng mục nên xét riêng. */
    if (!kw) {
      out.forEach(function (x) {
        var byKw = ['title', 'slug', 'intro', 'density'].indexOf(x.id) >= 0
          || (x.id === 'heads' && b.heads.length >= 2 && !b.headJump);
        if (!byKw) return;
        x.fix = 'manual';
        x.how = 'Nhập từ khoá chính ở ô trên trước đã.';
      });
    }

    var tiers = TIERS.map(function (t) {
      var its = out.filter(function (x) { return x.tier === t.key; });
      return {
        key: t.key, name: t.name, note: t.note, tone: t.tone, items: its,
        ok: its.filter(function (x) { return x.ok; }).length, total: its.length
      };
    });
    var okAll = out.filter(function (x) { return x.ok; }).length;
    var must = out.filter(function (x) { return x.tier === 'must' && !x.ok; });
    return {
      items: out, tiers: tiers, ok: okAll, total: out.length,
      mustMissing: must, blocking: must.length > 0, metrics: b, density: density
    };
  }

  /* Việc còn lại chia hai đường: máy tự làm (miễn phí) và phải sinh chữ (trừ từ AI) — §7 CLAUDE.md */
  function plan(rep) {
    var bad = (rep.items || []).filter(function (x) { return !x.ok; });
    var free = bad.filter(function (x) { return x.fix === 'auto'; });
    var paid = bad.filter(function (x) { return x.fix === 'ai'; });
    var manual = bad.filter(function (x) { return x.fix === 'manual'; });
    return {
      free: free, paid: paid, manual: manual,
      words: paid.reduce(function (n, x) { return n + (x.words || 0); }, 0),
      total: bad.length
    };
  }

  window.BSSEO = {
    audit: audit, plan: plan, TIERS: TIERS, slugify: slugify, scan: scan, wc: wc,
    LIMITS: LIMITS, limits: limits, meter: meter, hardCap: hardCap
  };
  try { document.dispatchEvent(new CustomEvent('bs-seo-ready')); } catch (e) {}
})();
