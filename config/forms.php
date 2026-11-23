<?php

return [
    // «Xóa» on the «Biểu mẫu» list: a form with at most this many leads (spam included) is deleted
    // inside the web request, its leads through the foreign key's ON DELETE CASCADE. Above it the
    // form is switched to `deleting` (no lead taken, hidden on every screen) and a queued job
    // (DeleteLargeForm) deletes its leads in batches of delete_batch_leads (one short site
    // transaction each), then the form row. Each lead also takes its search words with it (up to
    // 64 rows in lead_search_terms), so the inline limit is kept low enough that the cascade stays
    // well under two seconds on a slower disk (20,000 leads × 42 words took 0.6 s on tmpfs).
    'delete_inline_max_leads' => (int) env('FORMS_DELETE_INLINE_MAX_LEADS', 5000),
    'delete_batch_leads' => (int) env('FORMS_DELETE_BATCH_LEADS', 2000),

    // «Tệp đính kèm»: the private disk the files visitors send with a lead are written to
    // (filesystems.php `leads`), and the largest file a field takes, in megabytes (board «Biểu
    // mẫu»: «Tối đa 20 MB mỗi tệp»). One value for every website until the plans carry it.
    'files_disk' => env('FORMS_FILES_DISK', 'leads'),
    'file_max_mb' => (int) env('FORMS_FILE_MAX_MB', 20),
    // The kinds a file field may be set to take (LeadFileKinds::KINDS), comma-separated. A kind
    // left out is not offered in «Biểu mẫu», its starting field is left out of new forms, and a
    // field already set to it is neither shown on the website nor collected — e.g. `cad,img` holds
    // back CVs until keeping them is signed off.
    'file_kinds' => array_values(array_filter(array_map('trim', explode(',', (string) env('FORMS_FILE_KINDS', 'cad,cv,img'))))),
];
