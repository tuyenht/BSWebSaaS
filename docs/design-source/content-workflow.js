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
    'Quy trình tòa soạn (6 bước)': {
      key: 'demo_newsroom', name: 'Quy trình tòa soạn (6 bước)', demo: true,
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
      R('pages', 'Lịch sử hình thành', 'gioi-thieu/lich-su-hinh-thanh', 'published', '', ['VI', 'EN'], 'Minh Trí', '26/06/2026 · 10:12', { template: 'Mặc định', parent: '/gioi-thieu', views: '642', viewsNum: 642 }),
      R('pages', 'Đội ngũ', 'gioi-thieu/doi-ngu', 'draft', '', ['VI'], 'Thu Hà', '03/07/2026 · 14:05', { template: 'Mặc định', parent: '/gioi-thieu', views: '0', viewsNum: 0 }),
      R('pages', 'Dịch vụ', 'dich-vu', 'published', '', ['VI', 'EN'], 'Ngọc Anh', '30/06/2026 · 11:40', { template: 'Nhóm dịch vụ', views: '5.902', viewsNum: 5902 }),
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
  /* D1-4b · MÃ CÔNG KHAI (repo mở bài bằng ulid: /admin/content/{ulid}/edit, routes/web.php:231) — không dùng id số.
     Prototype sinh mã 26 ký tự kiểu ulid, cố định theo loại + đường dẫn ban đầu; đổi đường dẫn không đổi mã. */
  var B32 = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
  function genMa(seed) { var h1 = 2166136261, h2 = 5381, s = String(seed), out = '01J'; for (var i = 0; i < s.length; i++) { var c = s.charCodeAt(i); h1 = Math.imul(h1 ^ c, 16777619) >>> 0; h2 = (Math.imul(h2, 33) + c) >>> 0; } for (var k = 0; k < 23; k++) { var v = (k % 2 ? h1 : h2); out += B32[(v >>> ((k * 5) % 27)) & 31]; h1 = Math.imul(h1 ^ k, 2654435761) >>> 0; h2 = (h2 + h1) >>> 0; } return out; }
  function ensureMa(type) { rowsOf(type).forEach(function (r) { if (!r.ma) r.ma = genMa(type + ':' + r.slug); }); }
  var _list0 = store.list, _get0 = store.get, _put0 = store.put;
  store.list = function (type) { ensureMa(type); return _list0(type); };
  store.get = function (type, slug) { ensureMa(type); return _get0(type, slug); };
  store.put = function (type, row, src, prevSlug) { ensureMa(type); if (!row.ma) { var cur = _get0(type, prevSlug || row.slug); row = Object.assign({}, row, { ma: (cur && cur.ma) || genMa(type + ':' + row.slug + ':' + Date.now()) }); } return _put0(type, row, src, prevSlug); };
  store.byMa = function (type, ma) { if (!ma) return null; ensureMa(type); var r = rowsOf(type).filter(function (x) { return x.ma === ma; })[0]; return r ? cp(r) : null; };
  store.maOf = function (type, slug) { var r = store.get(type, slug); return r ? r.ma : null; };
  /* Cây chuyên mục theo loại (đọc TYPE_META.cats [tên, cấp]) → [{n, lvl, path:[tên cha…]}] — ô chọn chuyên mục của khuôn soạn. */
  store.catTree = function (type) { var st = [], m = TYPE_META[type]; return ((m && m.cats) || []).map(function (c) { st.length = c[1]; var path = st.slice(); st[c[1]] = c[0]; return { n: c[0], lvl: c[1], path: path, type: type }; }); };
  /* Loại nội dung (để khuôn soạn đọc nhãn, đường dẫn gốc) — chịu được loại mới khai thêm vào TYPE_META. */
  store.kinds = function () { return Object.keys(TYPE_META).map(function (k) { return { key: k, label: TYPE_META[k].label, base: TYPE_META[k].base, isPage: k === 'pages' }; }); };

  /* D2-1 · quyền kéo theo — chép PermissionRegistry::IMPLIES (dòng 71–84). Tick việc lớn → tự tick "Xem" cùng khu vực. */
  var IMPLIES = {
    'entries.create': ['entries.view'], 'entries.edit.own': ['entries.view'], 'entries.edit.any': ['entries.view'],
    'entries.delete.own': ['entries.view'], 'entries.delete.any': ['entries.view'], 'entries.submit': ['entries.view'],
    'entries.review': ['entries.view'], 'entries.approve': ['entries.view'], 'entries.publish': ['entries.view'],
    'media.upload': ['media.view'], 'media.delete': ['media.view'],
    'forms.manage': ['forms.view'], 'catalog.manage': ['catalog.view']
  };

  window.BSCW = {
    IMPLIES: IMPLIES,
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
  /* D1-4d · Thay đổi chờ của bài ĐANG CÔNG KHAI (repo: bảng entry_drafts — mỗi bài tối đa MỘT thay đổi chờ;
     Cms\Actions\SaveEntryDraft · ApplyEntryDraftTransition · ApplyEntryDraft · DiscardEntryDraft; EntryDraft::version()).
     Khóa = loại + mã công khai (hoặc đường dẫn). state: open (đang soạn / chờ người có quyền Đăng) · submitted (đã gửi duyệt, khóa)
     · returned (người duyệt trả lại kèm ghi chú). Chỉ sống trong phiên trang — bản thật đọc entry_drafts. */
  var DRAFTS = window.__bsDrafts || (window.__bsDrafts = {}); /* giữ qua lần nạp lại tệp (helmet có thể chạy lại script) */
  var PEOPLE = { 'Chủ website': 'Anh Tuấn', 'Quản trị': 'Chị Hà', 'Biên tập': 'Anh Nam', 'Người viết': 'Chị Lan', 'Cộng tác viên': 'Anh Khoa', 'Chỉ xem': 'Chị Mai' };
  /* Nguyên văn lang/vi.json:763–771 (entries.change_err_*) — EntryDraftRejectedException::REASONS; không hiện mã lý do */
  var CHANGE_ERR = {
    change_locked: 'Thay đổi này đã được gửi duyệt. Nhờ người duyệt trả lại để sửa tiếp.',
    change_pending: 'Thay đổi này đang chờ duyệt nên chưa bỏ được. Nhờ người duyệt trả lại trước.',
    change_stale: 'Thay đổi vừa được sửa hoặc xử lý ở nơi khác. Tải lại trang để xem bản mới nhất — không có gì bị mất.',
    not_live: 'Bài này hiện không có trên website, nên nội dung được sửa thẳng vào bài — không cần thay đổi chờ duyệt.',
    content_invalid: 'Thay đổi chưa hợp lệ: kiểm tra lại tiêu đề, đường dẫn, bố cục và chuyên mục rồi lưu lại.',
    category_governed: 'Chuyên mục này sẽ đưa bài ra khỏi quy trình duyệt của nó. Hãy chọn một chuyên mục cùng mục.',
    locale_disabled: 'Ngôn ngữ này đang tắt trên website. Bật lại ngôn ngữ đó để lưu thay đổi.',
    slug_taken: 'Đường dẫn của thay đổi này nay đã được nội dung khác dùng. Đổi đường dẫn rồi thử lại.',
    workflow_governed: 'Bài này đang theo quy trình duyệt — thay đổi cần được duyệt thay vì cập nhật thẳng lên website.'
  };
  function dk(type, key) { return type + '|' + key; }
  var drafts = {
    person: function (role) { return PEOPLE[role] || role || 'Bạn'; },
    get: function (type, key) { var r = key ? DRAFTS[dk(type, key)] : null; return r ? cp(r) : null; },
    put: function (type, key, rec, src) { var k = dk(type, key), cur = DRAFTS[k]; var r = Object.assign({}, cur || {}, cp(rec), { type: type, key: key, ver: ((cur && cur.ver) || 0) + 1 }); DRAFTS[k] = r; emit(type, key, 'draft', src); return cp(r); },
    del: function (type, key, src) { delete DRAFTS[dk(type, key)]; emit(type, key, 'draft-del', src); },
    list: function (state) { return Object.keys(DRAFTS).map(function (k) { return cp(DRAFTS[k]); }).filter(function (r) { return !state || r.state === state; }); }
  };
  /* Thông báo mẫu cho chuông (D2-4 vẽ): repo gửi câu `.change` trên 4 loại thông báo quy trình đang có (EntryNotifier gắn change => true) — không có loại mới */
  var NOTICES = window.__bsNotices || (window.__bsNotices = []);
  var notices = {
    SAMPLES: [{ kind: 'workflow', change: true, title: 'Có thay đổi chờ cập nhật', text: 'Chị Lan sửa “Gia công CNC cho linh kiện ô tô” (đang đăng) — mở bài để xem và cập nhật website.', at: '10:32' }],
    list: function () { return NOTICES.slice(); },
    push: function (n, src) { NOTICES.unshift(cp(n)); emit('notices', null, 'notice', src); }
  };
  /* D2-4 · THÔNG BÁO TRONG ỨNG DỤNG (repo: app/Modules/Notifications — bảng site_notifications theo website + người nhận, mã công khai ulid;
     Registry/NotificationKinds.php 11 loại · 4 nhóm; Support/NotificationFeed.php đếm chưa đọc, 20 mục mới nhất + "Tải thêm" theo ulid, đánh dấu đã đọc 1 / tất cả;
     Support/NotificationPreferences.php users.preferences.notifications.<loại>.<in_app|mail>, loại khóa luôn gửi; Console/PruneSiteNotifications.php giữ 90 ngày).
     Câu chữ = nguyên văn lang/vi.json:860–884 (notifications.*); SiteNotification::summary() chọn `.<sentence>` → `.change` → câu gốc.
     backend: sent = đã phát · unsent = loại có trong registry nhưng chưa nơi nào phát · proposed = Chờ backend (chưa có trong registry).
     Thông báo thuộc TỪNG website: feed() chỉ trả thông báo của website đang mở (không gộp nhiều website — Cần owner chốt). */
  var NT_VI = {
    'workflow.assigned': ':actor giao bài «:title» cho bạn.',
    'workflow.returned': ':actor trả lại bài «:title» để sửa thêm.',
    'workflow.published': ':actor đã đăng bài «:title».',
    'workflow.pending_review': ':actor gửi bài «:title» — đang chờ bạn duyệt.',
    'workflow.assigned.change': ':actor giao thay đổi của bài «:title» cho bạn.',
    'workflow.returned.change': ':actor trả lại thay đổi của bài «:title» để sửa thêm.',
    'workflow.published.change': ':actor đã cập nhật thay đổi của bài «:title» lên website.',
    'workflow.pending_review.change': ':actor gửi thay đổi của bài «:title» — đang chờ bạn duyệt.',
    'lead.new': 'Có khách liên hệ mới: :name.',
    'security.new_device_login': 'Tài khoản của bạn vừa đăng nhập từ một thiết bị mới.',
    'security.two_factor_disabled': 'Xác thực 2 lớp của tài khoản đã được tắt lúc :at.',
    'security.support_sign_in': ':staff (hỗ trợ :platform) đã đăng nhập dưới tên :member lúc :at (lý do: :reason).',
    'system.storage_near_limit': 'Thư viện ảnh & tệp sắp đầy dung lượng của gói.',
    'security.domain_taken_over': 'Một website khác đã chứng minh sở hữu :domains, nên tên miền đã được gỡ khỏi website này.',
    'system.domain_problem.failing': ':domains không còn trỏ về website. Hãy sửa bản ghi DNS trước :until, nếu không tên miền sẽ ngừng hoạt động ở đây.',
    'system.domain_problem.expiring': ':domains vẫn chưa trỏ về website: tên miền sẽ ngừng hoạt động ở đây vào :until.',
    'system.domain_problem.lost': ':domains đã không trỏ về website 30 ngày nên đã ngừng hoạt động ở đây.',
    'system.domain_problem.recovered': ':domains đã trỏ về website và hoạt động trở lại.',
    'system.domain_problem.wait_expired': ':domains chưa được trỏ về website trong 7 ngày nên đã được gỡ. Hãy thêm lại khi sẵn sàng.',
    /* Chờ backend — câu đề xuất, chưa có trong lang/vi.json */
    'security.password_changed': 'Mật khẩu tài khoản của bạn vừa được đổi lúc :at.',
    'system.site_suspended': 'Website đang tạm ngưng: :reason. Khách chưa xem được website cho tới khi mở lại.'
  };
  var NT_GROUPS = [
    { k: 'workflow', l: 'Duyệt bài', icon: 'ri-git-pull-request-line', tone: 'warn' },
    { k: 'lead', l: 'Khách liên hệ', icon: 'ri-mail-line', tone: 'ok' },
    { k: 'security', l: 'Bảo mật', icon: 'ri-shield-keyhole-line', tone: 'danger' },
    { k: 'system', l: 'Hệ thống', icon: 'ri-settings-3-line', tone: 'info' }
  ];
  /* in_app / mail / locked = NotificationKinds::all(); mailSent = repo đã gửi thư cho loại này (NotificationKinds: "mail is only honoured where the kind's sender can mail — security notices and domain problems, today").
     perm = ai nhận (Notify::holdersOf / người liên quan); roles = chỉ các nhóm này. */
  var NT_KINDS = [
    { k: 'workflow.pending_review', g: 'workflow', l: 'Có bài chờ bạn duyệt', d: 'Khi người viết gửi bài hoặc thay đổi của bài đang đăng.', in_app: true, mail: false, locked: false, backend: 'sent', mailSent: false, perm: 'entries.review|entries.approve' },
    { k: 'workflow.assigned', g: 'workflow', l: 'Bài được giao cho bạn', d: 'Khi người duyệt chuyển bài sang bước bạn phụ trách.', in_app: true, mail: false, locked: false, backend: 'sent', mailSent: false, perm: 'entries.review|entries.approve' },
    { k: 'workflow.returned', g: 'workflow', l: 'Bài của bạn bị trả lại', d: 'Kèm lý do người duyệt ghi.', in_app: true, mail: false, locked: false, backend: 'sent', mailSent: false, perm: 'entries.submit' },
    { k: 'workflow.published', g: 'workflow', l: 'Bài của bạn đã đăng', d: 'Khi bài hoặc thay đổi của bạn lên website.', in_app: true, mail: false, locked: false, backend: 'sent', mailSent: false, perm: 'entries.submit' },
    { k: 'lead.new', g: 'lead', l: 'Khách liên hệ mới', d: 'Gửi mọi người xem được khách liên hệ.', in_app: true, mail: false, locked: false, backend: 'sent', mailSent: false, perm: 'forms.view', owner: true },
    { k: 'security.new_device_login', g: 'security', l: 'Đăng nhập từ thiết bị mới', d: 'Tài khoản của bạn vừa đăng nhập trên máy lạ.', in_app: true, mail: true, locked: true, backend: 'unsent', mailSent: true, perm: '' },
    { k: 'security.two_factor_disabled', g: 'security', l: 'Xác thực 2 lớp bị tắt', d: 'Ngay khi xác thực 2 lớp của tài khoản bị tắt.', in_app: true, mail: true, locked: true, backend: 'sent', mailSent: true, perm: '' },
    { k: 'security.support_sign_in', g: 'security', l: 'Nhân viên hỗ trợ đăng nhập dưới tên thành viên', d: 'Kèm lý do; gửi mọi Chủ website.', in_app: true, mail: true, locked: true, backend: 'sent', mailSent: true, perm: '', roles: ['Chủ website'] },
    { k: 'security.domain_taken_over', g: 'security', l: 'Tên miền bị website khác lấy lại', d: 'Khi một website khác chứng minh sở hữu tên miền của bạn.', in_app: true, mail: true, locked: true, backend: 'sent', mailSent: true, perm: 'domains.manage', domain: true },
    { k: 'security.password_changed', g: 'security', l: 'Mật khẩu vừa được đổi', d: 'Ngay khi mật khẩu tài khoản được đổi.', in_app: true, mail: true, locked: true, backend: 'proposed', mailSent: false, perm: '' },
    { k: 'system.storage_near_limit', g: 'system', l: 'Thư viện ảnh & tệp sắp đầy', d: 'Khi dung lượng ảnh và tệp gần hết mức của gói.', in_app: true, mail: true, locked: false, backend: 'unsent', mailSent: false, perm: 'media.upload' },
    { k: 'system.domain_problem', g: 'system', l: 'Tên miền có vấn đề', d: 'Tên miền không còn trỏ về website, sắp ngừng, đã ngừng hoặc hoạt động lại.', in_app: true, mail: true, locked: false, backend: 'sent', mailSent: true, perm: 'domains.manage', domain: true },
    { k: 'system.site_suspended', g: 'system', l: 'Website bị tạm ngưng', d: 'Khi nền tảng tạm ngưng website (ví dụ quá hạn thanh toán).', in_app: true, mail: true, locked: false, backend: 'proposed', mailSent: false, perm: '', roles: ['Chủ website', 'Quản trị'] }
  ];
  /* Chuông của mặt Nền tảng — Chờ backend + Cần owner chốt danh sách loại (nền tảng chưa có kho thông báo). */
  var NT_PLATFORM = [
    { id: 'p1', icon: 'ri-store-2-line', tone: 'ok', g: 'Khách hàng', text: 'Website mới đăng ký: Nha khoa Minh An (gói Cơ bản).', act: 'Xem website', go: 'p-sites', params: {}, mins: 25, unread: true },
    { id: 'p2', icon: 'ri-global-line', tone: 'danger', g: 'Tên miền', text: '4 website có tên miền không còn trỏ về nền tảng — sẽ ngừng hoạt động sau 7 ngày nếu không sửa.', act: 'Xem danh sách', go: 'p-sites', params: {}, mins: 140, unread: true },
    { id: 'p3', icon: 'ri-bank-card-line', tone: 'warn', g: 'Thanh toán', text: 'Gói của Cơ Khí Chính Xác An Vinh hết hạn sau 3 ngày.', act: 'Xem khách hàng', go: 'p-tenants', params: {}, mins: 60 * 20, unread: false },
    { id: 'p4', icon: 'ri-user-shared-line', tone: 'info', g: 'Hỗ trợ', text: 'Lê Hoàng Phúc bắt đầu phiên đăng nhập hỗ trợ ở An Vinh (lý do: Khách nhờ sửa trình đơn đầu trang).', act: 'Xem nhật ký', go: 'p-impersonate', params: {}, mins: 60 * 30, unread: false }
  ];
  var NREAD = window.__bsNoticeRead || (window.__bsNoticeRead = {});
  var NPREF = window.__bsNoticePref || (window.__bsNoticePref = { digest: false, kinds: {} });
  function ntFill(key, data) { var s = NT_VI[key] || key; Object.keys(data || {}).forEach(function (k) { s = s.split(':' + k).join(String(data[k])); }); return s; }
  function ntKind(k) { var base = String(k).split('.').slice(0, 2).join('.'); for (var i = 0; i < NT_KINDS.length; i++) if (NT_KINDS[i].k === base) return NT_KINDS[i]; return null; }
  function ntPad(n) { return (n < 10 ? '0' : '') + n; }
  function ntWhen(mins) {
    var now = new Date(), d = new Date(now.getTime() - mins * 60000), hm = ntPad(d.getHours()) + ':' + ntPad(d.getMinutes());
    var y = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
    var sameDay = function (a, b) { return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); };
    var dmY = ntPad(d.getDate()) + '/' + ntPad(d.getMonth() + 1) + '/' + d.getFullYear();
    var day = sameDay(d, now) ? 'Hôm nay' : (sameDay(d, y) ? 'Hôm qua' : dmY);
    var ago = mins < 1 ? 'Vừa xong' : (mins < 60 ? Math.round(mins) + ' phút trước' : (sameDay(d, now) ? hm : day + ' · ' + hm));
    return { day: day, ago: ago, hm: hm, dmY: dmY };
  }
  function ntRow(type, name) { var rs = rowsOf(type); for (var i = 0; i < rs.length; i++) if (rs[i].name === name) { ensureMa(type); return rs[i]; } return null; }
  function ntFindByTitle(title) { var ts = Object.keys(TYPE_META); for (var i = 0; i < ts.length; i++) { var r = ntRow(ts[i], title); if (r) return r; } return null; }
  function ntOpen(type, name) { var r = ntRow(type, name); return r ? { go: type + '.edit', params: { ma: r.ma } } : { go: type + '.list', params: {} }; }
  function ntPlat() { try { return window.BSPLAT && window.BSPLAT.name ? window.BSPLAT.name() : 'nền tảng'; } catch (e) { return 'nền tảng'; } }
  /* Thông báo mẫu đã có trong website (thời gian = số phút trước lúc mở trang). Mã công khai kiểu ulid (genMa) — không id số. */
  function ntSeed() {
    var at = function (m) { var w = ntWhen(m); return w.hm + ' ' + w.dmY; };
    var o1 = ntOpen('posts', 'An Vinh đầu tư máy phay 5 trục mới'), o2 = ntOpen('posts', 'Sai số gia công: hiểu đúng để đặt hàng đúng'), o3 = ntOpen('posts', '5 lưu ý khi chọn xưởng gia công CNC'), o4 = ntOpen('posts', 'Quy trình kiểm tra chất lượng tại An Vinh');
    var dm = { go: 'settings', params: { section: 'domain', ten_mien: 'anvinh.vn' } };
    return [
      { s: 1, kind: 'lead.new', data: { name: 'Trần Văn Hùng (Công ty Cơ khí Hưng Phát)' }, mins: 12, act: 'Xem liên hệ', go: 'contacts', params: { ma: genMa('lead:hung') }, tPerm: 'forms.view' },
      { s: 2, kind: 'workflow.pending_review', change: true, data: { actor: 'Chị Lan', title: 'Gia công CNC cho linh kiện ô tô' }, mins: 35, act: 'Mở hộp duyệt', go: 'review', params: {}, tPerm: 'entries.review|entries.approve' },
      { s: 3, kind: 'system.domain_problem', sentence: 'failing', data: { domains: 'anvinh.vn và www.anvinh.vn', until: '14/10/2026' }, mins: 70, act: 'Mở Tên miền', go: dm.go, params: dm.params, tPerm: 'domains.manage' },
      { s: 4, kind: 'workflow.assigned', data: { actor: 'Anh Nam', title: 'An Vinh đầu tư máy phay 5 trục mới' }, mins: 150, act: 'Mở bài', go: o1.go, params: o1.params, tPerm: 'entries.review|entries.approve', roles: ['Biên tập', 'Quản trị'] },
      { s: 5, kind: 'lead.new', data: { name: 'Nguyễn Thị Mai' }, mins: 200, act: 'Xem liên hệ', go: 'contacts', params: { ma: genMa('lead:mai') }, tPerm: 'forms.view' },
      { s: 6, kind: 'workflow.returned', data: { actor: 'Chị Hà', title: 'Sai số gia công: hiểu đúng để đặt hàng đúng' }, note: 'Thêm bảng dung sai và một ảnh chi tiết sau gia công.', mins: 60 * 22, act: 'Sửa bài', go: o2.go, params: o2.params, tPerm: 'entries.edit.own|entries.edit.any', roles: ['Người viết', 'Cộng tác viên'] },
      { s: 7, kind: 'security.two_factor_disabled', data: { at: at(60 * 26) }, mins: 60 * 26, act: 'Bật lại xác thực 2 lớp', go: 'account', params: { tab: 'security' }, tPerm: '' },
      { s: 8, kind: 'workflow.published', data: { actor: 'Chị Hà', title: '5 lưu ý khi chọn xưởng gia công CNC' }, mins: 60 * 30, read: true, act: 'Mở bài', go: o3.go, params: o3.params, tPerm: 'entries.view', roles: ['Người viết', 'Cộng tác viên'] },
      { s: 9, kind: 'workflow.assigned', change: true, data: { actor: 'Chị Hà', title: 'Quy trình kiểm tra chất lượng tại An Vinh' }, mins: 60 * 34, read: true, act: 'Mở bài', go: o4.go, params: o4.params, tPerm: 'entries.review|entries.approve', roles: ['Biên tập'] },
      { s: 10, kind: 'security.support_sign_in', data: { staff: 'Lê Hoàng Phúc', platform: ntPlat(), member: 'Nguyễn Ngọc Anh', at: at(60 * 50), reason: 'Khách nhờ sửa trình đơn đầu trang' }, mins: 60 * 50, act: 'Xem nhật ký hoạt động', go: 'activity', params: {}, tPerm: 'users.manage' },
      { s: 11, kind: 'workflow.returned', change: true, data: { actor: 'Anh Nam', title: 'Quy trình kiểm tra chất lượng tại An Vinh' }, note: 'Ảnh mới chưa có mô tả — thêm mô tả rồi gửi lại.', mins: 60 * 54, read: true, act: 'Sửa bài', go: o4.go, params: o4.params, tPerm: 'entries.edit.own|entries.edit.any', roles: ['Người viết', 'Cộng tác viên'] },
      { s: 12, kind: 'workflow.published', change: true, data: { actor: 'Anh Nam', title: 'An Vinh đầu tư máy phay 5 trục mới' }, mins: 60 * 75, read: true, act: 'Mở bài', go: o1.go, params: o1.params, tPerm: 'entries.view', roles: ['Người viết', 'Cộng tác viên'] },
      { s: 13, kind: 'system.domain_problem', sentence: 'recovered', data: { domains: 'anvinh.com' }, mins: 60 * 96, read: true, act: 'Mở Tên miền', go: dm.go, params: { section: 'domain', ten_mien: 'anvinh.com' }, tPerm: 'domains.manage' },
      { s: 14, kind: 'security.domain_taken_over', data: { domains: 'anvinh.com.vn' }, mins: 60 * 140, read: true, act: 'Mở Tên miền', go: dm.go, params: { section: 'domain', ten_mien: 'anvinh.com.vn' }, tPerm: 'domains.manage' },
      /* nhận khi người xem CÒN quyền xem khách liên hệ — đổi nhóm quyền rồi thì bấm vẫn mở màn đích, màn đích hiện "không có quyền" */
      { s: 15, kind: 'lead.new', data: { name: 'Phạm Quốc Bảo' }, mins: 60 * 24 * 25, read: true, act: 'Xem liên hệ', go: 'contacts', params: { ma: genMa('lead:bao') }, tPerm: 'forms.view', keep: true },
      /* loại có nhưng CHƯA phát (unsent) và loại Chờ backend (proposed) — chỉ hiện khi bật "Hiện ghi chú thiết kế" */
      { s: 16, kind: 'security.new_device_login', data: {}, mins: 60 * 5, act: 'Xem thiết bị', go: 'account', params: { tab: 'sessions' }, tPerm: '' },
      { s: 17, kind: 'system.storage_near_limit', data: {}, mins: 60 * 28, act: 'Mở thư viện', go: 'media', params: {}, tPerm: 'media.view' },
      { s: 18, kind: 'security.password_changed', data: { at: at(60 * 47) }, mins: 60 * 47, read: true, act: 'Xem phiên đăng nhập', go: 'account', params: { tab: 'sessions' }, tPerm: '' },
      { s: 19, kind: 'system.site_suspended', data: { reason: 'quá hạn thanh toán 15 ngày' }, mins: 60 * 24 * 9, read: true, act: 'Xem gói & mức dùng', go: 'settings', params: { section: 'quota' }, tPerm: 'settings.manage' }
    ];
  }
  var NT_ACT_ICON = { 'workflow.pending_review': 'ri-git-pull-request-line', 'workflow.assigned': 'ri-user-received-2-line', 'workflow.returned': 'ri-arrow-go-back-line', 'workflow.published': 'ri-global-line', 'lead.new': 'ri-mail-line', 'security.new_device_login': 'ri-device-line', 'security.two_factor_disabled': 'ri-shield-cross-line', 'security.support_sign_in': 'ri-user-shared-line', 'security.domain_taken_over': 'ri-error-warning-line', 'security.password_changed': 'ri-lock-password-line', 'system.storage_near_limit': 'ri-hard-drive-3-line', 'system.domain_problem': 'ri-global-line', 'system.site_suspended': 'ri-pause-circle-line' };
  function ntTone(kind, sentence) { if (kind === 'system.domain_problem') return sentence === 'recovered' ? 'ok' : 'danger'; if (kind === 'workflow.published') return 'ok'; if (kind === 'workflow.returned') return 'warn'; if (kind.indexOf('security') === 0) return 'danger'; if (kind === 'lead.new') return 'ok'; if (kind === 'system.storage_near_limit' || kind === 'system.site_suspended') return 'warn'; return 'brand'; }
  function ntPrefOn(kind, ch) { var K = ntKind(kind); if (!K) return false; if (K.locked) return K[ch]; var m = NPREF.kinds[K.k]; return (m && typeof m[ch] === 'boolean') ? m[ch] : K[ch]; }
  function ntCan(role, k) { if (!k) return true; return has(role, k); }
  function ntGets(role, K, it) { if (!K) return false; if (it && it.keep) return true; var roles = (it && it.roles) || K.roles; if (roles && roles.indexOf(role) < 0) return false; return ntCan(role, K.perm); }
  function ntMake(it, role) {
    var K = ntKind(it.kind), key = it.kind + (it.sentence ? '.' + it.sentence : (it.change ? '.change' : '')), w = ntWhen(it.mins), g = NT_GROUPS.filter(function (x) { return x.k === K.g; })[0];
    var id = it.id || genMa('ntf:' + (it.s || key + JSON.stringify(it.data)));
    var text = ntFill(key, it.data);
    return { id: id, kind: K.k, sentenceKey: 'notifications.' + key, group: K.g, groupLabel: g.l, icon: NT_ACT_ICON[K.k] || g.icon, tone: ntTone(K.k, it.sentence), text: text, note: it.note || '', act: it.act, go: it.go, params: it.params || {},
      mins: it.mins, ago: w.ago, day: w.day, unread: !NREAD[id] && !(it.read && NREAD[id] !== false), backend: K.backend, denied: !ntCan(role, it.tPerm), hay: (text + ' ' + (it.note || '') + ' ' + g.l).toLowerCase() };
  }
  function ntFeed(o) {
    o = o || {}; var role = o.role || 'Chủ website';
    if (o.plane === 'platform') return NT_PLATFORM.map(function (p) { var w = ntWhen(p.mins), id = genMa('ntfp:' + p.id); return { id: id, kind: 'platform', group: 'platform', groupLabel: p.g, icon: p.icon, tone: p.tone, text: p.text, note: '', act: p.act, go: p.go, params: p.params, mins: p.mins, ago: w.ago, day: w.day, unread: NREAD[id] === undefined ? p.unread : !NREAD[id], backend: 'proposed', denied: false, hay: (p.text + ' ' + p.g).toLowerCase() }; });
    var out = [];
    /* 1 · bài đang chờ ở bước người xem bấm được (D0-5: Người viết gửi duyệt trong phiên) → workflow.pending_review */
    if (o.wf && WF[o.wf] && (o.wf !== 'Không duyệt — đăng thẳng')) {
      store.queue(o.wf, role).forEach(function (r) { ensureMa(r.type); var rr = store.get(r.type, r.slug) || r; out.push({ id: genMa('ntf:pr:' + r.type + ':' + rr.ma + ':' + (rr.updated || '')), kind: 'workflow.pending_review', data: { actor: rr.author || 'Chị Lan', title: rr.name }, mins: (rr._subMins != null ? rr._subMins : 0.5), act: 'Mở bài', go: r.type + '.edit', params: { ma: rr.ma }, tPerm: 'entries.review|entries.approve' }); });
    }
    /* 2 · thay đổi của bài đang đăng (D1-4d: BSCW.notices.push) → câu `.change` của workflow.pending_review — không thêm loại mới */
    NOTICES.forEach(function (n, i) { var m = /^(.*?) sửa “(.*?)”/.exec(n.text || ''); var title = m ? m[2] : (n.title || ''); var r = ntFindByTitle(title); out.push({ id: genMa('ntf:chg:' + i + ':' + (n.text || '') + (n.at || '')), kind: 'workflow.pending_review', change: true, data: { actor: m ? m[1] : 'Chị Lan', title: title }, mins: 0.5 + (NOTICES.length - 1 - i) * 0.01, act: 'Mở bài', go: r ? r.type + '.edit' : 'review', params: r ? { ma: r.ma } : {}, tPerm: 'entries.publish|entries.approve', roles: null }); });
    ntSeed().forEach(function (s) { out.push(s); });
    return out.filter(function (it) { var K = ntKind(it.kind); if (!K) return false; if (!o.notes && K.backend !== 'sent') return false; if (!ntGets(role, K, it)) return false; return ntPrefOn(K.k, 'in_app'); })
      .map(function (it) { return ntMake(it, role); })
      .sort(function (a, b) { return a.mins - b.mins; });
  }
  notices.GROUPS = NT_GROUPS;
  notices.KINDS = NT_KINDS;
  notices.VI = NT_VI;
  notices.RETAIN_DAYS = 90;
  notices.PAGE = 20;
  notices.feed = ntFeed;
  notices.kind = ntKind;
  notices.gets = function (role, k) { return ntGets(role, ntKind(k), null); };
  notices.unread = function (o) { return ntFeed(o).filter(function (x) { return x.unread; }).length; };
  notices.badge = function (n) { return n >= 100 ? '99+' : String(n); };
  notices.markRead = function (id, src) { if (!id || NREAD[id] === true) return; NREAD[id] = true; emit('notices', null, 'read', src); };
  notices.markUnread = function (id, src) { if (!id) return; NREAD[id] = false; emit('notices', null, 'unread', src); };
  notices.markAll = function (ids, src) { var n = 0; (ids || []).forEach(function (id) { if (NREAD[id] !== true) { NREAD[id] = true; n++; } }); if (n) emit('notices', null, 'read-all', src); return n; };
  notices.restore = function (ids, src) { (ids || []).forEach(function (id) { NREAD[id] = false; }); emit('notices', null, 'unread', src); };
  notices.pref = function (k, ch) { return ntPrefOn(k, ch); };
  notices.setPref = function (k, ch, v, src) { var K = ntKind(k); if (!K || K.locked) return false; var m = NPREF.kinds[K.k] || (NPREF.kinds[K.k] = {}); m[ch] = !!v; emit('notices', null, 'pref', src); return true; };
  notices.digest = function (v, src) { if (typeof v === 'boolean') { NPREF.digest = v; emit('notices', null, 'pref', src); } return !!NPREF.digest; };
  window.BSCW.drafts = drafts;
  window.BSCW.CHANGE_ERR = CHANGE_ERR;
  window.BSCW.notices = notices;
  try { document.dispatchEvent(new CustomEvent('bs-cw-ready')); } catch (e) {}
})();
