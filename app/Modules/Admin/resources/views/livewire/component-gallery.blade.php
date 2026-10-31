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

    {{-- Record status pills: every status, then the menu trigger beside a row's buttons --}}
    <x-bs-admin::card :title="__('entries.status_label')">
        <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                @foreach (['published', 'draft', 'scheduled', 'pending', 'private', 'archived'] as $status)
                    <x-bs-admin::status-pill :status="$status">{{ __('entries.status_'.$status) }}</x-bs-admin::status-pill>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-2" x-data="{ open: false }">
                <x-bs-admin::status-pill button status="published" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-haspopup="true">{{ __('entries.status_published') }}</x-bs-admin::status-pill>
                <x-bs-admin::button size="sm" variant="soft" color="secondary">{{ __('admin.view') }}</x-bs-admin::button>
                <x-bs-admin::button size="sm" variant="soft" color="primary">{{ __('entries.edit_action') }}</x-bs-admin::button>
            </div>
        </div>
    </x-bs-admin::card>

    {{-- Shared list row: one row in each state (card < 600, one line to 900, table from 900 — by the list's width) --}}
    <x-bs-admin::card title="List rows">
        @php
            $galleryRows = [
                ['title' => 'Giới thiệu công ty', 'pill' => 'published', 'meta' => ['/gioi-thieu', '1.204 lượt xem'], 'author' => 'Nguyễn Văn Demo', 'when' => '12 Thg 10, 2026', 'state' => 'normal', 'langs' => ['vi', 'en', 'ja'], 'has' => ['vi', 'en']],
                ['title' => 'Chính sách bảo hành và đổi trả sản phẩm gia công cơ khí chính xác cho khách hàng doanh nghiệp tại Việt Nam', 'pill' => 'draft', 'meta' => ['/chinh-sach-bao-hanh-va-doi-tra-san-pham-gia-cong-co-khi-chinh-xac', 'Chưa có lượt xem'], 'author' => 'Trần Thị Ngọc Anh', 'when' => '30 Thg 10, 2026', 'state' => 'selected', 'langs' => ['vi', 'en', 'ja', 'zh', 'ko'], 'has' => ['vi', 'en']],
                ['title' => 'Tuyển dụng kỹ sư CNC', 'pill' => 'scheduled', 'meta' => ['/tuyen-dung', 'Chưa có lượt xem'], 'author' => 'Lê Minh Trí', 'when' => 'Lên lịch 05 Thg 11, 2026 · 08:00', 'state' => 'readonly', 'langs' => ['vi', 'en'], 'has' => ['vi']],
                ['title' => 'Trang cũ đã bỏ', 'pill' => 'archived', 'meta' => ['/trang-cu', '18 lượt xem'], 'author' => 'Nguyễn Văn Demo', 'when' => '01 Thg 9, 2026', 'state' => 'trashed', 'langs' => ['vi', 'en'], 'has' => ['vi', 'en']],
            ];
        @endphp
        <x-bs-admin::list label="Danh sách mẫu" class="-mx-4">
            <x-slot:select>
                <x-bs-admin::list.check checked="mixed" label="Chọn tất cả" visible />
            </x-slot:select>
            <x-bs-admin::list.head>
                <x-bs-admin::list.cell head col="chk"><x-bs-admin::list.check checked="mixed" label="Chọn tất cả" /></x-bs-admin::list.cell>
                <x-bs-admin::list.cell head col="main" sort="ascending"><x-bs-admin::list.sort active dir="asc">Tiêu đề</x-bs-admin::list.sort></x-bs-admin::list.cell>
                <x-bs-admin::list.cell head col="st" sort="none"><x-bs-admin::list.sort>Trạng thái</x-bs-admin::list.sort></x-bs-admin::list.cell>
                <x-bs-admin::list.cell head col="au" sort="none"><x-bs-admin::list.sort>Người tạo</x-bs-admin::list.sort></x-bs-admin::list.cell>
                <x-bs-admin::list.cell head col="act">Thao tác</x-bs-admin::list.cell>
            </x-bs-admin::list.head>
            @foreach ($galleryRows as $gr)
                @php $grTrashed = $gr['state'] === 'trashed'; $grEdit = $gr['state'] !== 'readonly' && ! $grTrashed; @endphp
                <x-bs-admin::list.row :selected="$gr['state'] === 'selected'">
                    <x-bs-admin::list.cell col="chk"><x-bs-admin::list.check :checked="$gr['state'] === 'selected'" :label="'Chọn “'.$gr['title'].'”'" /></x-bs-admin::list.cell>
                    <x-bs-admin::list.cell col="main">
                        <x-bs-admin::list.name :href="$grTrashed ? null : '#'" :quick-edit="$grEdit ? 'Sửa nhanh tiêu đề “'.$gr['title'].'”' : null">{{ $gr['title'] }}</x-bs-admin::list.name>
                        <x-bs-admin::list.meta>
                            @foreach ($gr['meta'] as $piece)
                                <span class="list-m">{{ $piece }}</span>
                            @endforeach
                            <x-bs-admin::list.langs :locales="$gr['langs']" :has="$gr['has']" source="vi" :name="$gr['title']"
                                :href="$grEdit ? fn (string $lc): string => '#' : null"
                                :why="$grTrashed ? __('entries.lc_why_trashed') : __('entries.lc_why_view_only', ['noun' => __('entries.noun_page')])" />
                            <span class="list-m" data-for="au">{{ $gr['author'] }}</span>
                            <span class="list-m" data-for="when">{{ $gr['when'] }}</span>
                        </x-bs-admin::list.meta>
                    </x-bs-admin::list.cell>
                    <x-bs-admin::list.cell col="st">
                        <div class="list-stw">
                            @if ($grEdit)
                                <x-bs-admin::status-pill button :status="$gr['pill']" aria-haspopup="true">{{ __('entries.status_'.$gr['pill']) }}</x-bs-admin::status-pill>
                            @else
                                <x-bs-admin::status-pill :status="$gr['pill']">{{ __('entries.status_'.$gr['pill']) }}</x-bs-admin::status-pill>
                            @endif
                        </div>
                        <div class="list-st-meta">{{ $gr['when'] }}</div>
                    </x-bs-admin::list.cell>
                    <x-bs-admin::list.cell col="au"><span class="list-au">{{ $gr['author'] }}</span></x-bs-admin::list.cell>
                    <x-bs-admin::list.cell col="act">
                        <x-bs-admin::list.actions :name="$gr['title']">
                            @if ($grTrashed)
                                <x-bs-admin::button size="sm" variant="soft" color="primary"><x-bs-admin::icon name="ri-arrow-go-back" class="size-4" /> {{ __('entries.action_restore') }}</x-bs-admin::button>
                                <x-slot:menu>
                                    <x-bs-admin::list.menu-item icon="ri-delete-bin" danger>{{ __('entries.action_destroy') }}</x-bs-admin::list.menu-item>
                                </x-slot:menu>
                            @else
                                <x-bs-admin::button size="sm" variant="soft" color="secondary"><x-bs-admin::icon name="ri-eye" class="size-4" /> {{ __('admin.view') }}</x-bs-admin::button>
                                <x-bs-admin::button size="sm" variant="soft" color="primary"><x-bs-admin::icon name="ri-pencil" class="size-4" /> {{ __('entries.edit_action') }}</x-bs-admin::button>
                                @if ($grEdit)
                                    <x-slot:menu>
                                        <x-bs-admin::list.menu-item icon="ri-pencil">{{ __('entries.quick_edit_title') }}</x-bs-admin::list.menu-item>
                                        <x-bs-admin::list.menu-item icon="ri-file-copy">{{ __('entries.action_duplicate') }}</x-bs-admin::list.menu-item>
                                        <x-bs-admin::list.menu-sep />
                                        <x-bs-admin::list.menu-item icon="ri-delete-bin" danger>{{ __('entries.action_trash') }}</x-bs-admin::list.menu-item>
                                    </x-slot:menu>
                                @endif
                            @endif
                        </x-bs-admin::list.actions>
                    </x-bs-admin::list.cell>
                </x-bs-admin::list.row>
            @endforeach
        </x-bs-admin::list>
    </x-bs-admin::card>

    {{-- Inputs --}}
    <x-bs-admin::card title="Inputs">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-bs-admin::input label="Tài khoản" name="gallery_email" type="email" placeholder="ten-ban@gmail.com" />
            <x-bs-admin::input label="Mật khẩu" name="gallery_password" type="password" placeholder="••••••••" />
            <x-bs-admin::input label="Disabled" name="gallery_disabled" placeholder="Không sửa được" disabled />
        </div>
    </x-bs-admin::card>

    {{-- List tools: search + Filter --}}
    <x-bs-admin::card title="List tools">
        @php
            $gallerySections = [
                ['key' => 'status', 'label' => 'Trạng thái', 'model' => 'galleryStatus', 'icon' => 'ri-inbox-archive', 'value' => $galleryStatus, 'options' => [
                    ['value' => 'live', 'label' => 'Tệp đang có', 'count' => 21],
                    ['value' => 'trash', 'label' => 'Thùng rác', 'count' => 3, 'divider' => true],
                ]],
                ['key' => 'kind', 'label' => 'Loại tệp', 'model' => 'galleryKinds', 'multiple' => true, 'icon' => 'ri-image-2', 'value' => $galleryKinds, 'options' => [
                    ['value' => 'image', 'label' => 'Ảnh', 'count' => 19],
                    ['value' => 'pdf', 'label' => 'Tài liệu PDF', 'count' => 2],
                ]],
            ];
            $galleryActive = ($galleryStatus !== 'live' ? 1 : 0) + count($galleryKinds);
            $galleryGroups = [
                ['key' => 'all', 'label' => 'Tất cả', 'count' => 1234],
                ['key' => 'image', 'label' => 'Ảnh', 'count' => 980],
                ['key' => 'document', 'label' => 'Tài liệu', 'count' => 120],
                ['key' => 'video', 'label' => 'Video', 'count' => 64],
                ['key' => 'used', 'label' => 'Đang dùng', 'count' => 870],
                ['key' => 'unused', 'label' => 'Chưa dùng', 'count' => 364],
                ['key' => 'recent', 'label' => 'Mới tải lên', 'count' => 12],
                ['key' => 'trash', 'label' => 'Thùng rác', 'icon' => 'ri-delete-bin', 'count' => 3, 'trash' => true],
            ];
            $galleryGroupLabel = collect($galleryGroups)->firstWhere('key', $galleryGroup)['label'] ?? null;
        @endphp
        <x-bs-admin::group-row :groups="$galleryGroups" :active="$galleryGroup" model="galleryGroup" label="Nhóm tệp" class="-mx-4 mb-2" />
        @php
            // the groups also lead the Filter sheet below 900, where their row hides (a sheet-only section)
            $gallerySections = [['key' => 'group', 'label' => 'Nhóm', 'model' => 'galleryGroup', 'value' => $galleryGroup, 'default' => 'all', 'sheet' => true, 'class' => 'min-[900px]:hidden',
                'options' => array_map(fn (array $g): array => ['value' => $g['key'], 'label' => $g['label'], 'count' => $g['count'], 'divider' => $g['trash'] ?? false], $galleryGroups)], ...$gallerySections];
        @endphp
        <div class="flex flex-col gap-3">
            <x-bs-admin::search label="Tìm theo tên tệp" wire:model.live.debounce.300ms="gallerySearch" />
            {{-- one line that never wraps: Sắp xếp drops its word first, then Lọc --}}
            <x-bs-admin::tool-row>
                <x-bs-admin::list.check :checked="false" label="Chọn tất cả" visible />
                <x-bs-admin::filter-panel class="ms-auto flex-nowrap" :sections="$gallerySections" :active="$galleryActive" :active-narrow="$galleryActive + ($galleryGroup === 'all' ? 0 : 1)" clear="clearGalleryFilters" />
                <x-bs-admin::sort-menu :options="[['key' => 'name', 'label' => 'Tên (A–Z)', 'icon' => 'ri-sort-alphabet-asc'], ['key' => 'size', 'label' => 'Lớn nhất trước'], ['key' => 'new', 'label' => 'Mới tải lên']]"
                    :active="$gallerySort" :lit="$gallerySort !== 'name'" set="setGallerySort" />
            </x-bs-admin::tool-row>
        </div>
        <x-bs-admin::filter-status class="-mx-3 mt-2" :group="$galleryGroup === 'all' ? null : $galleryGroupLabel" :summary="$galleryGroup === 'all' && $galleryActive === 0 ? null : '12 tệp'"
            :chips="array_map(fn (string $k): array => ['label' => $k === 'pdf' ? 'Tài liệu PDF' : 'Ảnh', 'remove' => '$set(\'galleryKinds\', [])'], $galleryKinds)" clear="clearGalleryFilters" />
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
