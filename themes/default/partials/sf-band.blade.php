{{--
    The band behind a section look (used through @component): `bg` is one of none, surface, tint,
    dark (SectionProps::BG, already checked by the caller); the slot is the section's content.
--}}
<section class="sf-band sf-band--{{ $bg ?? 'none' }}{{ ! empty($class) ? ' '.$class : '' }}">
    <div class="sf-container">
{{ $slot }}
    </div>
</section>
