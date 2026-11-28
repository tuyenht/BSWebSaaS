// The «Mẫu thư» list's search fold (email-templates.blade.php, the section's x-data): Vietnamese
// diacritics and đ/Đ fold away in any case, so «dat» and «ĐẶT» both find «Đặt lại mật khẩu».
// `node --test tests/js/admin-email-template-search.test.mjs`.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const blade = readFileSync(new URL('../../app/Modules/Admin/resources/views/livewire/email-templates.blade.php', import.meta.url), 'utf8');
const body = blade.match(/fold\(s\) \{ (return [^\n]*?); \},/)?.[1];
assert.ok(body, 'the fold() line is in the blade');
const fold = new Function('s', `${body};`);

test('folds case, marks and đ/Đ the same way on both sides', () => {
    const name = fold('Đặt lại mật khẩu');
    assert.equal(name, 'dat lai mat khau');
    for (const q of ['dat', 'ĐẶT', 'đặt lại', 'MAT KHAU']) {
        assert.ok(name.includes(fold(q)), q);
    }
    assert.ok(!name.includes(fold('xác minh')));
});
