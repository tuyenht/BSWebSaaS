<div class="admin-login">
    <h1>Đăng nhập quản trị</h1>

    <form wire:submit="login">
        <label>
            Email
            <input type="email" wire:model="email" autocomplete="username" required>
        </label>
        @error('email')
            <p class="error">{{ $message }}</p>
        @enderror

        <label>
            Mật khẩu
            <input type="password" wire:model="password" autocomplete="current-password" required>
        </label>
        @error('password')
            <p class="error">{{ $message }}</p>
        @enderror

        <button type="submit">Đăng nhập</button>
    </form>
</div>
