<?php

return [

    // How a customer's number (tenants.customer_no) is shown to staff: the prefix, then the number
    // zero-padded to this many digits — 123 shows as KH-000123. Only the number is stored, so a
    // white-label deployment sets its own prefix here and every existing customer follows. A number
    // longer than the padding is shown whole, never cut.
    'code_prefix' => env('CUSTOMER_CODE_PREFIX', 'KH-'),

    'code_pad' => (int) env('CUSTOMER_CODE_PAD', 6),

    // The country a customer's contact phone belongs to when it is typed without one ("0901 234 567"
    // → +84901234567), and the one shown back in its national form (tenants.contact_phone). It must
    // be a country that dials a leading 0 at home ("0" + the number), as Vietnam does.
    'phone_country_code' => env('CUSTOMER_PHONE_COUNTRY_CODE', '84'),

];
