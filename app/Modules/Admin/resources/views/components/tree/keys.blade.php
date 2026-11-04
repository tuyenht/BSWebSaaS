@php
    // The keyboard help of the shared tree, folded under "Phím tắt": the keys work while a
    // record's name has the focus.
@endphp
<details {{ $attributes->merge(['class' => 'tree-keys']) }}>
    <summary>{{ __('admin.tree.keys') }}</summary>
    <p>{{ __('admin.tree.keys_intro') }}</p>
    <dl>
        <dt><kbd>Alt</kbd> + <kbd>↑</kbd> / <kbd>↓</kbd></dt><dd>{{ __('admin.tree.keys_move') }}</dd>
        <dt><kbd>Alt</kbd> + <kbd>→</kbd></dt><dd>{{ __('admin.tree.keys_in') }}</dd>
        <dt><kbd>Alt</kbd> + <kbd>←</kbd></dt><dd>{{ __('admin.tree.keys_out') }}</dd>
        <dt><kbd>Ctrl</kbd> + <kbd>Z</kbd></dt><dd>{{ __('admin.tree.keys_undo') }}</dd>
        <dt><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>Z</kbd></dt><dd>{{ __('admin.tree.keys_redo') }}</dd>
        <dt><kbd>↑</kbd> <kbd>↓</kbd> <kbd>←</kbd> <kbd>→</kbd></dt><dd>{{ __('admin.tree.keys_nav') }}</dd>
        <dt><kbd>Esc</kbd></dt><dd>{{ __('admin.tree.keys_esc') }}</dd>
    </dl>
</details>
