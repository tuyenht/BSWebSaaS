{{--
    A live region only a screen reader hears: the words a script puts in it (a drag saying where the row
    will land) are read out politely, nothing shows on screen. Pass x-ref so the script can find it, or
    put the words in its slot when the server redraws them (a sentence that follows the ticked boxes).
--}}
<p {{ $attributes->merge(['class' => 'sr-only', 'role' => 'status', 'aria-live' => 'polite']) }}>{{ $slot }}</p>
