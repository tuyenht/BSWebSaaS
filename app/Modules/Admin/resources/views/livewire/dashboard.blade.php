<div class="dashboard">
    <h1>{{ __('admin.dashboard') }}</h1>
    <p>{{ __('admin.welcome') }}{{ auth()->user()?->name ? ' '.auth()->user()->name : '' }}</p>
</div>
