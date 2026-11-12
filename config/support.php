<?php

/*
 * The platform's support team, as the product reaches it. A website owner who asks for help with
 * a task (pointing a domain: "Nhờ hỗ trợ trỏ giúp") sends a mail here, on the platform's own mail
 * account — never a customer's.
 */
return [
    // The support team's mailbox. Blank = the platform's sender address (MAIL_FROM_ADDRESS).
    'mailbox' => env('SUPPORT_MAILBOX_ADDRESS'),

    // The phone and Zalo numbers the admin's error pages offer ("Gọi hỗ trợ", "Zalo …"). Blank = the
    // channel is not shown (the email above always is).
    'phone' => env('SUPPORT_PHONE'),
    'zalo' => env('SUPPORT_ZALO'),

    // How many help requests one website may send in an hour; the rest wait.
    'help_requests_per_hour' => 3,
];
