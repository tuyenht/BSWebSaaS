/* Thư viện khối tái dùng — MỘT nguồn sự thật cho cả hai màn:
   · "Block Library - Options" (nơi dựng và quản lý khối)
   · "Trình đơn" (nơi chọn khối cho bảng lớn — chỉ trỏ tới, không dựng)
   Backend sau này thay hàm nạp: GET /api/page-builder/blocks (?usage=menu để lọc vùng trình đơn).
   Sửa tên vùng / danh sách khối CHỈ sửa ở đây. */
(function () {
  var REGIONS = [
    { k: 'footer',  l: 'Chân trang',           i: 'ri-layout-bottom-line' },
    { k: 'header',  l: 'Đầu trang',            i: 'ri-layout-top-line' },
    { k: 'sidebar', l: 'Cột bên',              i: 'ri-layout-right-line' },
    { k: 'inline',  l: 'Chèn trong trang',     i: 'ri-insert-column-right' },
    { k: 'menu',    l: 'Bảng lớn trình đơn',   i: 'ri-node-tree' }
  ];

  var BLOCKS = [
    { id: 41, key: 'footer', name: 'Chân trang (Footer)', region: 'footer', icon: 'ri-layout-bottom-line', color: '#405189', tint: '#eef2fb', locales: ['VI', 'EN'], updated: '02/07/2026', status: 'published',
      places: ['Trang chủ', 'Giới thiệu', 'Dịch vụ', 'Dự án', 'Tin tức', 'Liên hệ', 'Sản phẩm', 'Tuyển dụng', 'Chính sách bảo mật', 'Điều khoản sử dụng', 'Câu hỏi thường gặp', 'Đối tác'] },
    { id: 42, key: 'top-bar', name: 'Thanh trên cùng', region: 'header', icon: 'ri-layout-top-line', color: '#0ab39c', tint: '#eafaf6', locales: ['VI', 'EN'], updated: '28/06/2026', status: 'published',
      places: ['Trang chủ', 'Giới thiệu', 'Dịch vụ', 'Dự án', 'Tin tức', 'Liên hệ', 'Sản phẩm', 'Tuyển dụng', 'Chính sách bảo mật', 'Điều khoản sử dụng', 'Câu hỏi thường gặp', 'Đối tác'] },
    { id: 43, key: 'widget-cot', name: 'Tiện ích cột bên', region: 'sidebar', icon: 'ri-layout-right-line', color: '#6d28d9', tint: '#f3edfb', locales: ['VI'], updated: '20/06/2026', status: 'published',
      places: ['Blog', 'Tin tức', 'Dịch vụ', 'Dự án', 'Câu hỏi thường gặp'] },
    { id: 44, key: 'cta-khuyen-mai', name: 'CTA Khuyến mãi', region: 'inline', icon: 'ri-megaphone-line', color: '#c78729', tint: '#fdf6e8', locales: ['VI'], updated: '11/07/2026', status: 'draft',
      places: ['Trang chủ', 'Landing khuyến mãi', 'Dịch vụ'] },
    { id: 45, key: 'thong-tin-lien-he', name: 'Thông tin liên hệ', region: 'inline', icon: 'ri-map-pin-line', color: '#3577f1', tint: '#eef4ff', locales: ['VI', 'EN', 'JA'], updated: '09/07/2026', status: 'published',
      places: ['Liên hệ', 'Giới thiệu', 'Footer', 'Trang chủ', 'Dịch vụ', 'Dự án'] },
    /* Vùng "Bảng lớn trình đơn" — đúng danh sách mà Trình đơn đọc khi chọn "Khối tự dựng". */
    { id: 46, key: 'b-services', name: 'Bảng dịch vụ + ảnh minh hoạ', region: 'menu', icon: 'ri-node-tree', color: '#405189', tint: '#eef2fb', locales: ['VI', 'EN'], updated: '24/07/2026', status: 'published',
      places: ['Trình đơn chính › Dịch vụ'] },
    { id: 47, key: 'b-products', name: 'Sản phẩm nổi bật 4 cột', region: 'menu', icon: 'ri-node-tree', color: '#0ab39c', tint: '#eafaf6', locales: ['VI'], updated: '19/07/2026', status: 'published',
      places: ['Trình đơn chính › Sản phẩm'] },
    { id: 48, key: 'b-promo', name: 'Khối khuyến mãi có banner', region: 'menu', icon: 'ri-node-tree', color: '#c78729', tint: '#fdf6e8', locales: ['VI'], updated: '30/07/2026', status: 'draft',
      places: [] }
  ];

  function regionLabel(k) { for (var i = 0; i < REGIONS.length; i++) if (REGIONS[i].k === k) return REGIONS[i].l; return k; }

  window.BSBLOCKS = {
    regions: function () { return REGIONS.slice(); },
    regionLabel: regionLabel,
    all: function () { return BLOCKS.slice(); },
    byRegion: function (k) { return BLOCKS.filter(function (b) { return b.region === k; }); },
    /* Danh sách cho Trình đơn: chỉ vùng "menu". Khối nháp VẪN trả về (kèm status) để
       Trình đơn cảnh báo được, không im lặng giấu đi. */
    menu: function () { return BLOCKS.filter(function (b) { return b.region === 'menu'; }); },
    get: function (key) { for (var i = 0; i < BLOCKS.length; i++) if (BLOCKS[i].key === key) return BLOCKS[i]; return null; },
    places: function (id) { for (var i = 0; i < BLOCKS.length; i++) if (BLOCKS[i].id === id) return (BLOCKS[i].places || []).slice(); return []; }
  };
  try { document.dispatchEvent(new CustomEvent('bs-blocks-ready')); } catch (e) {}
})();
