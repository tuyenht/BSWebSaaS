<?php

return [

    // How a customer's number (tenants.customer_no) is shown to staff: the prefix, then the number
    // zero-padded to this many digits — 123 shows as KH-000123. Only the number is stored, so a
    // white-label deployment sets its own prefix here and every existing customer follows. A number
    // longer than the padding is shown whole, never cut.
    'code_prefix' => env('CUSTOMER_CODE_PREFIX', 'KH-'),

    'code_pad' => (int) env('CUSTOMER_CODE_PAD', 6),

];
