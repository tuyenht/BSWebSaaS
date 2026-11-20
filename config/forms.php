<?php

return [
    // «Xóa» on the «Biểu mẫu» list: a form with at most this many leads (spam included) is deleted
    // inside the web request, its leads through the foreign key's ON DELETE CASCADE. Above it the
    // form is switched to `deleting` (no lead taken, hidden on every screen) and a queued job
    // (DeleteLargeForm) deletes its leads in batches of delete_batch_leads (one short site
    // transaction each), then the form row.
    'delete_inline_max_leads' => (int) env('FORMS_DELETE_INLINE_MAX_LEADS', 20000),
    'delete_batch_leads' => (int) env('FORMS_DELETE_BATCH_LEADS', 2000),
];
