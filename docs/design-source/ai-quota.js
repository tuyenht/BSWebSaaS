/* BSWebSaaS — nguồn duy nhất cho hạn ngạch AI (sổ chữ + sổ ảnh).
   Sửa gói / giá / mức quy đổi ở đây, mọi màn dùng chung. CLAUDE.md §7, §7a, §7a-bis.
   Màn chuẩn hiển thị: "Hạn ngạch AI và mua thêm.dc.html" — cũng đọc bảng gói từ đây. */
(function (g) {
  /* Bảng gói — HAI SỔ RIÊNG, không quy đổi chéo (§7a).
     words  = từ AI mỗi tháng · images = lượt tạo ảnh mỗi tháng
     variants = số phương án ảnh mỗi lần tạo (gói cao nhiều phương án hơn → một ảnh trừ nhiều lượt hơn) */
  var PLANS = {
    free:  { key: 'free',  name: 'Miễn phí',        words: 5000,   images: 5,   variants: 1, price: 0,       free: true,
             fit: 'Dùng thử thật, không giới hạn thời gian — mỗi tháng cấp lại' },
    basic: { key: 'basic', name: 'Cơ bản',          words: 60000,  images: 20,  variants: 1, price: 490000,  bundled: true,
             fit: 'Website mới hoặc lên bài 2–3 bài mỗi tuần' },
    pro:   { key: 'pro',   name: 'Chuyên nghiệp',   words: 200000, images: 120, variants: 2, price: 990000,
             fit: 'Lên bài đều 3–5 bài mỗi tuần, có nhiều ngôn ngữ' },
    ent:   { key: 'ent',   name: 'Doanh nghiệp',    words: 600000, images: 320, variants: 3, price: 2690000,
             fit: 'Nhiều nhóm sản phẩm và viết hàng loạt' }
  };
  var ORDER = ['free', 'basic', 'pro', 'ent'];

  // Gói đang dùng của website — backend cấp qua BSAI.setPlan('pro').
  var CURRENT = 'basic';

  // Số từ AI trung bình một việc sinh ra (dùng khi website chưa có đủ mức dùng thật).
  var RATES = { perField: 140, perTpl: 120, perLabel: 12, perPost: 980, perSeoPair: 45 };

  /* Mức dùng thật 30 ngày qua CỦA CHÍNH WEBSITE — backend cấp qua BSAI.setUsage30().
     Câu "đủ khoảng N …" phải tính từ đây, không từ RATES (§7a-bis). */
  var USAGE30 = {
    post:    { n: 12, words: 11760 },
    label:   { n: 96, words: 1150 },
    tpl:     { n: 18, words: 2160 },
    rewrite: { n: 41, words: 520 },
    seo:     { n: 9,  words: 410 },
    image:   { n: 18, runs: 22 }
  };

  var LABEL = {
    post: 'bài viết', label: 'nhãn dịch', tpl: 'mẫu email',
    rewrite: 'lần viết lại đoạn', seo: 'cặp tiêu đề & mô tả SEO'
  };
  var FALLBACK = {
    post: 'perPost', label: 'perLabel', tpl: 'perTpl',
    rewrite: 'perLabel', seo: 'perSeoPair'
  };

  function nfm(v) { return Math.round(v).toLocaleString('vi-VN'); }
  function plan(key) { return PLANS[key || CURRENT] || PLANS.basic; }
  function plans() { return ORDER.map(function (k) { return PLANS[k]; }); }
  function variants(key) { return plan(key).variants; }

  // Số từ trung bình cho một việc: ưu tiên mức dùng thật, cần ≥ 3 mẫu mới đáng tin.
  function unit(kind) {
    var u = USAGE30[kind];
    if (u && u.n >= 3 && u.words > 0) return u.words / u.n;
    return RATES[FALLBACK[kind]] || RATES.perPost;
  }

  /* enough(left, ['post','label']) → "đủ khoảng 12 bài viết, hoặc 1.541 nhãn dịch" */
  function enough(left, kinds) {
    if (!(left > 0)) return 'đã hết từ AI trong tháng này';
    return 'đủ khoảng ' + (kinds || ['post']).map(function (k) {
      return nfm(Math.floor(left / unit(k))) + ' ' + (LABEL[k] || k);
    }).join(', hoặc ');
  }

  /* Sổ ảnh: quy lượt tạo ra số ẢNH DÙNG ĐƯỢC theo số phương án của gói (§7a).
     enoughImages(54) → "đủ khoảng 27 ảnh dùng được" ở gói 2 phương án. */
  function enoughImages(left, key) {
    if (!(left > 0)) return 'đã hết lượt tạo ảnh trong tháng này';
    var v = variants(key);
    var u = USAGE30.image;
    var perImg = (u && u.n >= 3 && u.runs > 0) ? (u.runs / u.n) : v;
    return 'đủ khoảng ' + nfm(Math.floor(left / perImg)) + ' ảnh dùng được';
  }

  function cost(kind, n) { return Math.round(unit(kind) * (n || 1)); }
  function imageCost(slots, key) { return (slots || 1) * variants(key); }

  g.BSAI = {
    PLANS: PLANS, ORDER: ORDER, RATES: RATES, LABEL: LABEL,
    plan: plan, plans: plans, variants: variants,
    nfm: nfm, unit: unit, enough: enough, enoughImages: enoughImages,
    cost: cost, imageCost: imageCost,
    usage30: function () { return USAGE30; },
    setUsage30: function (u) { USAGE30 = Object.assign({}, USAGE30, u || {}); },
    currentPlan: function () { return CURRENT; },
    setPlan: function (k) {
      if (!PLANS[k]) return;
      CURRENT = k;
      try { document.dispatchEvent(new CustomEvent('bs-ai-quota-ready')); } catch (e) {}
    }
  };
  try { document.dispatchEvent(new CustomEvent('bs-ai-quota-ready')); } catch (e) {}
})(window);
