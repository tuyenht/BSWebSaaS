/* Hướng dẫn theo màn + hướng dẫn trực quan, bám đúng quyền của người đang đăng nhập (bản thử 07/10).
 * Nguồn nội dung = CHÍNH dữ liệu màn dùng để vẽ (view-model của màn) + BSCW (nhóm quyền, quy trình duyệt).
 * Không viết cứng tên nút: «Gửi duyệt» / «Gửi biên tập» / «Đăng», tên tab, mục menu ⋮, người duyệt…
 * đều đọc từ đó, nên đổi nhóm quyền / quy trình thì tên, số bước và việc được làm đổi theo.
 * Phần tử không hiện (không có quyền) → bước đó tự bỏ. "Đã xem" nhớ theo màn × (nhóm quyền + quy trình). */
(function () {
  'use strict';
  window.__bgRuns = (window.__bgRuns || 0) + 1;
  if (window.BSGUIDE) return;              /* tệp có thể được nạp lại (Shell vẽ lại thẻ script) — chỉ chạy một bản */
  var KEY = 'bs-guide-seen', RKEY = 'bs-guide-rolebar';
  var vis = function (e) { if (!e) return false; var r = e.getBoundingClientRect(); var cs = getComputedStyle(e); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
  var $ = function (sel, root) { var l = (root || document).querySelectorAll(sel); for (var i = 0; i < l.length; i++) if (vis(l[i])) return l[i]; return null; };
  var q = function (s) { return '«' + s + '»'; };
  var qs = function (a, join) { a = a.map(q); return a.length < 2 ? a.join('') : a.slice(0, -1).join(', ') + ' ' + (join || 'và') + ' ' + a[a.length - 1]; };
  var short = function (s) { return String(s || '').split(' — ')[0].replace(/…$/, '').trim(); };
  var cap = function (s) { return s.charAt(0).toUpperCase() + s.slice(1); };
  var screenKey = function () { var m = (location.hash || '').match(/#\/[a-z]+\/([^?]+)/); return m ? m[1] : ''; };
  var load = function (k) { try { return JSON.parse(localStorage.getItem(k) || '{}'); } catch (e) { return {}; } };
  var save = function (k, o) { try { localStorage.setItem(k, JSON.stringify(o)); } catch (e) {} };
  /* Mục registry của màn đang mở (purpose, help) — nguồn "Hay hỏi ở màn này" và nội dung khung khi màn chưa có bộ bước riêng.
     Màn theo loại nội dung ({T}) lấy tên loại từ tiêu đề màn. */
  var screenInfo = function () {
    try { var R = window.BSSCREENS, k = screenKey().split('/')[0], h1 = $('.scl h1') || $('h1'); if (!R || !k) return null;
      var m = /^([a-z0-9_-]+)\./.exec(k), t = {}; if (m) t[m[1]] = h1 ? (h1.innerText || '').trim() : m[1]; return R.resolve(k, t); } catch (e) { return null; }
  };
  var reduce = function () { return matchMedia('(prefers-reduced-motion: reduce)').matches; };

  /* Logic của màn danh sách = cùng view-model màn dùng để vẽ. */
  function listLogic() {
    var el = document.querySelector('.ql'); if (!el) return null;
    var k = Object.keys(el).filter(function (x) { return x.indexOf('__reactFiber') === 0; })[0], fb = k && el[k], n = 0;
    while (fb && n++ < 60) { var sn = fb.stateNode; if (sn && sn.logic && typeof sn.logic.listVals === 'function') return sn.logic; fb = fb.return; }
    return null;
  }

  /* Bối cảnh: nhóm quyền, quy trình, chuỗi bước tới khi đăng, ai làm bước nào — từ BSCW + view-model. */
  function listContext() {
    var L = listLogic(), C = window.BSCW; if (!L || !C) return null;
    var vm = L.listVals(); if (vm.stateKey && vm.stateKey !== 'data' && vm.stateKey !== 'empty') return null;
    var role = L.roleLabel(), W = L.wf(), u = vm.unit;
    var can = function (p) { return C.can(role, p); };
    var others = function (p) { return C.roleNames().filter(function (r) { return r !== role && C.can(r, p); }); };
    var who = function (p) { var o = others(p); return (can(p) ? ['bạn'] : []).concat(o.length ? ['nhóm ' + o.join(', ')] : []).join(' hoặc '); };
    var chain = [], submit = null, pendLabel = 'Chờ duyệt';
    if (W) {
      var init = Object.keys(W.states).filter(function (k) { return W.states[k].initial; })[0];
      var sub = W.transitions.filter(function (t) { return t.from === init && !(W.states[t.to] || {}).published; })[0];
      submit = sub && can(sub.perm) ? sub.label : null;
      var from = sub ? sub.to : null, guard = 0;
      if (sub) pendLabel = W.states[sub.to].label;
      while (from && !(W.states[from] || {}).published && guard++ < 8) {
        var ts = W.transitions.filter(function (t) { return t.from === from; }), fwd = ts.filter(function (t) { return !t.note; })[0];
        if (!fwd) break;
        chain.push({ label: fwd.label, state: W.states[from].label, who: who(fwd.perm), mine: can(fwd.perm),
          back: ts.filter(function (t) { return t.note && can(t.perm); }).map(function (t) { return t.label; }),
          backAll: ts.filter(function (t) { return t.note; }).map(function (t) { return t.label; }) });
        from = fwd.to;
      }
    } else {
      submit = can('entries.publish') ? null : (can('entries.submit') ? 'Gửi duyệt' : null);
      chain = [{ label: 'Đăng', state: 'Chờ duyệt', who: who('entries.publish'), mine: can('entries.publish'), back: can('entries.publish') ? ['Chuyển về bản nháp'] : [], backAll: ['Chuyển về bản nháp'] }];
    }
    return {
      L: L, vm: vm, role: role, W: W, can: can, others: others, submit: submit, chain: chain, pendLabel: pendLabel,
      reviewer: chain.some(function (x) { return x.mine; }) && (!!W || others('entries.submit').some(function (r) { return !C.can(r, 'entries.publish'); })),
      unit: u, type: vm.typeLabel || '', tabs: (vm.tabs || []).map(function (t) { return t.label; }), more: ((vm.moreTab || {}).items || []).map(function (t) { return t.label; }),
      editScope: can('entries.edit.any') ? 'any' : (can('entries.edit.own') ? 'own' : 'none'),
      delScope: can('entries.delete.any') ? 'any' : (can('entries.delete.own') ? 'own' : 'none'),
      scenario: role + '|' + (W ? W.key : 'direct')
    };
  }
  var phone = function () { return !!$('.ql-mst'); };          /* điện thoại: mọi trạng thái gom trong một nút bên phải, nút ghi trạng thái đang xem */
  var stBtn = function () { var e = $('.ql-mst .sx-t'); return 'nút ' + q(e ? e.innerText.trim() : 'Tất cả') + ' bên phải'; };
  function tabEl(label) {
    var t = Array.prototype.filter.call(document.querySelectorAll('.ql-tab'), function (x) { return vis(x) && (x.innerText || '').trim().indexOf(label) === 0; })[0];
    return t || $('.ql-tabbar') || $('.ql-mst');
  }
  function where(c, label) { return phone() ? 'Bấm ' + stBtn() + ', chọn ' + q(label) : 'Ở tab ' + q(label) + (c.more.indexOf(label) >= 0 ? ' (trong ' + q('Khác') + ')' : ''); }
  /* Dòng thứ i trên màn ↔ rows[i] của view-model. */
  function rowEl(i) { var rs = Array.prototype.filter.call(document.querySelectorAll('.ql-r:not(.ql-hd)'), vis); return rs[i] || null; }
  function firstRow(c, test) { var rows = c.vm.rows || []; for (var i = 0; i < rows.length; i++) if (test(rows[i]) && rowEl(i)) return { r: rows[i], e: rowEl(i) }; return null; }

  var LIST = [
    { tour: true, el: function () { return $('.ql-role'); }, title: function () { return 'Quyền của bạn ở màn này'; },
      text: function (c) { return 'Bạn đang dùng với nhóm quyền ' + q(c.role) + ': ' + c.vm.permSummary.toLowerCase() + '. ' + c.vm.roleBarText; } },
    { tour: true, el: function (c) { return c.vm.canCreate ? ($('[data-hdr-primary]') || $('.ql-add')) : null; }, title: function (c) { return c.vm.addLabel; },
      text: function (c) {
        var u = c.unit, s = 'Bấm ' + q(c.vm.addLabel) + ' để bắt đầu; chưa xong thì để ' + q('Bản nháp') + ', chưa ai ngoài website thấy. ';
        if (!c.W) {
          if (c.can('entries.publish')) return s + 'Viết xong bấm ' + q('Đăng') + ' là ' + u + ' hiện trên website ngay, hoặc ' + q('Hẹn giờ đăng') + ' để tự đăng đúng giờ.';
          if (c.submit) return s + 'Viết xong bấm ' + q(c.submit) + ': ' + c.chain[0].who + ' sẽ xem và bấm ' + q('Đăng') + '. ' + cap(u) + ' chưa được đăng thì chưa hiện trên website.';
          return s;
        }
        if (!c.submit) return s + 'Nhóm quyền của bạn chưa được gửi ' + u + ' đi duyệt.';
        var steps = c.chain.map(function (x) { return q(x.label) + ' (' + x.who + ')'; }).join(' → ');
        var all = c.chain.length && c.chain.every(function (x) { return x.mine; }), backs = [];
        c.chain.forEach(function (x) { x.backAll.forEach(function (b) { if (backs.indexOf(b) < 0) backs.push(b); }); });
        return s + 'Viết xong bấm ' + q(c.submit) + ': ' + u + ' sang ' + q(c.pendLabel) + ', rồi ' + steps + '. ' +
          (all ? 'Bạn được làm cả các bước này, nhưng ' + u + ' vẫn đi đủ từng bước. ' : 'Bạn nhận thông báo khi có kết quả. ') +
          (backs.length ? 'Nếu chưa đạt, người duyệt chọn ' + qs(backs, 'hoặc') + ' kèm ghi chú cần sửa gì — ' + u + ' về ' + q('Bản nháp') + ' để bạn sửa rồi gửi lại. ' : '') +
          'Chỉ sau ' + q(c.chain[c.chain.length - 1].label) + ', ' + u + ' mới hiện trên website.';
      } },
    { tour: true, el: function () { return $('.ql-tabbar') || $('.ql-mst'); }, title: function () { return phone() ? 'Xem theo trạng thái' : 'Các tab trạng thái'; },
      text: function (c) {
        var u = c.unit, mine = cap(u) + ' của tôi', all = c.tabs.concat(c.more).filter(function (t, i, a) { return a.indexOf(t) === i; });
        var s = phone() ? 'Bấm ' + stBtn() + ' để xem theo trạng thái: ' + qs(all, 'hoặc') + '. Nút luôn ghi trạng thái bạn đang xem và số ' + u + '.'
          : 'Tab: ' + qs(c.tabs.filter(function (t) { return c.more.indexOf(t) < 0; })) + '.' + (c.more.length ? ' Trong ' + q('Khác') + ': ' + qs(c.more) + '.' : '');
        if (all.indexOf(mine) >= 0 && c.vm.canCreate) s += ' ' + q(mine) + ' chỉ gồm ' + u + ' do bạn viết.';
        if (all.indexOf(c.pendLabel) >= 0) s += ' ' + q(c.pendLabel) + (c.reviewer && c.chain[0].mine ? ' là ' + u + ' đang chờ bạn xử lý.' : ' là ' + u + ' đã gửi, đang chờ người duyệt.');
        if (all.indexOf('Thùng rác') >= 0) s += ' ' + q('Thùng rác') + ' giữ ' + u + ' đã xóa 30 ngày, khôi phục được.';
        return s;
      } },
    { tour: true, el: function (c) { if (!c.reviewer) return null; var first = c.chain.filter(function (x) { return x.mine; })[0]; return tabEl(first.state); },
      title: function (c) { return cap(c.unit) + ' chờ bạn duyệt'; },
      text: function (c) {
        var mine = c.chain.filter(function (x) { return x.mine; });
        var u = c.unit;
        if (!c.W) return cap(u) + ' do nhóm ' + c.others('entries.submit').filter(function (r) { return !window.BSCW.can(r, 'entries.publish'); }).join(', ') + ' gửi nằm ở ' + (phone() ? stBtn() + ' › ' : 'tab ') + q(c.pendLabel) + '. Bấm vào trạng thái của ' + u + ' rồi chọn ' + q('Đăng') + ' (hoặc ' + q('Hẹn giờ đăng') + '); chưa đạt thì chọn ' + q('Chuyển về bản nháp') + ' và nhắn người viết cần sửa gì.';
        var parts = mine.map(function (x) { return where(c, x.state) + ': bấm vào trạng thái của ' + u + ' rồi chọn ' + q(x.label) + (x.back.length ? '; chưa đạt thì chọn ' + qs(x.back, 'hoặc') + ' và ghi rõ cần sửa gì' : '') + '.'; });
        return parts.join(' ') + ' Chuông thông báo báo khi có ' + u + ' mới chờ bạn.';
      } },
    { tour: true, el: function (c) { var f = firstRow(c, function (r) { return r.showEdit; }) || firstRow(c, function (r) { return r.showView || r.showLock; }); return f && $('.ql-acts .ql-main', f.e); },
      title: function (c) { return 'Mở một ' + c.unit; },
      text: function (c) {
        var u = c.unit;
        if (c.editScope === 'any') return 'Bấm ' + q('Sửa') + ' để mở trình soạn. Bạn sửa được mọi ' + u + '.';
        if (c.editScope === 'own') return cap(u) + ' do bạn viết có nút ' + q('Sửa') + ' để mở trình soạn. ' + cap(u) + ' của người khác chỉ có ' + q('Xem') + ' (đã đăng) hoặc ' + q('Xem trước') + ' (chưa đăng).';
        return 'Bấm ' + q('Xem') + ' (' + u + ' đã đăng) hoặc ' + q('Xem trước') + ' (chưa đăng) để đọc. Nhóm quyền của bạn chỉ được xem, không sửa.';
      } },
    { tour: true, el: function (c) { var f = firstRow(c, function (r) { return r.stBtn && r.stItems.length; }); return f && $('button.ql-st', f.e); },  /* dòng đầu tiên bạn đổi được trạng thái */
      title: function () { return 'Đổi trạng thái ngay tại dòng'; },
      text: function (c) { var f = firstRow(c, function (r) { return r.stBtn && r.stItems.length; }), r = f.r;
        return 'Bấm vào ' + q(r.stLabel) + ' để chọn ' + qs(r.stItems.map(function (x) { return short(x.label); }), 'hoặc') + '. Menu chỉ có việc bạn được làm' + (c.W ? ' ở bước này của quy trình' : '') + ', nên mỗi ' + c.unit + ' có thể khác nhau.'; } },
    { tour: true, el: function (c) { var f = firstRow(c, function (r) { return r.hasMore; }); return f && $('.ql-more', f.e); },
      title: function () { return 'Việc ít dùng (nút ⋮)'; },
      text: function (c) {
        var f = firstRow(c, function (r) { return r.hasMore; }), u = c.unit;
        var it = (f.r.moreItems || []).filter(function (m) { return !m.sep && m.label && !/Thùng rác|Xóa/.test(m.label); }).map(function (m) { return short(m.label); });
        var d = c.delScope === 'any' ? ' ' + q('Chuyển vào Thùng rác') + ' nằm cuối, màu đỏ; ' + u + ' vào ' + q('Thùng rác') + ', khôi phục được trong 30 ngày.'
          : c.delScope === 'own' ? ' Với ' + u + ' của bạn, cuối menu có ' + q('Chuyển vào Thùng rác') + ' (khôi phục được trong 30 ngày).'
          : ' Nhóm quyền của bạn không xóa được ' + u + '.';
        return 'Nút ⋮ có ' + qs(it) + '.' + d;
      } },
    /* chỉ có trong khung Hướng dẫn */
    { tour: false, el: function () { return $('.ql-search'); }, title: function (c) { return 'Tìm và lọc'; },
      text: function (c) { var f = (c.vm.filters || []).map(function (x) { return x.label; }).filter(Boolean);
        return 'Gõ vài chữ trong tiêu đề hoặc đường dẫn để tìm, có dấu hay không dấu đều được' + (f.length ? '; thu hẹp bằng ' + qs(f) : '') + '. ' + (phone() ? 'Nút phễu cạnh ô tìm là ' + q('Lọc') + ' (có số điều kiện đang dùng), nút mũi tên lên xuống là ' + q('Sắp xếp') + '. ' : '') + 'Điều kiện lọc và cách sắp xếp đang dùng hiện thành nhãn ngay dưới ô tìm, kèm số ' + c.unit + ' tìm thấy; bấm một nhãn để bỏ điều kiện đó, ' + q('Bỏ lọc') + ' để về đủ danh sách. ' + (c.vm.ctypeAttr === 'pages' ? 'Mặc định trang xếp theo ' + q('Thứ tự trên website') + '.' : 'Mặc định ' + c.unit + ' mới sửa gần đây đứng đầu.'); } },
    { tour: false, el: function (c) { return c.vm.canSelect ? ($('.ql-msel') || $('.ql-ck')) : null; }, title: function (c) { return 'Làm nhiều ' + c.unit + ' một lần'; },
      text: function (c) { var u = c.unit; return (phone() ? q('Chọn tất cả') + ' chọn mọi ' + u + ' trên trang này. ' : '') + 'Đánh dấu ô vuông đầu dòng để chọn nhiều ' + u + '. Mỗi nút trên thanh ' + q('Đã chọn') + ' ghi số ' + u + ' nó làm được; ' + u + ' bạn không có quyền thì được bỏ qua và nói rõ lý do.'; } },
    { tour: false, el: function (c) { return c.vm.ctypeAttr === 'pages' && c.editScope !== 'none' ? ($('.ql-tabbar') || $('.ql-mst')) : null; }, title: function () { return 'Trang cha – trang con và thứ tự'; },
      text: function (c) { return 'Trang xếp theo cây cha – con, đúng thứ tự trên website.' + (c.vm.canCreate ? ' Nút ' + q('Trang con') + ' trên mỗi dòng thêm trang nằm dưới trang đó.' : '') + ' Đổi thứ tự bằng ' + q('Chuyển lên') + ' / ' + q('Chuyển xuống') + ' trong nút ⋮ (hoặc kéo thả bằng chuột) khi đang xem ' + q('Tất cả') + ', không lọc, sắp xếp ' + q('Thứ tự trên website') + '.'; } },
    { tour: false, el: function (c) { return (c.vm.rows || []).some(function (r) { return r.showLock; }) ? ($('.ql-tabbar') || $('.ql-mst')) : null; }, title: function (c) { return cap(c.unit) + ' đang có người sửa'; },
      text: function (c) { return 'Nút có biểu tượng khóa nghĩa là có người khác đang mở ' + c.unit + ' đó. Bạn vẫn xem trước được ở nút ⋮; sửa được khi họ lưu xong.'; } }
  ];

  var GUIDES = {
    list: { match: function (k) { return /\.list$/.test(k) && !!document.querySelector('.ql'); }, ctx: listContext, steps: LIST,
      purpose: function (c) { return 'Mọi ' + c.type.toLowerCase() + ' của website nằm ở đây: tìm, lọc, xem trạng thái' + (c.vm.canCreate ? ', viết ' + c.unit + ' mới' : '') + ' và mở ' + c.unit + ' để ' + (c.editScope === 'none' ? 'đọc' : 'làm việc') + '.'; } }
  };
  function guide() { var k = screenKey(); for (var g in GUIDES) if (GUIDES[g].match(k)) return GUIDES[g]; return null; }
  function build(g, onlyTour) {
    var c = g.ctx(); if (!c) return { c: null, list: [] };
    var list = g.steps.filter(function (s) { return !onlyTour || s.tour; }).map(function (s) {
      var e; try { e = s.el(c); } catch (err) { e = null; } if (!e) return null;
      try { return { e: e, title: s.title(c, e), text: s.text(c, e), tour: s.tour }; } catch (err) { if (window.console) console.warn('guide step', err); return null; }
    }).filter(Boolean);
    return { c: c, list: list };
  }

  /* ---------- giao diện ---------- */
  function css() {
    if (document.getElementById('bs-guide-css')) return;
    var st = document.createElement('style'); st.id = 'bs-guide-css';
    st.textContent = [
      '.bg-scrim{position:fixed;inset:0;z-index:2000;background:rgba(15,18,30,.45)}',
      '.bg-panel{position:fixed;top:0;right:0;bottom:0;z-index:2001;width:min(440px,100%);display:flex;flex-direction:column;background:var(--color-surface,#fff);color:var(--color-body-text,#3c4765);box-shadow:-8px 0 40px rgba(20,22,30,.22);font-size:calc(14px + var(--fsp,0px));line-height:1.55}',
      '.bg-hd{display:flex;align-items:flex-start;gap:12px;padding:16px 16px 12px 20px;border-bottom:1px solid var(--color-border,#e9ecf4)}',
      '.bg-hd h2{flex:1;margin:10px 0 0;font-size:calc(18px + var(--fsp,0px));line-height:1.35;color:var(--color-heading,#1f2b4d)}',
      '.bg-x{flex:0 0 44px;width:44px;height:44px;border:0;border-radius:8px;background:transparent;color:inherit;cursor:pointer;font-size:20px}',
      '.bg-x:hover{background:var(--color-surface-hover,#f1f3f9)}',
      '.bg-bd{flex:1;overflow:auto;padding:16px 20px 24px}',
      '.bg-faq{margin:0 0 12px}.bg-faq dt{font-weight:600;color:var(--color-heading,#1f2b4d);margin-top:8px}.bg-faq dd{margin:2px 0 0}',
      '.bg-who{padding:10px 12px;border:1px solid var(--color-border,#e9ecf4);border-radius:8px;background:var(--color-body,#f4f5fa);margin-bottom:14px}',
      '.bg-who b,.bg-bd li b{color:var(--color-heading,#1f2b4d)}',
      '.bg-bd h3{margin:18px 0 8px;font-size:calc(15px + var(--fsp,0px));color:var(--color-heading,#1f2b4d)}',
      '.bg-bd ol{margin:0;padding-left:22px}.bg-bd li{margin:0 0 10px}',
      '.bg-go{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:44px;margin-top:14px;border:0;border-radius:8px;background:var(--color-admin-primary,#2050a0);color:#fff;font:inherit;font-weight:600;cursor:pointer}',
      '.bg-sup a{display:flex;align-items:center;gap:8px;min-height:44px;color:var(--color-accent,#2050a0)}',
      '.bg-hole{position:fixed;z-index:2002;border-radius:8px;box-shadow:0 0 0 3px var(--color-accent,#2050a0),0 0 0 9999px rgba(15,18,30,.55);pointer-events:none;transition:all .2s}',
      '.bg-card{position:fixed;z-index:2003;width:min(380px,calc(100vw - 24px));padding:16px;border-radius:10px;background:var(--color-surface,#fff);color:var(--color-body-text,#3c4765);box-shadow:0 12px 40px rgba(15,18,30,.3);font-size:calc(14px + var(--fsp,0px));line-height:1.55}',
      '.bg-card h3{margin:0 40px 6px 0;font-size:calc(16px + var(--fsp,0px));color:var(--color-heading,#1f2b4d)}',
      '.bg-card .bg-x{position:absolute;top:6px;right:6px}',
      '.bg-nav{display:flex;align-items:center;gap:8px;margin-top:12px}.bg-nav .n{flex:1;color:var(--color-body-muted,#5b6275);font-size:calc(13px + var(--fsp,0px))}',
      '.bg-nav button{min-height:44px;padding:0 14px;border-radius:8px;font:inherit;font-weight:600;cursor:pointer}',
      '.bg-prev{border:1px solid var(--color-border-input,#8a90a2);background:transparent;color:inherit}',
      '.bg-next{border:0;background:var(--color-admin-primary,#2050a0);color:#fff}',
      '.bg-offer{position:fixed;z-index:1500;width:min(340px,calc(100vw - 24px));padding:14px 16px;border:1px solid var(--color-border,#e9ecf4);border-radius:10px;background:var(--color-surface,#fff);color:var(--color-body-text,#3c4765);box-shadow:0 8px 28px rgba(15,18,30,.18);font-size:calc(14px + var(--fsp,0px));line-height:1.5}',
      '.bg-offer b{display:block;margin-bottom:4px;color:var(--color-heading,#1f2b4d)}.bg-offer .bg-nav{margin-top:8px}',
      '.bg-offer::before{content:"";position:absolute;top:-7px;left:var(--ax,24px);width:12px;height:12px;background:inherit;border-left:1px solid var(--color-border,#e9ecf4);border-top:1px solid var(--color-border,#e9ecf4);transform:rotate(45deg)}',
      '.ql-role.bg-rb{overflow:hidden;transition:max-height .25s ease,opacity .2s ease,padding .25s ease,margin .25s ease,border-width .25s ease}',
      '.ql-role.bg-rb-gone{max-height:0!important;opacity:0;padding-top:0!important;padding-bottom:0!important;margin-top:0!important;margin-bottom:0!important;border-width:0!important}',
      '@media (max-width:599px){.bg-card{left:12px!important;right:12px;bottom:12px;top:auto!important;width:auto}}',
      '@media (prefers-reduced-motion:reduce){.bg-hole,.ql-role.bg-rb{transition:none}}'
    ].join('\n');
    document.head.appendChild(st);
  }

  /* ---------- khung Hướng dẫn ---------- */
  var panel = null, opener = null;
  function closePanel() { if (!panel) return; panel.forEach(function (n) { n.remove(); }); panel = null; if (opener && opener.setAttribute) opener.setAttribute('aria-expanded', 'false'); if (opener && opener.focus) opener.focus(); }
  function openPanel(btn) {
    css(); closePanel(); hideOffer(); opener = btn || document.activeElement;
    var g = guide(), b = g ? build(g, false) : { c: null, list: [] }, c = b.c;
    var sup = (window.BSPLAT && window.BSPLAT.support) ? window.BSPLAT.support() : {};
    var h1 = $('.scl h1') || $('h1'), title = h1 ? (h1.innerText || '').trim() : '';
    var scrim = document.createElement('div'); scrim.className = 'bg-scrim'; scrim.onclick = closePanel;
    var p = document.createElement('aside'); p.className = 'bg-panel'; p.setAttribute('role', 'dialog'); p.setAttribute('aria-modal', 'true'); p.setAttribute('aria-labelledby', 'bg-h');
    var steps = b.list.filter(function (s) { return !s.e.classList.contains('ql-role'); });
    var n = b.list.filter(function (s) { return s.tour; }).length;
    var h = '<div class="bg-hd"><h2 id="bg-h">Hướng dẫn · ' + title + '</h2><button class="bg-x" aria-label="Đóng hướng dẫn" title="Đóng"><i class="ri-close-line" aria-hidden="true"></i></button></div><div class="bg-bd">';
    if (c) h += '<div class="bg-who">Bạn đang dùng với nhóm quyền <b>' + c.role + '</b>: ' + c.vm.permSummary.toLowerCase() + '.<br>' + c.vm.roleBarText + '</div>';
    var reg = screenInfo(), faq = ((reg && reg.help) || []).filter(function (x) { return x && x[0] && !/người hướng dẫn|hỗ trợ/i.test(x[0]); });
    h += '<p>' + (c ? g.purpose(c) : ((reg && (reg.desc || reg.purpose)) || 'Cần giúp ngay thì liên hệ hỗ trợ bên dưới.')) + '</p>';
    if (steps.length) {
      h += '<h3>Cách làm ở màn này</h3><ol>' + steps.map(function (s) { return '<li><b>' + s.title + ':</b> ' + s.text + '</li>'; }).join('') + '</ol>';
      if (n) h += '<button class="bg-go" type="button"><i class="ri-cursor-line" aria-hidden="true"></i>Xem trực tiếp trên màn (' + n + ' bước)</button>';
    }
    if (faq.length) h += '<h3>Hay hỏi ở màn này</h3><dl class="bg-faq">' + faq.map(function (x) { return '<dt>' + x[0] + '</dt><dd>' + x[1] + '</dd>'; }).join('') + '</dl>';
    h += '<h3>Cần người hỗ trợ?</h3><div class="bg-sup">' + (sup.phone ? '<a href="tel:' + String(sup.phone).replace(/\D/g, '') + '"><i class="ri-phone-line" aria-hidden="true"></i>Gọi ' + sup.phone + '</a>' : '') + (sup.zalo ? '<a href="https://zalo.me/' + String(sup.zalo).replace(/\D/g, '') + '" target="_blank" rel="noopener"><i class="ri-chat-3-line" aria-hidden="true"></i>Nhắn Zalo ' + sup.zalo + '</a>' : '') + (sup.email ? '<a href="mailto:' + sup.email + '"><i class="ri-mail-line" aria-hidden="true"></i>' + sup.email + '</a>' : '') + '</div></div>';
    p.innerHTML = h;
    p.querySelector('.bg-x').onclick = closePanel;
    var go = p.querySelector('.bg-go'); if (go) go.onclick = function () { var o = opener; closePanel(); opener = o; startTour(); };
    document.body.appendChild(scrim); document.body.appendChild(p); panel = [scrim, p];
    if (opener && opener.setAttribute) opener.setAttribute('aria-expanded', 'true');
    p.querySelector('.bg-x').focus();
  }

  /* ---------- hướng dẫn trực quan ---------- */
  var cur = 0, list = [], hole = null, tourEl = null, tourCtx = null;
  function endTour(done) {
    if (tourEl) { tourEl.remove(); tourEl = null; } if (hole) { hole.remove(); hole = null; }
    var o = load(KEY); if (tourCtx) o[screenKey() + '|' + tourCtx.scenario] = done ? 'done' : 'skip'; save(KEY, o);
    roleBar('rehide');
    if (opener && opener.focus) opener.focus();
  }
  function place() {
    var s = list[cur]; if (!s) return;
    s.e.scrollIntoView({ block: 'center', behavior: reduce() ? 'auto' : 'smooth' });
    setTimeout(function () {
      if (!tourEl) return;
      var r = s.e.getBoundingClientRect(), pad = 6;
      hole.style.left = (r.left - pad) + 'px'; hole.style.top = (r.top - pad) + 'px'; hole.style.width = (r.width + 2 * pad) + 'px'; hole.style.height = (r.height + 2 * pad) + 'px';
      tourEl.innerHTML = '<button class="bg-x" aria-label="Thoát hướng dẫn" title="Thoát"><i class="ri-close-line" aria-hidden="true"></i></button><h3 id="bg-t">' + s.title + '</h3><div aria-live="polite">' + s.text + '</div><div class="bg-nav"><span class="n">Bước ' + (cur + 1) + '/' + list.length + '</span>' + (cur ? '<button class="bg-prev" type="button">Quay lại</button>' : '') + '<button class="bg-next" type="button">' + (cur === list.length - 1 ? 'Xong' : 'Tiếp') + '</button></div>';
      var w = tourEl.offsetWidth, hgt = tourEl.offsetHeight, below = r.bottom + 12 + hgt < innerHeight;
      tourEl.style.left = Math.max(12, Math.min(r.left, innerWidth - w - 12)) + 'px';
      tourEl.style.top = (below ? r.bottom + 12 : Math.max(12, r.top - 12 - hgt)) + 'px';
      tourEl.querySelector('.bg-x').onclick = function () { endTour(false); };
      var pv = tourEl.querySelector('.bg-prev'); if (pv) pv.onclick = function () { cur--; place(); };
      tourEl.querySelector('.bg-next').onclick = function () { if (cur === list.length - 1) endTour(true); else { cur++; place(); } };
      tourEl.querySelector('.bg-next').focus();
    }, reduce() ? 30 : 280);
  }
  function startTour() {
    css(); hideOffer(); var g = guide(); if (!g) return;
    roleBar('show');                          /* bước 1 chỉ vào dải quyền → mở lại nếu đã thu gọn */
    setTimeout(function () {
      var b = build(g, true); list = b.list; tourCtx = b.c; if (!list.length) return; cur = 0;
      hole = document.createElement('div'); hole.className = 'bg-hole'; hole.setAttribute('aria-hidden', 'true');
      tourEl = document.createElement('div'); tourEl.className = 'bg-card'; tourEl.setAttribute('role', 'dialog'); tourEl.setAttribute('aria-labelledby', 'bg-t');
      document.body.appendChild(hole); document.body.appendChild(tourEl); place();
    }, reduce() ? 30 : 300);
  }

  /* ---------- lời mời lần đầu: bong bóng dưới nút "Hướng dẫn", không tự bật tour ---------- */
  var offer = null;
  function hideOffer() { if (offer) { offer.remove(); offer = null; } }
  function placeOffer() {
    if (!offer) return; var l = $('[data-guide-open]'); if (!l) { hideOffer(); return; }
    var r = l.getBoundingClientRect(), w = offer.offsetWidth, left = Math.max(12, Math.min(r.right - w, innerWidth - w - 12));
    offer.style.left = left + 'px'; offer.style.top = (r.bottom + 10) + 'px';
    offer.style.setProperty('--ax', Math.max(12, Math.min(w - 24, r.left + r.width / 2 - left - 6)) + 'px');
    offer.style.visibility = (r.bottom < 0 || r.top > innerHeight) ? 'hidden' : 'visible';
  }
  function maybeOffer() {
    hideOffer(); if (tourEl || panel) return; var g = guide(); if (!g) return; var c = g.ctx(); if (!c) return; var k = screenKey(), o = load(KEY);
    if (o[k + '|' + c.scenario]) return;
    var changed = Object.keys(o).some(function (x) { return x.indexOf(k + '|') === 0; });
    css(); offer = document.createElement('div'); offer.className = 'bg-offer'; offer.setAttribute('role', 'region'); offer.setAttribute('aria-label', 'Gợi ý hướng dẫn');
    offer.innerHTML = '<b>' + (changed ? 'Cách làm ở màn này vừa thay đổi' : 'Lần đầu dùng màn này?') + '</b>' + (changed ? 'Quyền hoặc quy trình duyệt của bạn đã khác trước. ' : '') + 'Xem hướng dẫn khoảng 1 phút, đúng với quyền của bạn.<div class="bg-nav"><span class="n"></span><button class="bg-prev" type="button">Để sau</button><button class="bg-next" type="button">Xem ngay</button></div>';
    offer.querySelector('.bg-prev').onclick = function () { var s = load(KEY); s[k + '|' + c.scenario] = 'later'; save(KEY, s); hideOffer(); };
    offer.querySelector('.bg-next').onclick = function () { opener = $('[data-guide-open]'); startTour(); };
    document.body.appendChild(offer); placeOffer();
  }
  document.addEventListener('scroll', placeOffer, true); window.addEventListener('resize', placeOffer);

  /* ---------- dải "nhóm quyền": tự thu gọn sau khi đủ thời gian đọc ----------
   * Lần đầu ở mỗi màn × (nhóm quyền + quy trình): hiện đủ, đếm thời gian đọc (8–15 giây theo số chữ).
   * Đếm tạm dừng khi chuột đang ở trên dải, focus trong dải, hoặc đang mở Hướng dẫn / hướng dẫn trực quan.
   * Chỉ thu gọn khi người dùng không thao tác trong 1,5 giây (nội dung không nhảy dưới tay).
   * Lần sau cùng tình huống: không hiện. Đổi nhóm quyền / quy trình → hiện lại từ đầu.
   * Nội dung luôn còn ở đầu khung "Hướng dẫn". */
  var rbTimer = null, lastAct = 0;
  ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (ev) { document.addEventListener(ev, function () { lastAct = Date.now(); }, { passive: true, capture: true }); });
  function rbShow(el) {
    el.classList.remove('bg-rb-gone'); el.removeAttribute('aria-hidden');
    el.style.maxHeight = el.scrollHeight + 'px';
    clearTimeout(el._bgT); el._bgT = setTimeout(function () { el.style.maxHeight = ''; }, reduce() ? 0 : 300);   /* bỏ chốt chiều cao: xoay máy / đổi cỡ chữ không cắt chữ */
  }
  function rbHide(el, animate) {
    clearTimeout(el._bgT);
    if (animate && !reduce()) { el.style.maxHeight = el.scrollHeight + 'px'; void el.offsetHeight; el.classList.add('bg-rb-gone'); }
    else { el.style.transition = 'none'; el.classList.add('bg-rb-gone'); void el.offsetHeight; el.style.transition = ''; }
    el.setAttribute('aria-hidden', 'true');
  }
  function roleBar(mode) {
    var el = document.querySelector('.ql-role'); if (!el) return;
    var g = guide(), c = g && g.ctx(); if (!c) return;
    var key = screenKey() + '|' + c.scenario, seen = !!load(RKEY)[key];
    css(); if (!el.classList.contains('bg-rb')) el.classList.add('bg-rb');
    if (mode === 'show') { clearTimeout(rbTimer); rbShow(el); return; }
    if (mode === 'rehide') { if (seen) rbHide(el, true); return; }
    if (el.dataset.bgKey === key) return;      /* đã xử lý cho đúng tình huống này */
    el.dataset.bgKey = key; clearTimeout(rbTimer);
    if (seen) { rbHide(el, false); return; }  /* lần sau: ẩn ngay, không có hiệu ứng co lại */
    rbShow(el);
    var words = (el.innerText || '').split(/\s+/).length, need = Math.max(8000, Math.min(15000, 3000 + words * 350)), shown = 0, last = Date.now();
    var tick = function () {
      var now = Date.now(), paused = el.matches(':hover') || el.contains(document.activeElement) || !!panel || !!tourEl || document.hidden;
      if (!paused) shown += now - last; last = now;
      if (shown < need || paused || now - lastAct < 1500) { rbTimer = setTimeout(tick, 400); return; }
      rbHide(el, true);
      var s = load(RKEY); s[key] = Date.now(); save(RKEY, s);
    };
    rbTimer = setTimeout(tick, 400);
  }
  /* Bắt dải ngay khi được vẽ (trước khi trình duyệt tô màn) → lần sau không chớp hiện rồi mới ẩn. */
  var moBusy = false;
  new MutationObserver(function () {
    if (moBusy || tourEl) return; var el = document.querySelector('.ql-role'); if (!el) return;
    moBusy = true; try { roleBar(); } finally { moBusy = false; }
  }).observe(document.documentElement, { childList: true, subtree: true });

  document.addEventListener('click', function (e) { var b = e.target.closest && e.target.closest('[data-guide-open]'); if (b) { e.preventDefault(); openPanel(b); } });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (tourEl) { e.stopPropagation(); endTour(false); } else if (panel) { e.stopPropagation(); closePanel(); }
  }, true);
  window.addEventListener('hashchange', function () { closePanel(); if (tourEl) endTour(false); setTimeout(maybeOffer, 1200); });
  window.addEventListener('load', function () { setTimeout(maybeOffer, 2500); });
  if (document.head) css(); else document.addEventListener('DOMContentLoaded', css);
  window.BSGUIDE = { open: openPanel, tour: startTour, offer: maybeOffer, roleBar: roleBar, build: function (t) { var g = guide(); return g ? build(g, !!t) : null; } };
})();
