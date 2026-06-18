<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-800">Component Gallery <span class="text-sm font-normal text-gray-500">(dev-only)</span></h1>
        <p class="mt-1 text-sm text-gray-500">Primitive admin dựng lại từ Velzon (design source) bằng Tailwind 4 + token <code class="fs-13">--color-admin-*</code> — duyệt fidelity tại đây.</p>
    </div>

    {{-- Buttons --}}
    <x-admin::card title="Buttons">
        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <x-admin::button color="primary">Primary</x-admin::button>
                <x-admin::button color="secondary">Secondary</x-admin::button>
                <x-admin::button color="success">Success</x-admin::button>
                <x-admin::button color="info">Info</x-admin::button>
                <x-admin::button color="warning">Warning</x-admin::button>
                <x-admin::button color="danger">Danger</x-admin::button>
                <x-admin::button color="dark">Dark</x-admin::button>
                <x-admin::button color="light">Light</x-admin::button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-admin::button variant="soft" color="primary">Soft primary</x-admin::button>
                <x-admin::button variant="soft" color="success">Soft success</x-admin::button>
                <x-admin::button variant="soft" color="danger">Soft danger</x-admin::button>
                <x-admin::button variant="outline" color="primary">Outline</x-admin::button>
                <x-admin::button variant="outline" color="danger">Outline danger</x-admin::button>
                <x-admin::button variant="ghost" color="primary">Ghost</x-admin::button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-admin::button size="sm" color="primary">Small</x-admin::button>
                <x-admin::button size="md" color="primary">Medium</x-admin::button>
                <x-admin::button size="lg" color="primary">Large</x-admin::button>
            </div>
        </div>
    </x-admin::card>

    {{-- Cards --}}
    <div class="grid gap-4 md:grid-cols-3">
        <x-admin::card>
            <h5 class="mb-1 text-[1rem] font-semibold text-gray-700">Basic card</h5>
            <p class="text-sm text-gray-500">Body-only — shadow-card + border + radius (mirror Velzon .card).</p>
        </x-admin::card>
        <x-admin::card title="Card có title">
            <p class="text-sm text-gray-500">Header border-bottom, title 16px.</p>
        </x-admin::card>
        <x-admin::card>
            <x-slot:header>
                <h5 class="text-[1rem] font-semibold text-gray-700">Header slot</h5>
                <x-admin::button size="sm" variant="soft" color="primary">Action</x-admin::button>
            </x-slot:header>
            <p class="text-sm text-gray-500">Header có action bên phải.</p>
        </x-admin::card>
    </div>

    {{-- Badges --}}
    <x-admin::card title="Badges">
        <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <x-admin::badge color="primary">Primary</x-admin::badge>
                <x-admin::badge color="success">Success</x-admin::badge>
                <x-admin::badge color="danger">Danger</x-admin::badge>
                <x-admin::badge color="warning">Warning</x-admin::badge>
                <x-admin::badge color="info">Info</x-admin::badge>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-admin::badge soft color="success">Login Success</x-admin::badge>
                <x-admin::badge soft color="info">Database seeded</x-admin::badge>
                <x-admin::badge soft color="danger">Failed</x-admin::badge>
                <x-admin::badge soft pill color="primary">Pill</x-admin::badge>
            </div>
        </div>
    </x-admin::card>

    {{-- Inputs --}}
    <x-admin::card title="Inputs">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-admin::input label="Tài khoản" name="gallery_email" type="email" placeholder="ten-ban@gmail.com" />
            <x-admin::input label="Mật khẩu" name="gallery_password" type="password" placeholder="••••••••" />
            <x-admin::input label="Disabled" name="gallery_disabled" placeholder="Không sửa được" disabled />
        </div>
    </x-admin::card>

    {{-- Table (mirrors the dashboard "Hoạt động gần đây") --}}
    <x-admin::card title="Hoạt động gần đây">
        <x-admin::table>
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
                    <td><x-admin::badge soft color="success">Login Success</x-admin::badge></td>
                    <td>Just now</td>
                </tr>
                <tr>
                    <td>System</td>
                    <td><x-admin::badge soft color="info">Database seeded</x-admin::badge></td>
                    <td>5 phút trước</td>
                </tr>
            </tbody>
        </x-admin::table>
    </x-admin::card>
</div>
