/* content-workflow.js — NGUỒN DUY NHẤT cho trạng thái nội dung, vai trò và quy trình duyệt.
   Bám repo tuyenht/BSWebSaaS@main (đừng khai lại các bảng này ở màn nào):
     · app/Modules/Rbac/Registry/PermissionRegistry.php — 24 quyền tenant + 6 vai trò site_type "corporate"
     · app/Modules/Cms/Policies/EntryPolicy.php — own/any (edit, delete), submit, publish
     · app/Modules/Workflow/Registry/WorkflowTemplateRegistry.php — 2 mẫu duyệt (+ 'none' = đăng thẳng)
     · app/Modules/Cms/Actions/ApplyEntryWorkflowTransition.php — bước duyệt → trạng thái công khai
     · database/migrations/2000_…_create_content_tables.php + 2012_…_expand_entry_status_and_add_view_count.php
       entries.status CHECK ('draft','pending','published','scheduled','private','archived')
   Dùng: window.BSCW (+ event 'bs-cw-ready' khi tải xong). */
(function () {
  var TENANT_PERMS = [
    'entries.view', 'entries.create', 'entries.edit.own', 'entries.edit.any',
    'entries.delete.own', 'entries.delete.any', 'entries.submit', 'entries.review',
    'entries.approve', 'entries.publish', 'media.view', 'media.upload', 'media.delete',
    'taxonomies.manage', 'menus.manage', 'forms.view', 'forms.manage',
    'catalog.view', 'catalog.manage', 'settings.manage', 'users.invite', 'users.manage',
    'roles.manage', 'domains.manage'
  ];

  /* 6 nhóm quyền tenant. Nhãn tiếng Việt = tên hiển thị cho chủ SME; `key` = tên trong repo. */
  var ROLES = {
    'Chủ website': { key: 'owner', level: 90, perms: '*' },
    'Quản trị': { key: 'admin', level: 70, perms: TENANT_PERMS },
    'Biên tập': {
      key: 'editor', level: 50, perms: [
        'entries.view', 'entries.create', 'entries.edit.any', 'entries.delete.any',
        'entries.submit', 'entries.review', 'entries.approve', 'entries.publish',
        'media.view', 'media.upload', 'media.delete',
        'taxonomies.manage', 'menus.manage', 'forms.view', 'forms.manage',
        'catalog.view', 'catalog.manage'
      ]
    },
    'Người viết': {
      key: 'author', level: 30, perms: [
        'entries.view', 'entries.create', 'entries.edit.own', 'entries.delete.own',
        'entries.submit', 'media.view', 'media.upload'
      ]
    },
    'Cộng tác viên': {
      key: 'contributor', level: 20, perms: [
        'entries.view', 'entries.create', 'entries.edit.own', 'entries.submit', 'media.view'
      ]
    },
    'Chỉ xem': { key: 'viewer', level: 10, perms: ['entries.view', 'media.view', 'forms.view', 'catalog.view'] }
  };

  /* Trạng thái công khai (entries.status) + `reviewing`: chỉ là BƯỚC của quy trình biên tập,
     không phải giá trị của entries.status (nó quy về 'pending'). Màu = token (D0-3): chữ bậc -text, nền pha 8–14% cùng token → tự lật sáng/tối. */
  var UI = {
    published: { label: 'Đã đăng', c: 'var(--color-admin-success-text,#047767)', bg: 'color-mix(in srgb,var(--color-admin-success-text,#047767) 10%,transparent)', icon: 'ri-global-line', pub: true },
    scheduled: { label: 'Đã hẹn giờ', c: 'var(--color-admin-secondary-text,#2f69d4)', bg: 'color-mix(in srgb,var(--color-admin-secondary-text,#2f69d4) 10%,transparent)', icon: 'ri-calendar-schedule-line' },
    pending: { label: 'Chờ duyệt', c: 'var(--color-admin-warning-text,#906405)', bg: 'color-mix(in srgb,var(--color-admin-warning-text,#906405) 14%,transparent)', icon: 'ri-inbox-unarchive-line' },
    reviewing: { label: 'Đang phản biện', c: 'var(--color-admin-info-text,#026fa2)', bg: 'color-mix(in srgb,var(--color-admin-info-text,#026fa2) 12%,transparent)', icon: 'ri-chat-check-line', wfOnly: true },
    draft: { label: 'Bản nháp', c: 'var(--color-admin-dark-text,#212529)', bg: 'color-mix(in srgb,var(--color-admin-dark-text,#212529) 8%,transparent)', icon: 'ri-draft-line' },
    private: { label: 'Không công khai', c: 'var(--color-accent,#2050a0)', bg: 'color-mix(in srgb,var(--color-accent,#2050a0) 10%,transparent)', icon: 'ri-lock-2-line' },
    archived: { label: 'Đã gỡ khỏi website', c: 'var(--color-body-muted,#5b6275)', bg: 'color-mix(in srgb,var(--color-body-muted,#5b6275) 12%,transparent)', icon: 'ri-archive-line' }
  };

  var WF = {
    'Không duyệt — đăng thẳng': null,
    'Duyệt trước khi đăng': {
      key: 'admin_approval', name: 'Duyệt trước khi đăng',
      states: {
        draft: { label: 'Bản nháp', initial: true },
        pending: { label: 'Chờ duyệt' },
        published: { label: 'Đã đăng', published: true },
        archived: { label: 'Đã gỡ khỏi website', terminal: true }
      },
      transitions: [
        { key: 'submit', label: 'Gửi duyệt', from: 'draft', to: 'pending', perm: 'entries.submit' },
        { key: 'approve', label: 'Duyệt đăng', from: 'pending', to: 'published', perm: 'entries.approve' },
        { key: 'reject', label: 'Trả lại', from: 'pending', to: 'draft', perm: 'entries.review', note: true },
        { key: 'retract', label: 'Đưa về nháp', from: 'published', to: 'draft', perm: 'entries.review', note: true },
        { key: 'archive', label: 'Gỡ khỏi website', from: 'published', to: 'archived', perm: 'entries.approve' }
      ]
    },
    'Quy trình biên tập': {
      key: 'editorial', name: 'Quy trình biên tập',
      states: {
        draft: { label: 'Bản nháp', initial: true },
        pending: { label: 'Chờ biên tập' },
        reviewing: { label: 'Đang phản biện' },
        published: { label: 'Đã đăng', published: true },
        archived: { label: 'Đã gỡ khỏi website', terminal: true }
      },
      transitions: [
        { key: 'submit', label: 'Gửi biên tập', from: 'draft', to: 'pending', perm: 'entries.submit' },
        { key: 'to_review', label: 'Chuyển phản biện', from: 'pending', to: 'reviewing', perm: 'entries.review' },
        { key: 'approve', label: 'Duyệt đăng', from: 'reviewing', to: 'published', perm: 'entries.approve' },
        { key: 'request_changes', label: 'Yêu cầu sửa', from: 'reviewing', to: 'draft', perm: 'entries.review', note: true },
        { key: 'send_back', label: 'Trả lại tác giả', from: 'pending', to: 'draft', perm: 'entries.review', note: true },
        { key: 'retract', label: 'Đưa về nháp', from: 'published', to: 'draft', perm: 'entries.review', note: true },
        { key: 'archive', label: 'Gỡ khỏi website', from: 'published', to: 'archived', perm: 'entries.approve' }
      ]
    }
  };

  /* Tier-1 (không quy trình): mỗi trạng thái cần quyền gì — theo EntryPolicy.
     'archived' không nằm ở đây: chỉ gỡ khỏi website được bài ĐANG đăng (xem archiveTr). */
  var TIER1 = [
    { key: 'draft', label: 'Lưu nháp — chưa hiện trên website', perm: null, why: '' },
    { key: 'pending', label: 'Gửi duyệt', perm: 'entries.submit', why: 'Nhóm quyền của bạn không được gửi duyệt' },
    { key: 'scheduled', label: 'Hẹn giờ đăng', perm: 'entries.publish', why: 'Bạn chỉ được gửi duyệt, không được hẹn giờ đăng' },
    { key: 'published', label: 'Đăng', perm: 'entries.publish', why: 'Bạn chỉ được gửi duyệt, không được đăng' },
    { key: 'private', label: 'Để không công khai — thành viên có quyền vẫn xem được', perm: 'entries.publish', why: 'Bạn chỉ được gửi duyệt, không được đổi cách hiển thị' }
  ];

  function role(label) { return ROLES[label] || ROLES['Biên tập']; }
  function can(label, perm) { var r = role(label); return r.perms === '*' || r.perms.indexOf(perm) >= 0; }
  function wf(label) { return WF[label] || null; }

  /* Bước duyệt của một bản ghi. `scheduled`/`private` không nằm trong máy trạng thái của quy trình
     (repo: bài tạo trước khi bật quy trình bắt đầu ở bước đầu) → tính ở bước khởi đầu. */
  function wfStateOf(label, key) {
    var w = wf(label);
    if (!w) return key;
    if (w.states[key]) return key;
    return 'draft';
  }
  function transitions(label, key) {
    var w = wf(label);
    if (!w) return [];
    var from = wfStateOf(label, key);
    return w.transitions.filter(function (t) { return t.from === from; });
  }
  /* ApplyEntryWorkflowTransition: is_published → published · is_initial → draft · còn lại → pending */
  function statusOfState(label, key) {
    var w = wf(label), s = w && w.states[key];
    if (!s) return key;
    return s.published ? 'published' : (s.initial ? 'draft' : 'pending');
  }
  /* Nhãn + màu để hiển thị: khi có quy trình thì lấy tên bước của quy trình đó. */
  function ui(key, label) {
    var base = UI[key] || UI.draft, w = wf(label);
    if (w && w.states[key] && w.states[key].label) {
      return { label: w.states[key].label, c: base.c, bg: base.bg, icon: base.icon, pub: !!base.pub };
    }
    return base;
  }
  function isPublic(key) { return key === 'published'; }
  /* Danh sách trạng thái cho tier-1, đã canh quyền của vai trò đang đăng nhập. */
  function tier1(roleLabel) {
    return TIER1.map(function (it) {
      var ok = !it.perm || can(roleLabel, it.perm);
      return { key: it.key, label: it.label, allowed: ok, why: it.why, perm: it.perm };
    });
  }
  /* Gỡ khỏi website ở tier-1: chỉ từ 'published', cùng mức quyền với đăng. */
  function archiveTr(roleLabel, curKey) {
    if (curKey !== 'published') return null;
    return { key: 'archived', label: 'Gỡ khỏi website — giữ lại nội dung', to: 'archived', allowed: can(roleLabel, 'entries.publish'), why: 'Chỉ nhóm quyền được đăng bài mới gỡ khỏi website được' };
  }

  /* Metadata của 2 mẫu nền tảng: gói tối thiểu + một câu giải thích cho chủ SME.
     Tên gói lấy từ BSAI.PLANS (ai-quota.js) — không khai lại ở đây. */
  var WF_META = {
    admin_approval: { plan: 'basic', desc: 'Người viết gửi duyệt, người có quyền xem lại rồi bấm đăng.' },
    editorial: { plan: 'pro', desc: 'Có thêm một bước phản biện trước khi đăng — dùng cho ban biên tập nhiều người.' }
  };

  /* DỮ LIỆU THỬ — không có trong repo. Chỉ để kiểm tra giao diện chịu được N bước (2–6) và tên bước dài.
     Backend cho phép mỗi site tự đặt tên bước/nút, nên mọi màn phải đọc từ dữ liệu, không hardcode. */
  var DEMO_WF = {
    'Quy trình toà soạn (6 bước)': {
      key: 'demo_newsroom', name: 'Quy trình toà soạn (6 bước)', demo: true,
      states: {
        draft: { label: 'Bản thảo của phóng viên', initial: true },
        pending: { label: 'Chờ trưởng ban đọc lần đầu' },
        reviewing: { label: 'Đang phản biện chuyên môn' },
        copyedit: { label: 'Soát chính tả và trình bày' },
        published: { label: 'Đã đăng lên trang chủ', published: true },
        archived: { label: 'Đã gỡ khỏi website', terminal: true }
      },
      transitions: [
        { key: 'submit', label: 'Gửi trưởng ban', from: 'draft', to: 'pending', perm: 'entries.submit' },
        { key: 'to_review', label: 'Chuyển phản biện chuyên môn', from: 'pending', to: 'reviewing', perm: 'entries.review' },
        { key: 'to_copyedit', label: 'Chuyển soát chính tả', from: 'reviewing', to: 'copyedit', perm: 'entries.review' },
        { key: 'approve', label: 'Duyệt đăng', from: 'copyedit', to: 'published', perm: 'entries.approve' },
        { key: 'request_changes', label: 'Yêu cầu sửa lại nội dung', from: 'reviewing', to: 'draft', perm: 'entries.review', note: true },
        { key: 'send_back', label: 'Trả lại phóng viên', from: 'pending', to: 'draft', perm: 'entries.review', note: true },
        { key: 'retract', label: 'Đưa về nháp', from: 'published', to: 'draft', perm: 'entries.review', note: true },
        { key: 'archive', label: 'Gỡ khỏi website', from: 'published', to: 'archived', perm: 'entries.approve' }
      ]
    },
    'Duyệt nhanh (2 bước)': {
      key: 'demo_quick', name: 'Duyệt nhanh (2 bước)', demo: true,
      states: {
        draft: { label: 'Bản nháp', initial: true },
        published: { label: 'Đã đăng', published: true }
      },
      transitions: [
        { key: 'approve', label: 'Duyệt đăng', from: 'draft', to: 'published', perm: 'entries.approve' },
        { key: 'retract', label: 'Đưa về nháp', from: 'published', to: 'draft', perm: 'entries.review', note: true }
      ]
    }
  };

  function allWf(name) { return WF[name] || DEMO_WF[name] || null; }
  function planName(k) {
    try { return (window.BSAI && window.BSAI.PLANS[k] && window.BSAI.PLANS[k].name) || k; } catch (e) { return k; }
  }
  function planRank(k) { var o = ['free', 'basic', 'pro', 'ent']; return o.indexOf(k); }

  /* Danh sách mẫu để chọn trong Cài đặt — đã canh gói hiện tại (locked = thấy nhưng khoá). */
  function templates(curPlan) {
    var names = Object.keys(WF).filter(function (n) { return !!WF[n]; }).concat(Object.keys(DEMO_WF));
    return names.map(function (n) {
      var w = allWf(n), meta = WF_META[w.key] || { plan: 'basic', desc: '' };
      var need = meta.plan, locked = planRank(need) > planRank(curPlan || 'basic');
      return {
        name: n, key: w.key, demo: !!w.demo, desc: meta.desc,
        steps: steps(n), transitions: w.transitions.slice(),
        needPlan: need, needPlanName: planName(need), locked: locked
      };
    });
  }
  /* Các bước theo thứ tự khai báo — mỗi site tự đặt tên, UI chỉ đọc. */
  function steps(name) {
    var w = allWf(name); if (!w) return [];
    return Object.keys(w.states).map(function (k, i) {
      var s = w.states[k];
      return { key: k, label: s.label, index: i, initial: !!s.initial, published: !!s.published, terminal: !!s.terminal };
    });
  }
  /* Nút chuyển user ĐƯỢC BẤM ở bước hiện tại (đã canh quyền của vai trò). */
  function allowed(name, stateKey, roleLabel) {
    var w = allWf(name); if (!w) return [];
    var ss = steps(name), from = w.states[stateKey] ? stateKey : (ss[0] && ss[0].key);
    return w.transitions.filter(function (t) { return t.from === from && can(roleLabel, t.perm); })
      .map(function (t) {
        var st = w.states[t.to] || {};
        return {
          key: t.key, label: t.label, from: t.from, to: t.to, perm: t.perm,
          note: !!t.note, toLabel: st.label || t.to, toPublished: !!st.published,
          danger: !!t.note, primary: !!st.published
        };
      });
  }
  function stepOf(name, stateKey) {
    var ss = steps(name);
    for (var i = 0; i < ss.length; i++) if (ss[i].key === stateKey) return ss[i];
    return ss[0] || null;
  }

  /* D0-4b — nhóm quyền NỀN TẢNG (PermissionRegistry::platformRoles(), dòng 141–152). Nhãn = tên hiển thị; key = tên repo. */
  var PLATFORM_PERMS = [
    'platform.tenants.view', 'platform.tenants.manage', 'platform.sites.view', 'platform.sites.manage',
    'platform.sites.impersonate', 'platform.entitlements.manage', 'platform.workflows.manage',
    'platform.site_types.manage', 'platform.themes.manage', 'platform.billing.manage',
    'platform.users.manage', 'platform.audit.view'
  ];
  var PLATFORM_ROLES = {
    'Quản trị nền tảng': { key: 'super_admin', level: 100, perms: '*' },
    'Nhân viên hỗ trợ': { key: 'support', level: 50, perms: ['platform.tenants.view', 'platform.sites.view', 'platform.audit.view', 'platform.sites.impersonate'] },
    'Nhân viên thanh toán': { key: 'billing', level: 40, perms: ['platform.billing.manage', 'platform.tenants.view'] }
  };
  /* Quyền ĐỀ XUẤT (chưa có trong repo — Chờ backend): nhóm nào được cấp mặc định. Không thêm vào TENANT_PERMS để khỏi lệch repo. */
  var PROPOSED = {
    'activity.view': ['owner', 'admin'],
    'platform.staff.manage': ['super_admin']
  };
  function platformRole(label) { return PLATFORM_ROLES[label] || PLATFORM_ROLES['Quản trị nền tảng']; }
  function canOne(r, perm) {
    if (!perm) return true;
    if (perm === 'super_admin') return r.key === 'super_admin';
    if (r.perms === '*') return true;
    if (PROPOSED[perm]) return PROPOSED[perm].indexOf(r.key) >= 0;
    return r.perms.indexOf(perm) >= 0;
  }
  /* has(nhóm, 'a|b') — một trong các quyền là đủ. plane 'platform' đọc PLATFORM_ROLES, còn lại ROLES. */
  function has(label, expr, plane) {
    var r = plane === 'platform' ? platformRole(label) : role(label);
    return !expr || String(expr).split('|').some(function (p) { return canOne(r, p); });
  }

  /* ---- D0-5 · KHO BÀI MẪU DÙNG CHUNG (prototype) — danh sách, trình soạn, Hộp duyệt cùng đọc/ghi ----
     Nhận ra bài bằng LOẠI + ĐƯỜNG DẪN (slug công khai), không dùng id số. `st` = khóa của BSCW.UI / bước quy trình
     (draft · pending · reviewing · published · scheduled · private · archived). Chỉ sống trong phiên trang (không lưu
     trình duyệt) — bản thật: bảng entries + entry_translations. Mỗi lần ghi phát 'bs-cw-store' {type, slug, act, src}.
     Mẫu cố ý KHÔNG có bài chờ duyệt: hàng chờ của Hộp duyệt chỉ có bài người dùng vừa gửi trong phiên. */
  var TYPE_META = {
    pages: { label: 'Trang', base: '/', icon: 'ri-file-text-line', cats: [] },
    posts: { label: 'Tin tức', base: '/tin-tuc/', icon: 'ri-newspaper-line', cats: [['Tin công ty', 0], ['Thông báo nội bộ', 1], ['Hoạt động cộng đồng', 1], ['Kiến thức kỹ thuật', 0], ['Gia công CNC', 1], ['Vật liệu & xử lý nhiệt', 1], ['Đo kiểm & dung sai', 2], ['Dự án đã làm', 0], ['Tuyển dụng', 0]] },
    services: { label: 'Dịch vụ', base: '/dich-vu/', icon: 'ri-briefcase-line', cats: [['Gia công CNC', 0], ['Tiện CNC', 1], ['Phay CNC', 1], ['Khuôn mẫu', 0], ['Xử lý bề mặt', 0], ['Bảo trì máy', 0]] },
    projects: { label: 'Dự án', base: '/du-an/', icon: 'ri-folder-2-line', cats: [['Khuôn ép nhựa', 0], ['Dây chuyền lắp đặt', 0], ['Chi tiết chính xác', 0], ['Khách hàng nước ngoài', 1]] }
  };
  function R(type, name, path, st, cat, langs, au, upd, extra) {
    var o = { type: type, name: name, slug: TYPE_META[type].base.replace(/\/$/, '') + '/' + path, st: st, cat: cat, langs: langs, author: au, updated: upd, trashed: false, img: true };
    if (type === 'pages') o.slug = path ? '/' + path : '/';
    for (var k in (extra || {})) o[k] = extra[k];
    return o;
  }
  var SAMPLE = {
    posts: [
      R('posts', '5 lưu ý khi chọn xưởng gia công CNC', 'luu-y-chon-xuong-gia-cong-cnc', 'published', 'Kiến thức kỹ thuật', ['VI', 'EN', 'JA'], 'Ngọc Anh', '28/07/2026 · 09:24'),
      R('posts', 'An Vinh đầu tư máy phay 5 trục mới', 'dau-tu-may-phay-5-truc', 'published', 'Tin công ty', ['VI', 'EN'], 'Minh Trí', '24/07/2026 · 16:05'),
      R('posts', 'Dự án khuôn ép nhựa cho khách Nhật', 'du-an-khuon-ep-nhua', 'published', 'Dự án đã làm', ['VI', 'EN', 'JA', 'ZH'], 'Ngọc Anh', '19/07/2026 · 11:40'),
      R('posts', 'Sai số gia công: hiểu đúng để đặt hàng đúng', 'sai-so-gia-cong', 'draft', 'Kiến thức kỹ thuật', ['VI'], 'Thu Hà', '30/07/2026 · 14:32'),
      R('posts', 'Tuyển kỹ sư lập trình CNC 2026', 'tuyen-ky-su-cnc-2026', 'scheduled', 'Tuyển dụng', ['VI', 'EN'], 'Thu Hà', '31/07/2026 · 10:07', { schedText: '05/08/2026 · 08:00', schedAt: '2026-08-05T08:00' }),
      R('posts', 'So sánh thép C45 và SKD11 khi làm khuôn', 'so-sanh-c45-skd11', 'draft', 'Kiến thức kỹ thuật', ['VI'], 'Minh Trí', '01/08/2026 · 15:44'),
      R('posts', 'Quy trình kiểm tra chất lượng tại An Vinh', 'quy-trinh-kiem-tra-chat-luong', 'published', 'Tin công ty', ['VI', 'EN', 'JA'], 'Ngọc Anh', '12/07/2026 · 08:15'),
      R('posts', 'Bảng giá gia công tiện CNC tham khảo', 'bang-gia-tien-cnc', 'draft', 'Kiến thức kỹ thuật', ['VI'], 'Thu Hà', '02/08/2026 · 09:10'),
      R('posts', 'Khách hàng nói gì về An Vinh', 'khach-hang-noi-gi', 'published', 'Dự án đã làm', ['VI', 'EN'], 'Minh Trí', '05/07/2026 · 13:20'),
      R('posts', 'Bản tin nội bộ tháng 6', 'ban-tin-noi-bo-thang-6', 'draft', 'Tin công ty', ['VI'], 'Ngọc Anh', '28/06/2026 · 17:50', { trashed: true }),
      R('posts', 'Hội chợ cơ khí Hà Nội 2026', 'hoi-cho-co-khi-ha-noi-2026', 'draft', 'Tin công ty', ['VI', 'EN'], 'Minh Trí', '29/07/2026 · 10:35'),
      R('posts', 'Cách đọc bản vẽ kỹ thuật cơ bản', 'cach-doc-ban-ve-ky-thuat', 'published', 'Kiến thức kỹ thuật', ['VI', 'EN', 'JA', 'ZH'], 'Thu Hà', '02/07/2026 · 09:58')
    ],
    services: [
      R('services', 'Gia công phay CNC 5 trục', 'gia-cong-phay-cnc-5-truc', 'published', 'Phay CNC', ['VI', 'EN'], 'Ngọc Anh', '20/07/2026 · 09:00'),
      R('services', 'Tiện CNC chi tiết trục, bạc', 'tien-cnc-chi-tiet-truc', 'published', 'Tiện CNC', ['VI', 'EN'], 'Minh Trí', '18/07/2026 · 14:20'),
      R('services', 'Thiết kế và chế tạo khuôn ép nhựa', 'che-tao-khuon-ep-nhua', 'published', 'Khuôn mẫu', ['VI', 'EN', 'JA'], 'Ngọc Anh', '11/07/2026 · 10:45'),
      R('services', 'Anod hóa và mạ kẽm chi tiết nhôm', 'anod-hoa-ma-kem', 'draft', 'Xử lý bề mặt', ['VI'], 'Thu Hà', '30/07/2026 · 16:10'),
      R('services', 'Bảo trì định kỳ máy CNC tại xưởng khách', 'bao-tri-may-cnc', 'scheduled', 'Bảo trì máy', ['VI'], 'Minh Trí', '01/08/2026 · 08:30', { schedText: '12/08/2026 · 08:00', schedAt: '2026-08-12T08:00' })
    ],
    projects: [
      R('projects', 'Bàn giao dây chuyền phay CNC cho khách Bắc Ninh', 'ban-giao-day-chuyen-phay-cnc-bac-ninh', 'published', 'Dây chuyền lắp đặt', ['VI', 'EN'], 'Minh Trí', '22/07/2026 · 15:00'),
      R('projects', 'Khuôn ép vỏ thiết bị cho đối tác Nhật Bản', 'khuon-ep-vo-thiet-bi-nhat-ban', 'published', 'Khách hàng nước ngoài', ['VI', 'EN', 'JA'], 'Ngọc Anh', '15/07/2026 · 11:20'),
      R('projects', 'Chi tiết dung sai 0,01 mm cho ngành y tế', 'chi-tiet-dung-sai-y-te', 'draft', 'Chi tiết chính xác', ['VI'], 'Thu Hà', '29/07/2026 · 09:40'),
      R('projects', 'Hồ sơ năng lực 2026 — bản rút gọn', 'ho-so-nang-luc-2026-ban-rut-gon', 'private', 'Chi tiết chính xác', ['VI'], 'Ngọc Anh', '27/07/2026 · 17:05')
    ],
    pages: [
      R('pages', 'Trang chủ', '', 'published', '', ['VI', 'EN', 'JA', 'ZH'], 'Ngọc Anh', '02/07/2026 · 09:24', { template: 'Trang chủ', views: '12.480', viewsNum: 12480 }),
      R('pages', 'Giới thiệu', 'gioi-thieu', 'published', '', ['VI', 'EN', 'JA'], 'Minh Trí', '28/06/2026 · 16:05', { template: 'Mặc định', views: '3.214', viewsNum: 3214 }),
      R('pages', 'Dịch vụ', 'dich-vu', 'published', '', ['VI', 'EN'], 'Ngọc Anh', '30/06/2026 · 11:40', { template: 'Danh mục dịch vụ', views: '5.902', viewsNum: 5902 }),
      R('pages', 'Thiết kế website', 'dich-vu/thiet-ke-website', 'published', '', ['VI', 'EN'], 'Minh Trí', '24/06/2026 · 08:15', { template: 'Chi tiết dịch vụ', parent: '/dich-vu', views: '1.876', viewsNum: 1876 }),
      R('pages', 'Quảng cáo Google', 'dich-vu/quang-cao-google', 'draft', '', ['VI'], 'Thu Hà', '21/06/2026 · 14:32', { template: 'Chi tiết dịch vụ', parent: '/dich-vu', views: '0', viewsNum: 0 }),
      R('pages', 'SEO tổng thể', 'dich-vu/seo-tong-the', 'scheduled', '', ['VI', 'EN'], 'Thu Hà', '05/07/2026 · 10:07', { template: 'Chi tiết dịch vụ', parent: '/dich-vu', schedAt: '2026-07-08T09:00', schedText: '08/07/2026 · 09:00', views: '0', viewsNum: 0 }),
      R('pages', 'Tin tức', 'tin-tuc', 'published', '', ['VI', 'EN', 'JA', 'ZH'], 'Ngọc Anh', '01/07/2026 · 17:50', { template: 'Danh sách bài viết', views: '8.655', viewsNum: 8655 }),
      R('pages', 'Liên hệ', 'lien-he', 'published', '', ['VI', 'EN', 'JA'], 'Minh Trí', '15/06/2026 · 09:58', { template: 'Liên hệ', views: '2.190', viewsNum: 2190 }),
      R('pages', 'Chính sách bảo mật', 'chinh-sach-bao-mat', 'private', '', ['VI'], 'Thu Hà', '10/06/2026 · 13:20', { template: 'Văn bản', views: '312', viewsNum: 312 }),
      R('pages', 'Tuyển dụng', 'tuyen-dung', 'draft', '', ['VI'], 'Ngọc Anh', '04/07/2026 · 15:44', { template: 'Mặc định', views: '0', viewsNum: 0 })
    ]
  };
  var ROWS = {};
  function cp(o) { return JSON.parse(JSON.stringify(o)); }
  function rowsOf(type) { if (!ROWS[type]) ROWS[type] = cp(SAMPLE[type] || []); return ROWS[type]; }
  function emit(type, slug, act, src) { try { document.dispatchEvent(new CustomEvent('bs-cw-store', { detail: { type: type, slug: slug, act: act, src: src || null } })); } catch (e) {} }
  /* Bước "đang chờ người duyệt" của một quy trình = không phải bước đầu, không phải bước đăng, không phải bước kết thúc. */
  function waitingSteps(wfName) { var w = allWf(wfName); if (!w) return []; return Object.keys(w.states).filter(function (k) { var s = w.states[k]; return !s.initial && !s.published && !s.terminal; }); }
  var store = {
    types: function () { return Object.keys(TYPE_META); },
    meta: function (type) { return TYPE_META[type] || { label: type, base: '/' + type + '/', icon: 'ri-file-line', cats: [] }; },
    list: function (type) { return cp(rowsOf(type)); },
    get: function (type, slug) { var r = rowsOf(type).filter(function (x) { return x.slug === slug; })[0]; return r ? cp(r) : null; },
    /* put: thêm hoặc cập nhật theo đường dẫn; prevSlug khi bài vừa đổi đường dẫn. */
    put: function (type, row, src, prevSlug) {
      var a = rowsOf(type), key = prevSlug || row.slug, i = -1;
      for (var j = 0; j < a.length; j++) if (a[j].slug === key) { i = j; break; }
      var rec = Object.assign({}, i >= 0 ? a[i] : {}, cp(row), { type: type });
      if (i >= 0) a[i] = rec; else a.unshift(rec);
      emit(type, rec.slug, i >= 0 ? 'update' : 'create', src); return cp(rec);
    },
    patch: function (type, slug, patch, src) { var cur = store.get(type, slug); if (!cur) return null; return store.put(type, Object.assign(cur, patch), src, slug); },
    /* replace: màn danh sách ghi cả bảng sau một thao tác (đổi trạng thái, Thùng rác, sửa tại dòng). */
    replace: function (type, rows, src) { ROWS[type] = cp(rows).map(function (r) { r.type = type; return r; }); emit(type, null, 'replace', src); },
    remove: function (type, slug, src) { ROWS[type] = rowsOf(type).filter(function (x) { return x.slug !== slug; }); emit(type, slug, 'remove', src); },
    /* Hàng chờ của Hộp duyệt: bài ở bước chờ của quy trình đang bật; roleLabel → chỉ bài nhóm quyền đó có bước để bấm. */
    queue: function (wfName, roleLabel) {
      var ws = waitingSteps(wfName); if (!ws.length) return [];
      var out = [];
      store.types().forEach(function (t) { rowsOf(t).forEach(function (r) { if (!r.trashed && ws.indexOf(r.st) >= 0 && (!roleLabel || allowed(wfName, r.st, roleLabel).length)) out.push(cp(r)); }); });
      return out;
    },
    count: function (wfName, roleLabel) { return store.queue(wfName, roleLabel).length; },
    reset: function () { ROWS = {}; emit(null, null, 'reset', null); }
  };

  window.BSCW = {
    store: store,
    PLATFORM_PERMS: PLATFORM_PERMS, PLATFORM_ROLES: PLATFORM_ROLES, PROPOSED: PROPOSED,
    platformRole: platformRole, platformRoleNames: function () { return Object.keys(PLATFORM_ROLES); }, has: has,
    PERMS: TENANT_PERMS, ROLES: ROLES, UI: UI, WF: WF, TIER1: TIER1,
    WF_META: WF_META, DEMO_WF: DEMO_WF,
    allWf: allWf, templates: templates, steps: steps, allowed: allowed, stepOf: stepOf,
    planName: planName,
    roleNames: function () { return Object.keys(ROLES); },
    wfNames: function () { return Object.keys(WF); },
    statusKeys: function () { return ['draft', 'pending', 'published', 'scheduled', 'private', 'archived']; },
    role: role, can: can, wf: wf, wfStateOf: wfStateOf, transitions: transitions,
    statusOfState: statusOfState, ui: ui, isPublic: isPublic, tier1: tier1, archiveTr: archiveTr
  };
  try { document.dispatchEvent(new CustomEvent('bs-cw-ready')); } catch (e) {}
})();
