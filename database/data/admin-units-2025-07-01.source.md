# admin-units-2025-07-01.csv — where it comes from

Vietnam's two-level administrative units in force from 01/07/2025 (Quyết định 19/2025/QĐ-TTg,
"Danh mục và mã số các đơn vị hành chính Việt Nam"): 34 provinces/cities and 3,321 wards/communes.

- Source: the General Statistics Office's official catalog service,
  `https://danhmuchanhchinh.nso.gov.vn/DMDVHC.asmx` — SOAP methods `DanhMucTinh` (provinces) and
  `DanhMucPhuongXa` (wards, one call per province), both with `DenNgay = 01/07/2025`.
- Downloaded: 2026-11-04.
- Changes from the source: only whitespace — runs of spaces and line breaks inside a name are
  collapsed to one space (the source has "Phường ⏎ Hoàn Kiếm"). Codes and names are otherwise as
  published; a ward's `parent_code` is its province's code.
- Format: the header `admin-units:import` reads — `level,code,name,parent_code,valid_from`
  (UTF-8, no comments, `valid_from` = 2025-07-01 for every row).

Load it (dev, or prod at deploy): `php artisan admin-units:import database/data/admin-units-2025-07-01.csv`.
Re-running is safe: units are upserted by their official code. When a newer list is published, add
it as a new dated file and import it with `--retire-missing`.
