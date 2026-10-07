/* shell-screens.js — REGISTRY MÀN HÌNH của Admin Shell (D0-4b, 06/10/2026). window.BSSCREENS.
   Một bảng duy nhất: khóa menu (hoặc khóa màn ngoài menu) → tiêu đề, mô tả 1 dòng, breadcrumb, cách hiện, file thiết kế,
   props, embedId, trạng thái, mã lượt phụ trách, câu hỏi Trợ giúp. Shell chỉ ĐỌC bảng này; thêm màn = thêm một dòng.
   kind: shell  = Shell tự vẽ (Tổng quan, Cài đặt)            · mount = dc-import trong khe của Shell (chỉ mount màn đang mở)
         design = đã có thiết kế nhưng chưa nhận `embedded` → thẻ "Đã có thiết kế" + mở file riêng
         (D0-5: mọi màn nội dung đã nối → mount; embedId = khóa của bs-embed-cmd/onDirty; shellSave = Shell vẽ cụm Lưu cho màn)
         pending = thẻ "Chờ thiết kế" chuẩn                   · modal = mở hộp đang có của Shell · later = Để sau (TMĐT)
   status: 'ready' | 'chờ thiết kế' | 'để sau' | 'giữ nguyên' — khớp cột Trạng thái của "SaaS Starter - Bản đồ màn hình.md".
   Hash (prototype): #/<mặt>/<màn>[/<mục>][?k=v] — mặt = site | platform | shop (bản thật: mỗi mặt một tên miền, không có phần mặt).
   params chỉ chứa khóa màn, đường dẫn hoặc mã công khai — không id số. */
(function (g) {
  var HELP_FIND = ['Tìm nhanh một mục?', 'Gõ vào ô tìm kiếm trên cùng, hoặc bấm Ctrl+K.'];
  var HELP_CALL = ['Cần người hướng dẫn?', 'Gọi, nhắn Zalo hoặc gửi email cho bộ phận hỗ trợ ở phần dưới.'];

  /* Màn theo LOẠI NỘI DUNG — dùng chung cho mọi loại (Trang, Tin tức, Dịch vụ, Dự án…): {T} = tên loại, {t} = tên viết thường. */
  var TYPE = {
    /* D1-3: MỘT khuôn danh sách cho mọi loại (kể cả Trang — cây cha-con) → Quản lý Bài viết - Options + prop content-type. Quản lý Trang - Options chỉ để đối chiếu. */
    list: { crumb: ['{T}', 'Danh sách'], title: '{T}', desc: 'Mọi {t} của website: tìm, lọc, đổi trạng thái và sửa ngay tại dòng.', kind: 'mount', dc: 'Quản lý Bài viết - Options', embedId: 'content', start: 'list', status: 'ready', turn: 'D1-3', mapRow: '8–9', props: ['embedded', 'embedId', 'onDirty', 'contentType', 'start', 'slug', 'role', 'workflow', 'langCodes', 'planKey', 'upsell'],
      purpose: 'Xem và quản lý mọi {t}: tìm, lọc theo trạng thái, đổi trạng thái tại dòng, chuyển vào Thùng rác.', data: 'Tiêu đề · đường dẫn · trạng thái · bản ngôn ngữ · người tạo · ngày sửa.',
      help: [['Đổi trạng thái một bài thế nào?', 'Bấm vào chip trạng thái ở dòng đó rồi chọn bước tiếp theo — chỉ hiện bước bạn được phép.'], ['Lỡ xóa nhầm?', 'Bài vào Thùng rác. Bấm "Hoàn tác" trên thông báo, hoặc mở tab Thùng rác để khôi phục.'], HELP_FIND] },
    /* D1-4b: MỘT khuôn soạn cho mọi loại (Trang · Tin tức · Dịch vụ · Dự án …) → Page Editor - Options + prop kind (= khóa loại). params: { ma } (mã công khai kiểu ulid), không id số. */
    create: { crumb: ['{T}', 'Thêm mới'], title: 'Thêm {t}', desc: 'Viết {t} mới: nội dung, ảnh, đường dẫn và cách hiện trên Google.', kind: 'mount', dc: 'Page Editor - Options', embedId: 'page-editor', start: 'new', status: 'ready', turn: 'D1-4b', mapRow: '10–11', props: ['embedded', 'embedId', 'onDirty', 'kind', 'start', 'ma', 'parent', 'lang', 'role', 'workflow', 'langCodes', 'planKey', 'upsell'],
      purpose: 'Soạn {t} mới, lưu nháp tự động, gửi duyệt hoặc đăng tùy nhóm quyền.', data: 'Tiêu đề · nội dung · ảnh đại diện · chuyên mục · đường dẫn · mô tả Google · bản ngôn ngữ.',
      help: [['Bài có tự lưu không?', 'Có. Bản nháp tự lưu sau vài giây; dòng "Đã lưu" ở đầu trình soạn cho biết lần lưu gần nhất.'], ['Vì sao tôi không thấy nút Đăng?', 'Nhóm quyền của bạn chỉ được gửi duyệt. Người duyệt sẽ đăng giúp bạn.'], HELP_CALL] },
    edit: { crumb: ['{T}', 'Sửa'], title: 'Sửa {t}', desc: 'Sửa nội dung, ảnh, đường dẫn và cách hiện trên Google — mở từ dòng trong danh sách.', kind: 'mount', dc: 'Page Editor - Options', embedId: 'page-editor', start: 'edit', notInMenu: true, status: 'ready', turn: 'D1-4b', mapRow: '10–11', props: ['embedded', 'embedId', 'onDirty', 'kind', 'start', 'ma', 'lang', 'role', 'workflow', 'langCodes', 'planKey', 'upsell'],
      help: [['Thay đổi khi nào hiện trên website?', 'Bài đang đăng: ngay khi bấm Đăng / Cập nhật. Bài chờ duyệt: khi người duyệt bấm Duyệt đăng.'], ['Đổi đường dẫn có làm hỏng liên kết cũ không?', 'Không — hệ thống tự chuyển khách từ địa chỉ cũ sang địa chỉ mới.'], HELP_CALL] },
    cats: { crumb: ['{T}', 'Chuyên mục'], title: 'Chuyên mục {t}', desc: 'Nhóm {t} theo chủ đề để khách tìm dễ hơn và đường dẫn gọn hơn.', kind: 'mount', dc: 'Chuyên mục', embedId: 'taxonomy', repoKey: '{type}.taxonomy', props: ['embedded', 'embedId', 'onDirty', 'contentType', 'role', 'langCodes', 'upsell'], status: 'ready', turn: 'D1-1', mapRow: '15',
      purpose: 'Tạo, sắp xếp và đặt tên chuyên mục cho {t}, kể cả chuyên mục con.', data: 'Tên chuyên mục · đường dẫn · chuyên mục cha · số bài · bản ngôn ngữ.',
      help: [['Chuyên mục dùng để làm gì?', 'Gom bài cùng chủ đề vào một trang danh sách trên website.'], HELP_FIND, HELP_CALL] }
  };

  var L = {
    /* ---- Mặt Website ---- */
    /* D2-5: Tổng quan theo QUYỀN (BSCW.can) — Tổng quan.dc.html; màn tự vẽ h1 + nút chính (ownHeader). */
    dashboard: { plane: 'site', title: 'Tổng quan', desc: 'Tình hình website hôm nay: việc cần làm, số liệu, bài chờ duyệt và bài của bạn.', kind: 'mount', dc: 'Tổng quan', embedId: 'dashboard', ownHeader: true, props: ['embedded', 'embedId', 'onDirty', 'role', 'workflow', 'planKey', 'upsell', 'moduleOff', 'freshSite', 'showNotes', 'bigText', 'siteName'], status: 'ready', turn: 'D2-5', mapRow: '4', menu: true,
      purpose: 'Bảng điều khiển theo quyền: Việc cần làm hôm nay, Bắt đầu (website mới), thẻ số liệu, lượt xem, Duyệt bài, Bài của tôi, nội dung gần đây, hoạt động, Làm nhanh, Cần giúp.', data: 'Bộ đếm theo website (trang · bài · sản phẩm · khách liên hệ 7 ngày) · dung lượng đã dùng · lượt xem theo ngày · hàng chờ duyệt · bài bị trả lại · trạng thái Bắt đầu.',
      help: [['Màn này cho biết gì?', 'Việc đang chờ bạn và tình hình website hôm nay. Mỗi người thấy phần đúng với việc của mình.'], ['Bắt đầu từ đâu?', 'Làm từ trên xuống: "Việc cần làm hôm nay" trước, rồi bấm "Viết bài mới" ở đầu trang.'], HELP_FIND] },
    /* D1-5: Sản phẩm là bảng riêng (products) — không quy trình duyệt, không Hẹn giờ đăng, không người tạo. Khóa repo: catalog.list · catalog.create; catalog.edit mở bằng params:{ma:<ulid>}. */
    'catalog.list': { crumb: ['Sản phẩm', 'Danh sách'], crumbGo: 'catalog.list', plane: 'site', title: 'Sản phẩm', desc: 'Mọi sản phẩm của website: tìm theo tên hoặc mã SKU, đổi trạng thái, giá và chuyên mục ngay tại dòng.', kind: 'mount', dc: 'Sản phẩm - Danh sách', embedId: 'catalog-list', repoKey: 'catalog.list', props: ['embedded', 'embedId', 'onDirty', 'role', 'langCodes', 'upsell', 'moduleOn', 'mode'], status: 'ready', turn: 'D1-5', mapRow: '20–21', menu: true,
      purpose: 'Danh sách sản phẩm theo khuôn danh sách dùng chung, thêm cột ảnh, SKU, giá, tồn kho, nhãn Nổi bật / Bán chạy.', data: 'Ảnh · tên · mã SKU · chuyên mục chính · giá · tồn kho · nhãn · trạng thái · bản ngôn ngữ · ngày cập nhật.',
      help: [['Thêm sản phẩm thế nào?', 'Bấm "Thêm sản phẩm" ở đầu trang — chỉ cần tên, ảnh và giá.'], ['Lỡ xóa nhầm?', 'Sản phẩm vào Thùng rác. Bấm "Hoàn tác" trên thông báo, hoặc mở tab Thùng rác để khôi phục.'], HELP_FIND] },
    'catalog.create': { crumb: ['Sản phẩm', 'Thêm mới'], crumbGo: 'catalog.list', plane: 'site', title: 'Thêm sản phẩm', desc: 'Nhập tên, ảnh, giá và mô tả — phần còn lại nằm trong "Thêm tùy chọn".', kind: 'mount', dc: 'Sản phẩm - Trình sửa', embedId: 'catalog', start: 'new', repoKey: 'catalog.create', props: ['embedded', 'embedId', 'onDirty', 'start', 'ma', 'lang', 'role', 'langCodes', 'planKey', 'upsell', 'moduleOn', 'mode'], status: 'ready', turn: 'D1-5', mapRow: '20–21', menu: true,
      purpose: 'Trình sửa sản phẩm: ảnh, tên, giá, mô tả; thêm tùy chọn: đường dẫn, tóm tắt, SKU, tồn kho, khuyến mãi, nhãn, chuyên mục, Google.', data: 'Tên · ảnh · giá · giá khuyến mãi · tồn kho · mô tả · chuyên mục · đường dẫn · mô tả Google · bản ngôn ngữ.', help: [['Sản phẩm có tự lưu không?', 'Bản nháp tự lưu sau 3 giây. Sản phẩm đang đăng thì bấm "Cập nhật" để khách thấy thay đổi.'], HELP_CALL] },
    'catalog.edit': { crumb: ['Sản phẩm', 'Sửa'], crumbGo: 'catalog.list', plane: 'site', title: 'Sửa sản phẩm', desc: 'Sửa ảnh, giá, mô tả và cách hiện trên Google — mở từ dòng trong danh sách.', kind: 'mount', dc: 'Sản phẩm - Trình sửa', embedId: 'catalog', start: 'edit', activeAs: 'catalog.list', props: ['embedded', 'embedId', 'onDirty', 'start', 'ma', 'lang', 'role', 'langCodes', 'planKey', 'upsell', 'moduleOn', 'mode'], status: 'ready', turn: 'D1-5', mapRow: '20–21',
      help: [['Thay đổi khi nào hiện trên website?', 'Sản phẩm đang đăng: ngay khi bấm Cập nhật. Bản nháp: khi bấm Đăng.'], ['Đổi đường dẫn có làm hỏng liên kết cũ không?', 'Không — hệ thống tự chuyển khách từ địa chỉ cũ sang địa chỉ mới.'], HELP_CALL] },
    'catalog.cats': { crumb: ['Sản phẩm', 'Chuyên mục'], crumbGo: 'catalog.list', plane: 'site', title: 'Chuyên mục sản phẩm', desc: 'Nhóm sản phẩm theo dòng hàng để khách tìm dễ hơn.', kind: 'mount', dc: 'Chuyên mục', embedId: 'taxonomy', repoKey: 'catalog.taxonomy', contentType: 'products', props: ['embedded', 'embedId', 'onDirty', 'contentType', 'role', 'langCodes', 'upsell'], status: 'ready', turn: 'D1-1', mapRow: '15', menu: true,
      purpose: 'Chuyên mục cho sản phẩm, dùng chung khuôn với chuyên mục bài viết.', data: 'Tên · đường dẫn · chuyên mục cha · số sản phẩm.', help: [HELP_FIND, HELP_CALL] },
    review: { plane: 'site', title: 'Hộp duyệt', desc: 'Bài đang chờ bạn đọc và quyết định: duyệt đăng, trả lại hoặc chuyển bước.', kind: 'mount', dc: 'Hộp duyệt', embedId: 'review', ownHeader: true, status: 'ready', turn: 'D0-6a', mapRow: '18–19', menu: true,
      help: [['Duyệt một bài thế nào?', 'Chọn bài ở cột trái, đọc ở khung phải rồi bấm nút bước tiếp theo.'], ['Trả lại có cần ghi lý do?', 'Có — người viết sẽ thấy lý do ngay trong bài.'], HELP_CALL] },
    media: { plane: 'site', title: 'Thư viện ảnh & tệp', desc: 'Mọi ảnh và tệp đã tải lên — dùng lại cho nhiều bài mà không phải tải lại.', kind: 'mount', dc: 'Thư viện ảnh & tệp', embedId: 'media', repoKey: 'media', props: ['embedded', 'embedId', 'onDirty', 'role', 'langCodes', 'upsell'], status: 'ready', turn: 'D1-6', mapRow: '22', menu: true,
      purpose: 'Trang thư viện: tải lên, tìm, lọc theo loại, sửa mô tả ảnh, chuyển vào Thùng rác.', data: 'Ảnh thu nhỏ · tên tệp · cỡ · nơi đang dùng · mô tả ảnh · dung lượng còn lại của gói.',
      help: [['Tải ảnh lên thế nào?', 'Bấm "Tải ảnh lên", hoặc kéo thả tệp từ máy vào trang. Nhận JPG, PNG, WebP, GIF, PDF — tối đa 10 MB mỗi tệp.'], ['Lỡ xóa nhầm?', 'Tệp nằm trong Thùng rác 15 ngày. Mở Thùng rác ở cột Thư mục rồi bấm Khôi phục.'], HELP_CALL] },
    /* D1-7: Hộp hai khung (mẫu 5). Mở một liên hệ: params:{ma:<ulid>}; lọc theo biểu mẫu: params:{form:<ulid biểu mẫu>} — không id số. */
    contacts: { plane: 'site', title: 'Khách liên hệ', desc: 'Yêu cầu khách gửi từ biểu mẫu trên website — gọi, nhắn Zalo hoặc gửi email ngay tại đây.', kind: 'mount', dc: 'Khách liên hệ', embedId: 'contacts', repoKey: 'contacts', props: ['embedded', 'embedId', 'onDirty', 'role', 'langCodes', 'upsell', 'ma', 'formMa'], status: 'ready', turn: 'D1-7', mapRow: '24–25', menu: true,
      purpose: 'Hộp hai khung: danh sách (tìm, lọc biểu mẫu / trạng thái / khoảng ngày, chọn nhiều, "Tải thêm") và chi tiết (Gọi · Zalo · Email, đổi trạng thái, đồng ý của khách).', data: 'Tên khách · điện thoại · email · biểu mẫu · trang gửi · nội dung · thời gian · trạng thái · câu đồng ý đã tick.',
      help: [['Gọi lại khách thế nào?', 'Mở liên hệ rồi bấm "Gọi" — trên điện thoại nút Gọi luôn nằm ở đáy màn hình.'], ['Thư rác là gì?', 'Liên hệ bộ lọc tự động hoặc bạn đánh dấu là quảng cáo, lừa đảo. Thư rác không gửi thư báo và tự dọn sau 30 ngày.'], HELP_CALL] },
    /* D1-7: khóa đề xuất — repo chưa có mục menu Biểu mẫu (Chờ backend). Mở trình sửa: params:{ma:<ulid>}. */
    forms: { plane: 'site', title: 'Biểu mẫu', desc: 'Biểu mẫu khách điền trên website: liên hệ, báo giá, đặt lịch.', kind: 'mount', dc: 'Biểu mẫu', embedId: 'forms', repoKey: 'forms (đề xuất)', props: ['embedded', 'embedId', 'onDirty', 'role', 'langCodes', 'upsell', 'ma'], status: 'ready', turn: 'D1-7', mapRow: '26', menu: true, pend: true,
      purpose: 'Danh sách biểu mẫu (bật/tắt cạnh tên, số liên hệ, email nhận) + trình sửa: 6 loại ô, kéo-thả hoặc Lên / Xuống, ô đồng ý khóa ở cuối, lời cảm ơn theo ngôn ngữ, email nhận thư báo, xem trước.', data: 'Tên · trạng thái · các ô (nhãn, loại, bắt buộc, lựa chọn, thứ tự) · lời cảm ơn · email nhận thư báo · số liên hệ.',
      help: [['Thêm ô cho biểu mẫu thế nào?', 'Mở biểu mẫu, bấm "Thêm ô" rồi chọn loại. Bấm "Lưu — hiệu lực ngay" để khách thấy.'], ['Vì sao không xóa được ô đồng ý?', 'Luật bảo vệ dữ liệu cá nhân bắt buộc khách đồng ý trước khi gửi. Câu chữ do hệ thống cập nhật.'], HELP_CALL] },
    menus: { plane: 'site', title: 'Trình đơn', desc: 'Sắp xếp liên kết ở đầu và chân website.', kind: 'mount', dc: 'Trình đơn', embedId: 'menus', shellSave: true, keepAlive: true, status: 'ready', turn: 'D1-2', mapRow: '16', menu: true,
      help: [['Trình đơn là gì?', 'Danh sách liên kết ở đầu và chân website, ví dụ Trang chủ, Giới thiệu, Liên hệ.'], ['Khi nào khách thấy thay đổi?', 'Ngay sau khi bạn bấm Lưu.'], ['Lỡ xóa nhầm một mục?', 'Bấm "Bỏ thay đổi" trước khi lưu để lấy lại.']] },
    /* D2-1: lọc theo nhóm bằng params:{nhom:<tên nhóm>} — bảng roles không có mã công khai; không id số. */
    users: { plane: 'site', title: 'Thành viên', desc: 'Mời đồng nghiệp cùng quản trị website và chọn nhóm quyền cho từng người.', kind: 'mount', dc: 'Thành viên', embedId: 'users', repoKey: 'users', props: ['embedded', 'embedId', 'onDirty', 'role', 'planKey', 'upsell', 'impersonating', 'nhom', 'tab'], status: 'ready', turn: 'D2-1', mapRow: '27–31', menu: true,
      /* D2-2: tab Lời mời trong cùng màn — mở bằng params:{tab:'loi-moi'} (mặc định 'thanh-vien'). Không có khóa màn riêng. */
      tabs: { 'thanh-vien': { title: 'Thành viên', turn: 'D2-1' }, 'loi-moi': { title: 'Lời mời', turn: 'D2-2', status: 'ready', mapRow: '31', data: 'Email · nhóm quyền · người mời · gửi lúc · hết hạn (bảng invitations + invitation_roles); Đang chờ · Hết hạn · Đã hủy · Đã nhận.' } },
      purpose: 'Danh sách thành viên + lời mời đang chờ (Mẫu 1): tìm, lọc nhóm, Dòng/trang, sửa nhóm từng người hoặc nhiều người, xóa khỏi website.', data: 'Tên · email · các nhóm quyền · trạng thái (đang hoạt động / chờ nhận lời mời / hết hạn).',
      help: [['Đổi nhóm cho một người thế nào?', 'Bấm "Sửa nhóm" ở dòng người đó, tick các nhóm rồi bấm Lưu — áp dụng ngay.'], ['Xóa khỏi website có mất bài không?', 'Không. Người đó chỉ không đăng nhập được nữa; bài đã viết giữ nguyên. Muốn cho vào lại thì mời lại.'], ['Người được mời chưa thấy thư?', 'Nhắc họ xem cả mục Thư rác. Bấm "Gửi lại" ở tab Lời mời — liên kết cũ sẽ hết hiệu lực. Mỗi địa chỉ nhận tối đa 3 thư trong 24 giờ.'], HELP_CALL] },
    /* D2-1: mở một nhóm bằng params:{nhom:<tên>, che:'sua'|'xem'|'moi'}. */
    roles: { plane: 'site', title: 'Nhóm quyền', desc: 'Xem mỗi nhóm được làm gì; tạo nhóm riêng khi gói cho phép.', kind: 'mount', dc: 'Nhóm quyền', embedId: 'roles', repoKey: 'roles', props: ['embedded', 'embedId', 'onDirty', 'role', 'planKey', 'upsell', 'impersonating', 'nhom', 'che'], status: 'ready', turn: 'D2-1', mapRow: '27–30', menu: true, pend: true, feature: 'rbac.custom_roles',
      purpose: '6 nhóm hệ thống (khóa, chỉ xem) + nhóm tùy chỉnh khi gói có "Nhóm quyền tùy chỉnh"; tạo / sửa bằng bảng việc theo khu vực, "Nhóm này làm được gì?".', data: 'Tên nhóm · tạo từ · vị trí cấp · số thành viên · việc được làm theo từng khu vực.',
      help: [['Khi nào cần nhóm riêng?', 'Khi 6 nhóm có sẵn không vừa, ví dụ người chỉ duyệt bài mà không sửa bài.'], ['Vì sao một ô có ổ khóa?', 'Bấm vào ô đó để xem lý do — thường là việc bạn không có hoặc ngoài gói.'], HELP_CALL] },
    activity: { plane: 'site', title: 'Nhật ký hoạt động', desc: 'Ai đã làm gì trên website, lúc nào.', kind: 'pending', status: 'chờ thiết kế', turn: 'D4-3', mapRow: '61', menu: true, pend: true,
      purpose: 'Dòng thời gian thao tác của thành viên, lọc theo người và loại việc ("Tải thêm").', data: 'Thời gian · người làm · việc · đối tượng · từ đâu.', help: [HELP_FIND, HELP_CALL] },
    settings: { plane: 'site', title: 'Cài đặt', desc: 'Thông tin, giao diện, ngôn ngữ và kết nối của website.', kind: 'shell', status: 'ready', turn: 'D0-4a', mapRow: '32', menu: true,
      help: [['Thay đổi có hiệu lực khi nào?', 'Ngay khi bạn bấm Lưu ở thanh dưới cùng. Chưa bấm Lưu thì website vẫn như cũ.'], ['Lỡ sửa sai thì sao?', 'Bấm "Bỏ thay đổi" để quay về bản đã lưu gần nhất.'], ['Ô bị báo lỗi ở đâu?', 'Bấm "Xem lỗi đầu tiên" — trang đưa bạn tới đúng ô cần sửa và nói cách sửa.']] },

    /* ---- Mặt Nền tảng ---- */
    'p-dashboard': { plane: 'platform', title: 'Tổng quan', desc: 'Tình hình cả nền tảng: khách hàng mới, website cần chú ý, việc của đội vận hành.', kind: 'pending', status: 'chờ thiết kế', turn: 'D4-1', mapRow: '5', menu: true, pend: true,
      purpose: 'Bảng điều khiển của nhân viên nền tảng, khác theo nhóm quyền (hỗ trợ / thanh toán).', data: 'Khách hàng mới · website lỗi tên miền · gói sắp hết hạn · yêu cầu hỗ trợ.', help: [HELP_FIND, HELP_CALL] },
    'p-tenants': { plane: 'platform', title: 'Khách hàng', desc: 'Mọi khách hàng của nền tảng và các website của họ.', kind: 'pending', status: 'chờ thiết kế', turn: 'D4-1', mapRow: '50–51', menu: true,
      purpose: 'Danh sách không giới hạn ("‹ Trước · Sau ›"), xem chi tiết khách, gói đang dùng.', data: 'Tên khách · người liên hệ · gói · số website · ngày tham gia · trạng thái.', help: [HELP_FIND, HELP_CALL] },
    'p-sites': { plane: 'platform', title: 'Website', desc: 'Mọi website trên nền tảng: tên miền, gói, tình trạng hoạt động.', kind: 'pending', status: 'chờ thiết kế', turn: 'D4-1', mapRow: '50–51 · 46', menu: true,
      purpose: 'Danh sách website + trang chi tiết (tab Quy trình duyệt đã có ở "Quy trình duyệt.dc.html") + Tạo website.', data: 'Tên website · tên miền · khách hàng · loại website · gói · trạng thái.', help: [HELP_FIND, HELP_CALL], goWorkflow: 'p-workflow' },
    /* D0-5: Quy trình duyệt nằm ở mặt Nền tảng (owner 30/09) — mở từ thẻ Website; D4-1 đặt vào tab của trang chi tiết website. KHÔNG nằm trong Cài đặt website. */
    'p-workflow': { crumb: ['Website', 'Quy trình duyệt'], crumbGo: 'p-sites', plane: 'platform', title: 'Quy trình duyệt', desc: 'Chọn loại nội dung nào của website phải qua bước duyệt trước khi đăng, và theo quy trình nào.', kind: 'mount', dc: 'Quy trình duyệt', embedId: 'workflow', shellSave: true, status: 'ready', turn: 'D0-5 · D4-1', mapRow: '46',
      help: [['Bật duyệt cho một loại nội dung thế nào?', 'Bật công tắc ở dòng loại đó rồi chọn quy trình. Bấm Lưu — áp dụng ngay cho bài gửi sau đó.'], ['Tắt duyệt thì bài đang chờ ra sao?', 'Bài đang chờ giữ nguyên nội dung; hộp xác nhận nói rõ số bài sẽ được xử lý.'], HELP_CALL] },
    'p-plans': { plane: 'platform', title: 'Gói & tính năng', desc: 'Số website, dung lượng và tính năng mở theo từng gói.', kind: 'shell', status: 'chờ thiết kế', turn: 'D4-2', mapRow: '52', menu: true, pend: true,
      help: [['Đổi gói ảnh hưởng gì?', 'Website đang dùng giữ nguyên phần đã có; giới hạn mới chỉ áp khi bật thêm.'], HELP_FIND, HELP_CALL] },
    'p-sitetypes': { plane: 'platform', title: 'Loại website', desc: 'Khuôn sẵn cho từng loại website: module, nhóm quyền và giao diện mặc định.', kind: 'pending', status: 'chờ thiết kế', turn: 'D4-2', mapRow: '53', menu: true, pend: true,
      purpose: 'Danh sách loại website (doanh nghiệp, cửa hàng…) và module/nhóm quyền đi kèm.', data: 'Tên loại · module bật sẵn · nhóm quyền mẫu · giao diện · số website đang dùng.', help: [HELP_FIND, HELP_CALL] },
    'p-staff': { plane: 'platform', title: 'Nhân sự vận hành', desc: 'Nhân viên của nền tảng và nhóm quyền của từng người.', kind: 'pending', status: 'chờ thiết kế', turn: 'D4-3', mapRow: '54', menu: true, pend: true,
      purpose: 'Danh sách nhân viên nền tảng (Quản trị nền tảng · Nhân viên hỗ trợ · Nhân viên thanh toán).', data: 'Tên · email · nhóm quyền · lần đăng nhập gần nhất.', help: [HELP_FIND, HELP_CALL] },
    'p-audit': { plane: 'platform', title: 'Nhật ký hệ thống', desc: 'Mọi thao tác quan trọng trên nền tảng, kể cả các lần đăng nhập hỗ trợ.', kind: 'pending', status: 'chờ thiết kế', turn: 'D4-3', mapRow: '55', menu: true,
      purpose: 'Dòng thời gian không giới hạn, lọc theo khách hàng, người làm và loại việc.', data: 'Thời gian · người làm · việc · khách hàng/website · địa chỉ truy cập.', help: [HELP_FIND, HELP_CALL] },
    'p-impersonate': { plane: 'platform', title: 'Đăng nhập hỗ trợ', desc: 'Danh sách các lần nhân viên đăng nhập dưới tên khách hàng để hỗ trợ.', kind: 'pending', status: 'chờ thiết kế', turn: 'D4-3', mapRow: '56', menu: true, pend: true,
      purpose: 'Ai đã đăng nhập dưới tên khách hàng nào, lúc nào, bao lâu, lý do.', data: 'Nhân viên · khách hàng · website · bắt đầu · thời lượng · lý do.', help: [HELP_FIND, HELP_CALL] },
    'p-settings': { plane: 'platform', title: 'Cài đặt nền tảng', desc: 'Thông tin, thương hiệu trắng, email hệ thống và bảo mật của cả nền tảng.', kind: 'shell', status: 'ready', turn: 'D4-3', mapRow: '57', menu: true,
      help: [['Thay đổi có hiệu lực khi nào?', 'Ngay khi bạn bấm Lưu ở thanh dưới cùng — áp cho mọi khách hàng.'], HELP_FIND, HELP_CALL] },

    blocks: { plane: 'site', title: 'Khối tái dùng', desc: 'Khối nội dung dựng sẵn dùng lại ở trang và trình đơn.', kind: 'later', status: 'để sau', turn: 'track Builder v3', mapRow: '⏸',
      purpose: 'Khối tái dùng thuộc track Builder v3 — Trình đơn mở tới đây khi chèn khối.', help: [HELP_CALL] },

    /* ---- Màn không nằm trong menu (dùng chung 2 mặt) ---- */
    account: { plane: '*', title: 'Tài khoản của tôi', desc: 'Hồ sơ, mật khẩu, xác thực 2 lớp, thiết bị đang đăng nhập và tùy chọn giao diện của riêng bạn.', kind: 'mount', dc: 'Tài khoản của tôi', embedId: 'account', props: ['embedded', 'embedId', 'onDirty', 'plane', 'twofa', 'impersonating', 'role', 'startTab', 'scheme', 'fontSize', 'motion', 'contrast', 'uiLang', 'onPref'], status: 'ready', turn: 'D2-3', mapRow: '6',
      purpose: 'Trang riêng 5 mục (Hồ sơ · Bảo mật · Phiên đăng nhập · Tài khoản liên kết · Tùy chọn) thay hộp Tài khoản cũ; mở từ menu tài khoản ở cả 2 mặt. Mở thẳng một mục: params.tab.', data: 'Họ tên · chức danh · điện thoại · email · mật khẩu · xác thực 2 lớp · mã khôi phục · phiên đăng nhập · tùy chọn giao diện.',
      help: [['Quên mật khẩu hiện tại?', 'Đăng xuất rồi bấm "Quên mật khẩu" ở trang đăng nhập — thư đặt lại gửi tới email của bạn.'], ['Mất điện thoại có ứng dụng xác thực?', 'Đăng nhập bằng một mã khôi phục, rồi bật lại xác thực 2 lớp trên điện thoại mới.'], HELP_CALL] },
    /* D2-4: dòng thời gian thông báo của website đang mở (site_notifications, mã công khai ulid). Mở thẳng một tab: params:{tab:'chua-doc'|'duyet-bai'|'khach-lien-he'|'bao-mat'|'he-thong'}. */
    notifications: { plane: '*', title: 'Thông báo', desc: 'Việc cần bạn chú ý trên website này: bài chờ duyệt, khách liên hệ, cảnh báo bảo mật và tên miền.', kind: 'mount', dc: 'Thông báo', embedId: 'notifications', repoKey: 'notifications', props: ['embedded', 'embedId', 'onDirty', 'plane', 'role', 'workflow', 'impersonating', 'tab', 'showNotes'], status: 'ready', turn: 'D2-4', mapRow: '7',
      purpose: 'Dòng thời gian ("Tải thêm"), tab Tất cả · Chưa đọc · Duyệt bài · Khách liên hệ · Bảo mật · Hệ thống, ô tìm, đánh dấu đã đọc 1 / tất cả; chuông trên thanh trên cùng đọc cùng nguồn.', data: 'Loại · câu thông báo · thời gian · đã đọc / chưa · việc cần làm (mở màn liên quan).',
      help: [['Thông báo giữ bao lâu?', '90 ngày, sau đó tự xóa. Việc còn dở (bài chờ duyệt, khách chưa gọi lại) vẫn nằm ở màn của nó.'], ['Tắt bớt thông báo thế nào?', 'Mở Tài khoản của tôi › Tùy chọn › Thông báo. Cảnh báo bảo mật luôn bật.'], HELP_CALL] },
    search: { plane: '*', title: 'Kết quả tìm kiếm', desc: 'Mọi kết quả khớp từ khóa: trang, bài viết, sản phẩm, cài đặt.', kind: 'pending', status: 'chờ thiết kế', turn: 'D5-1', mapRow: '60',
      purpose: 'Kết quả nhóm theo loại, lọc theo loại, mở thẳng mục khớp.', data: 'Tiêu đề · loại · trạng thái · đoạn khớp.', help: [HELP_FIND] },
    impersonate: { plane: '*', title: 'Đăng nhập dưới tên khách hàng', desc: 'Hộp xác nhận + thanh đầu trang khi nhân viên hỗ trợ vào admin của khách.', kind: 'pending', status: 'chờ thiết kế', turn: 'D4-3', mapRow: '56',
      purpose: 'Hộp xác nhận (lý do, thời hạn phiên) mở từ màn Website; Shell đã có khe thanh "Đang đăng nhập với tên …".', data: 'Thành viên · khách hàng · nhóm quyền · thời hạn còn lại.', help: [HELP_CALL] },
    'ui-standard': { plane: '*', title: 'Chuẩn giao diện SaaS Starter', desc: 'Trang chuẩn: component, câu chữ, mẫu trang dùng cho mọi màn.', kind: 'mount', dc: 'Chuẩn giao diện SaaS Starter', ownHeader: true, status: 'ready', turn: 'D0-2 · D0-3', mapRow: '—',
      help: [['Trang này để làm gì?', 'Mọi màn mới chỉ dùng component và câu chữ ở đây.'], HELP_FIND] }
  };

  /* Khóa ngoài menu — mở được ở mọi mặt. */
  var GLOBAL = ['account', 'notifications', 'search', 'impersonate', 'ui-standard'];

  function fill(s, T) { return String(s || '').replace(/\{T\}/g, T).replace(/\{t\}/g, T.toLowerCase()); }
  /* resolve('posts.list', {posts:'Tin tức'}) → bản ghi đầy đủ; khóa lạ → null. types = {khóa loại: nhãn} do Shell truyền (đọc từ content_sections). */
  function resolve(key, types) {
    if (!key) return null;
    if (L[key]) return Object.assign({ key: key }, L[key]);
    var m = /^([a-z0-9_-]+)\.(list|create|edit|cats)$/.exec(key);
    if (m && types && types[m[1]]) {
      var t = TYPE[m[2]], T = types[m[1]];
      var r = { key: key, plane: 'site', menu: true, type: m[1] };
      Object.keys(t).forEach(function (k) { r[k] = (typeof t[k] === 'string') ? fill(t[k], T) : t[k]; });
      r.crumb = t.crumb.map(function (x) { return fill(x, T); }); r.crumbGo = m[1] + '.list';
      if (m[1] === 'pages' && t.dcPages) { r.dc = t.dcPages; if (t.embedIdPages) r.embedId = t.embedIdPages; }
      if (t.notInMenu) { r.menu = false; r.activeAs = m[1] + '.list'; }
      delete r.dcPages; delete r.embedIdPages; delete r.notInMenu;
      r.help = t.help.map(function (h) { return [fill(h[0], T), fill(h[1], T)]; });
      return r;
    }
    return null;
  }
  var PLANES = { site: 'tenant', platform: 'platform', shop: 'shop' };
  var PLANE_SLUG = { tenant: 'site', platform: 'platform', shop: 'shop' };
  /* Hash mới #/<mặt>/<màn>[/<mục>][?k=v] và 4 dạng cũ: #<khóa menu> · #settings/<mục> · #p-<khóa> · #p-settings/<mục>. */
  function parse(h) {
    h = String(h || '').replace(/^#/, ''); if (!h) return null;
    var q = {}, qi = h.indexOf('?');
    if (qi >= 0) { h.slice(qi + 1).split('&').forEach(function (kv) { if (!kv) return; var p = kv.split('='); try { q[decodeURIComponent(p[0])] = decodeURIComponent(p[1] || ''); } catch (e) {} }); h = h.slice(0, qi); }
    var parts = h.split('/'), out;
    if (parts[0] === '' && parts.length >= 3) {
      var plane = PLANES[parts[1]]; if (!plane) return { bad: true };
      var scr = parts[2] || '', sub = parts[3] || '';
      if (plane === 'platform' && GLOBAL.indexOf(scr) < 0) { scr = 'p-' + scr; if (sub) sub = 'p-' + sub; }
      out = { plane: plane, screen: scr, section: sub, params: q, form: 'new' };
    } else {
      var k = parts[0];
      out = { plane: k.indexOf('p-') === 0 ? 'platform' : null, screen: k, section: parts[1] || '', params: q, form: 'old' };
    }
    return out;
  }
  function build(plane, key, section, params) {
    var slug = PLANE_SLUG[plane] || 'site', scr = key, sub = section || '';
    if (plane === 'platform') { scr = String(scr).replace(/^p-/, ''); sub = String(sub).replace(/^p-/, ''); }
    var qs = Object.keys(params || {}).filter(function (k) { return params[k] != null && params[k] !== ''; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
    return '/' + slug + '/' + scr + (sub ? '/' + sub : '') + (qs ? '?' + qs : '');
  }
  function all() { return Object.keys(L).map(function (k) { return Object.assign({ key: k }, L[k]); }); }

  g.BSSCREENS = { LIST: L, TYPE: TYPE, GLOBAL: GLOBAL, resolve: resolve, parse: parse, build: build, all: all };
  try { document.dispatchEvent(new CustomEvent('bs-screens-ready')); } catch (e) {}
})(window);
