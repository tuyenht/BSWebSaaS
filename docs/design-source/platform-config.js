/* BSWebSaaS — nguồn DUY NHẤT cho thương hiệu nền tảng (white-label).
   Mọi câu chữ, logo, alt, tên miền mẫu nhắc tới nền tảng đều đọc từ window.BSPLAT — không gắn cứng.
   Backend cấp giá trị thật qua BSPLAT.set({...}) (Cài đặt nền tảng › Thông tin + Thương hiệu trắng).
   Giá trị dưới đây là MẪU cho prototype. */
(function (g) {
  var C = {
    name: 'Bảo Sơn',                 // Tên nền tảng — hiện ở đăng nhập, email hệ thống, hộp thoại
    kit: 'SaaS Starter',             // Tên bộ khung quản trị
    version: '1.0',
    rootDomain: 'baoson.net',        // Tên miền gốc: website khách mặc định ở dạng <ten>.rootDomain
    logo: { light: 'assets/logo-light.png', dark: 'assets/logo-dark.png', sm: 'assets/logo-sm.png' },
    builtBy: { show: true, name: 'Bảo Sơn', url: '#' },   // Dòng "Phát triển bởi" — tắt được ở Thương hiệu trắng (p-branding)
    support: { phone: '1900 1234', zalo: '0901 234 567', email: 'hotro@baoson.net' }
  };
  function sub(name) { return (name || 'website') + '.' + C.rootDomain; }
  function noReply() { return 'no-reply@' + C.rootDomain; }
  g.BSPLAT = {
    get: function () { return C; },
    name: function () { return C.name; },
    kit: function () { return C.kit; },
    version: function () { return C.version; },
    rootDomain: function () { return C.rootDomain; },
    sub: sub,
    noReply: noReply,
    logo: function (k) { return C.logo[k || 'sm']; },
    builtBy: function () { return C.builtBy; },
    support: function () { return C.support; },
    set: function (o) {
      o = o || {};
      Object.keys(o).forEach(function (k) {
        C[k] = (o[k] && typeof o[k] === 'object' && !Array.isArray(o[k])) ? Object.assign({}, C[k], o[k]) : o[k];
      });
      try { document.dispatchEvent(new CustomEvent('bs-platform-ready')); } catch (e) {}
    }
  };
  try { document.dispatchEvent(new CustomEvent('bs-platform-ready')); } catch (e) {}
})(window);
