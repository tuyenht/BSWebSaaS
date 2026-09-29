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

  /* 6 vai trò tenant. Nhãn tiếng Việt = tên hiển thị cho chủ SME; `key` = tên trong repo. */
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
     không phải giá trị của entries.status (nó quy về 'pending'). Màu lấy trong token AdminKit. */
  var UI = {
    published: { label: 'Đã đăng', c: '#0ab39c', bg: 'rgba(10,179,156,0.10)', icon: 'ri-global-line', pub: true },
    scheduled: { label: 'Đã lên lịch', c: '#3577f1', bg: 'rgba(53,119,241,0.10)', icon: 'ri-calendar-schedule-line' },
    pending: { label: 'Chờ duyệt', c: '#c78729', bg: 'rgba(247,184,75,0.15)', icon: 'ri-inbox-unarchive-line' },
    reviewing: { label: 'Đang phản biện', c: '#299cdb', bg: 'rgba(41,156,219,0.12)', icon: 'ri-chat-check-line', wfOnly: true },
    draft: { label: 'Bản nháp', c: '#495057', bg: 'rgba(33,37,41,0.08)', icon: 'ri-draft-line' },
    private: { label: 'Riêng tư', c: '#405189', bg: 'rgba(64,81,137,0.10)', icon: 'ri-lock-2-line' },
    archived: { label: 'Lưu trữ', c: '#878a99', bg: 'rgba(135,138,153,0.12)', icon: 'ri-archive-line' }
  };

  var WF = {
    'Không duyệt — đăng thẳng': null,
    'Duyệt trước khi đăng': {
      key: 'admin_approval', name: 'Duyệt trước khi đăng',
      states: {
        draft: { label: 'Bản nháp', initial: true },
        pending: { label: 'Chờ duyệt' },
        published: { label: 'Đã đăng', published: true },
        archived: { label: 'Lưu trữ', terminal: true }
      },
      transitions: [
        { key: 'submit', label: 'Gửi duyệt', from: 'draft', to: 'pending', perm: 'entries.submit' },
        { key: 'approve', label: 'Duyệt đăng', from: 'pending', to: 'published', perm: 'entries.approve' },
        { key: 'reject', label: 'Trả lại', from: 'pending', to: 'draft', perm: 'entries.review', note: true },
        { key: 'retract', label: 'Gỡ bài', from: 'published', to: 'draft', perm: 'entries.review', note: true },
        { key: 'archive', label: 'Lưu trữ', from: 'published', to: 'archived', perm: 'entries.approve' }
      ]
    },
    'Quy trình biên tập': {
      key: 'editorial', name: 'Quy trình biên tập',
      states: {
        draft: { label: 'Bản nháp', initial: true },
        pending: { label: 'Chờ biên tập' },
        reviewing: { label: 'Đang phản biện' },
        published: { label: 'Đã đăng', published: true },
        archived: { label: 'Lưu trữ', terminal: true }
      },
      transitions: [
        { key: 'submit', label: 'Gửi biên tập', from: 'draft', to: 'pending', perm: 'entries.submit' },
        { key: 'to_review', label: 'Chuyển phản biện', from: 'pending', to: 'reviewing', perm: 'entries.review' },
        { key: 'approve', label: 'Duyệt đăng', from: 'reviewing', to: 'published', perm: 'entries.approve' },
        { key: 'request_changes', label: 'Yêu cầu sửa', from: 'reviewing', to: 'draft', perm: 'entries.review', note: true },
        { key: 'send_back', label: 'Trả lại tác giả', from: 'pending', to: 'draft', perm: 'entries.review', note: true },
        { key: 'retract', label: 'Gỡ bài', from: 'published', to: 'draft', perm: 'entries.review', note: true },
        { key: 'archive', label: 'Lưu trữ', from: 'published', to: 'archived', perm: 'entries.approve' }
      ]
    }
  };

  /* Tier-1 (không quy trình): mỗi trạng thái cần quyền gì — theo EntryPolicy.
     'archived' không nằm ở đây: chỉ lưu trữ được bài ĐANG đăng (xem archiveTr). */
  var TIER1 = [
    { key: 'draft', label: 'Để nháp — chỉ mình bạn thấy', perm: null, why: '' },
    { key: 'pending', label: 'Gửi duyệt', perm: 'entries.submit', why: 'Vai trò của bạn không được gửi duyệt' },
    { key: 'scheduled', label: 'Hẹn giờ đăng', perm: 'entries.publish', why: 'Bạn chỉ được gửi duyệt, không được hẹn giờ đăng' },
    { key: 'published', label: 'Đăng ngay', perm: 'entries.publish', why: 'Bạn chỉ được gửi duyệt, không được đăng' },
    { key: 'private', label: 'Riêng tư — lưu xong nhưng không hiện ra ngoài', perm: 'entries.publish', why: 'Bạn chỉ được gửi duyệt, không được đổi cách hiển thị' }
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
  /* Lưu trữ ở tier-1: chỉ từ 'published', cùng mức quyền với đăng. */
  function archiveTr(roleLabel, curKey) {
    if (curKey !== 'published') return null;
    return { key: 'archived', label: 'Lưu trữ — gỡ khỏi website, giữ lại nội dung', to: 'archived', allowed: can(roleLabel, 'entries.publish'), why: 'Chỉ vai trò được đăng bài mới lưu trữ được' };
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
        archived: { label: 'Lưu trữ toà soạn', terminal: true }
      },
      transitions: [
        { key: 'submit', label: 'Gửi trưởng ban', from: 'draft', to: 'pending', perm: 'entries.submit' },
        { key: 'to_review', label: 'Chuyển phản biện chuyên môn', from: 'pending', to: 'reviewing', perm: 'entries.review' },
        { key: 'to_copyedit', label: 'Chuyển soát chính tả', from: 'reviewing', to: 'copyedit', perm: 'entries.review' },
        { key: 'approve', label: 'Duyệt đăng', from: 'copyedit', to: 'published', perm: 'entries.approve' },
        { key: 'request_changes', label: 'Yêu cầu sửa lại nội dung', from: 'reviewing', to: 'draft', perm: 'entries.review', note: true },
        { key: 'send_back', label: 'Trả lại phóng viên', from: 'pending', to: 'draft', perm: 'entries.review', note: true },
        { key: 'retract', label: 'Gỡ bài', from: 'published', to: 'draft', perm: 'entries.review', note: true },
        { key: 'archive', label: 'Lưu trữ', from: 'published', to: 'archived', perm: 'entries.approve' }
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
        { key: 'retract', label: 'Gỡ bài', from: 'published', to: 'draft', perm: 'entries.review', note: true }
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

  window.BSCW = {
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
