<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-heading">Component Gallery <span class="text-sm font-normal text-body-muted">(dev-only)</span></h1>
        <p class="mt-1 text-sm text-body-muted">Primitive admin dựng lại từ Velzon (design source) bằng Tailwind 4 + token <code class="fs-13">--color-admin-*</code> — duyệt fidelity tại đây.</p>
    </div>

    {{-- Buttons --}}
    <x-bs-admin::card title="Buttons">
        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <x-bs-admin::button color="primary">Primary</x-bs-admin::button>
                <x-bs-admin::button color="secondary">Secondary</x-bs-admin::button>
                <x-bs-admin::button color="success">Success</x-bs-admin::button>
                <x-bs-admin::button color="info">Info</x-bs-admin::button>
                <x-bs-admin::button color="warning">Warning</x-bs-admin::button>
                <x-bs-admin::button color="danger">Danger</x-bs-admin::button>
                <x-bs-admin::button color="dark">Dark</x-bs-admin::button>
                <x-bs-admin::button color="light">Light</x-bs-admin::button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-bs-admin::button variant="soft" color="primary">Soft primary</x-bs-admin::button>
                <x-bs-admin::button variant="soft" color="success">Soft success</x-bs-admin::button>
                <x-bs-admin::button variant="soft" color="danger">Soft danger</x-bs-admin::button>
                <x-bs-admin::button variant="outline" color="primary">Outline</x-bs-admin::button>
                <x-bs-admin::button variant="outline" color="danger">Outline danger</x-bs-admin::button>
                <x-bs-admin::button variant="ghost" color="primary">Ghost</x-bs-admin::button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-bs-admin::button size="sm" color="primary">Small</x-bs-admin::button>
                <x-bs-admin::button size="md" color="primary">Medium</x-bs-admin::button>
                <x-bs-admin::button size="lg" color="primary">Large</x-bs-admin::button>
            </div>
        </div>
    </x-bs-admin::card>

    {{-- Cards --}}
    <div class="grid gap-4 md:grid-cols-3">
        <x-bs-admin::card>
            <h5 class="mb-1 text-[1rem] font-semibold text-body-text">Basic card</h5>
            <p class="text-sm text-body-muted">Body-only — shadow-card + border + radius (mirror Velzon .card).</p>
        </x-bs-admin::card>
        <x-bs-admin::card title="Card có title">
            <p class="text-sm text-body-muted">Header border-bottom, title 16px.</p>
        </x-bs-admin::card>
        <x-bs-admin::card>
            <x-slot:header>
                <h5 class="text-[1rem] font-semibold text-body-text">Header slot</h5>
                <x-bs-admin::button size="sm" variant="soft" color="primary">Action</x-bs-admin::button>
            </x-slot:header>
            <p class="text-sm text-body-muted">Header có action bên phải.</p>
        </x-bs-admin::card>
    </div>

    {{-- Badges --}}
    <x-bs-admin::card title="Badges">
        <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <x-bs-admin::badge color="primary">Primary</x-bs-admin::badge>
                <x-bs-admin::badge color="success">Success</x-bs-admin::badge>
                <x-bs-admin::badge color="danger">Danger</x-bs-admin::badge>
                <x-bs-admin::badge color="warning">Warning</x-bs-admin::badge>
                <x-bs-admin::badge color="info">Info</x-bs-admin::badge>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-bs-admin::badge soft color="success">Login Success</x-bs-admin::badge>
                <x-bs-admin::badge soft color="info">Database seeded</x-bs-admin::badge>
                <x-bs-admin::badge soft color="danger">Failed</x-bs-admin::badge>
                <x-bs-admin::badge soft pill color="primary">Pill</x-bs-admin::badge>
            </div>
        </div>
    </x-bs-admin::card>

    {{-- Inputs --}}
    <x-bs-admin::card title="Inputs">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-bs-admin::input label="Tài khoản" name="gallery_email" type="email" placeholder="ten-ban@gmail.com" />
            <x-bs-admin::input label="Mật khẩu" name="gallery_password" type="password" placeholder="••••••••" />
            <x-bs-admin::input label="Disabled" name="gallery_disabled" placeholder="Không sửa được" disabled />
        </div>
    </x-bs-admin::card>

    {{-- Table (mirrors the dashboard "Hoạt động gần đây") --}}
    <x-bs-admin::card title="Hoạt động gần đây">
        <x-bs-admin::table>
            <thead>
                <tr>
                    <th>Người dùng</th>
                    <th>Hành động</th>
                    <th>Thời gian</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>owner@anvinh.test</td>
                    <td><x-bs-admin::badge soft color="success">Login Success</x-bs-admin::badge></td>
                    <td>Just now</td>
                </tr>
                <tr>
                    <td>System</td>
                    <td><x-bs-admin::badge soft color="info">Database seeded</x-bs-admin::badge></td>
                    <td>5 phút trước</td>
                </tr>
            </tbody>
        </x-bs-admin::table>
    </x-bs-admin::card>

    {{-- Alerts --}}
    <x-bs-admin::card title="Alerts">
        <div class="space-y-3">
            <x-bs-admin::alert color="success" icon="shield">Đã lưu thay đổi.</x-bs-admin::alert>
            <x-bs-admin::alert color="info" icon="mail">Có 3 liên hệ mới trong hộp thư.</x-bs-admin::alert>
            <x-bs-admin::alert color="warning" icon="megaphone" dismissible>Tên miền sắp hết hạn — gia hạn sớm.</x-bs-admin::alert>
            <x-bs-admin::alert color="danger" icon="trash" dismissible>Không kết nối được máy chủ email.</x-bs-admin::alert>
        </div>
    </x-bs-admin::card>

    {{-- Avatars --}}
    <x-bs-admin::card title="Avatars">
        <div class="flex flex-wrap items-center gap-3">
            <x-bs-admin::avatar name="An Vinh Owner" size="sm" />
            <x-bs-admin::avatar name="Bảo Sơn" size="md" />
            <x-bs-admin::avatar name="Tuyền Trần" size="lg" />
        </div>
    </x-bs-admin::card>

    {{-- Page header --}}
    <x-bs-admin::card title="Page header">
        <x-bs-admin::page-header title="Người dùng" subtitle="Quản lý thành viên của website.">
            <x-slot:breadcrumbs>Dashboard / Người dùng</x-slot:breadcrumbs>
            <x-slot:actions>
                <x-bs-admin::button size="sm" color="primary">Thêm thành viên</x-bs-admin::button>
            </x-slot:actions>
        </x-bs-admin::page-header>
    </x-bs-admin::card>

    {{-- Pagination --}}
    <x-bs-admin::card title="Pagination">
        <x-bs-admin::pagination :current="3" :last="8" />
    </x-bs-admin::card>

    {{-- Card + footer --}}
    <x-bs-admin::card title="Card + footer">
        <p class="text-sm text-body-muted">Thân card. Footer có border-top + action.</p>
        <x-slot:footer>
            <div class="flex justify-end">
                <x-bs-admin::button size="sm" variant="soft" color="primary">Lưu</x-bs-admin::button>
            </div>
        </x-slot:footer>
    </x-bs-admin::card>

    {{-- Dropdown --}}
    <x-bs-admin::card title="Dropdown">
        <x-bs-admin::dropdown>
            <x-slot:trigger>
                <x-bs-admin::button variant="soft" color="primary">Thao tác</x-bs-admin::button>
            </x-slot:trigger>
            <a href="#" class="block px-3 py-2 hover:bg-surface-muted">Sửa</a>
            <a href="#" class="block px-3 py-2 hover:bg-surface-muted">Nhân bản</a>
            <a href="#" class="block px-3 py-2 text-admin-danger-text hover:bg-surface-muted">Xóa</a>
        </x-bs-admin::dropdown>
    </x-bs-admin::card>

    {{-- Tabs --}}
    <x-bs-admin::card title="Tabs">
        <x-bs-admin::tabs :tabs="['info' => 'Thông tin', 'security' => 'Bảo mật', 'notify' => 'Thông báo']">
            <div x-show="active === 'info'" role="tabpanel" class="text-sm text-body-muted">Nội dung tab Thông tin.</div>
            <div x-show="active === 'security'" role="tabpanel" class="text-sm text-body-muted" x-cloak>Nội dung tab Bảo mật.</div>
            <div x-show="active === 'notify'" role="tabpanel" class="text-sm text-body-muted" x-cloak>Nội dung tab Thông báo.</div>
        </x-bs-admin::tabs>
    </x-bs-admin::card>

    {{-- Tooltip --}}
    <x-bs-admin::card title="Tooltip">
        <div class="flex flex-wrap items-center gap-6">
            <x-bs-admin::tooltip text="Xem chi tiết">
                <x-bs-admin::button size="sm" variant="soft" color="info">Hover / focus tôi</x-bs-admin::button>
            </x-bs-admin::tooltip>
            <x-bs-admin::tooltip text="Chú thích ở dưới" position="bottom">
                <x-bs-admin::badge soft color="primary">Badge có tooltip</x-bs-admin::badge>
            </x-bs-admin::tooltip>
        </div>
    </x-bs-admin::card>

    {{-- Modal --}}
    <x-bs-admin::card title="Modal">
        <x-bs-admin::modal title="Xác nhận xóa" size="md">
            <x-slot:trigger>
                <x-bs-admin::button color="danger">Mở modal</x-bs-admin::button>
            </x-slot:trigger>
            <p class="text-sm text-body-muted">Bạn có chắc muốn xóa mục này? Hành động không thể hoàn tác.</p>
            <x-slot:footer>
                <x-bs-admin::button variant="soft" color="primary" x-on:click="hide()">Hủy</x-bs-admin::button>
                <x-bs-admin::button color="danger">Xóa</x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::modal>
    </x-bs-admin::card>
</div>
