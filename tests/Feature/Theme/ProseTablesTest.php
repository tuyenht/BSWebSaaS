<?php

use App\Modules\Theme\Rendering\ProseTables;

/*
 * Tables in formatted text get their layout when the page is printed (board "Bảng trong bài viết"):
 * the stored value never changes, so old bodies are laid out the same way.
 */

function laidOut(string $html): string
{
    return ProseTables::apply($html);
}

/** The wrapper's class list of the only table in $html. */
function tableClass(string $html): string
{
    expect(preg_match('~<div class="(sf-table [^"]*)"~', laidOut($html), $m))->toBe(1);

    return $m[1];
}

function rowsOf(int $rows, int $columns, string $cell = 'x'): string
{
    $html = '';
    for ($r = 0; $r < $rows; $r++) {
        $html .= '<tr>'.str_repeat("<td>{$cell}</td>", $columns).'</tr>';
    }

    return $html;
}

it('leaves text without a table untouched', function (): void {
    $html = '<p>Không có bảng &amp; gì cả</p>';

    expect(laidOut($html))->toBe($html);
});

it('fits a two-column table as label and value, with no tab stop', function (): void {
    $html = laidOut('<table style="width:900px;"><tbody><tr><td style="width:40%;height:30px;">Vật liệu</td><td>SUS304</td></tr><tr><td>Dung sai</td><td>±0.01</td></tr></tbody></table>');

    expect($html)->toBe('<div class="sf-table sf-table--kv sf-cols-2"><table><tbody><tr><td style="width:40%;">Vật liệu</td><td>SUS304</td></tr><tr><td>Dung sai</td><td>±0.01</td></tr></tbody></table></div>');
});

it('turns a short table with a header into cards, each cell named by its column', function (): void {
    $html = laidOut('<table><thead><tr><th>Máy</th><th>Hãng</th><th>Số lượng</th></tr></thead><tbody>'
        .'<tr><td>Phay CNC</td><td>Mazak</td><td>4</td></tr><tr><td>Tiện CNC</td><td>Okuma</td><td>3</td></tr></tbody></table>');

    expect($html)->toContain('<div class="sf-table sf-table--stack sf-cols-3 sf-table--rowhead">')
        ->and($html)->toContain('<th scope="col">Máy</th>')
        ->and($html)->toContain('<td data-label="Hãng">Mazak</td>')
        ->and($html)->toContain('<td data-label="Số lượng">3</td>')
        ->and($html)->not->toContain('tabindex');
});

it('scrolls a wide table inside a region named by the heading above it', function (): void {
    $head = '<thead><tr>'.str_repeat('<th>Cột</th>', 7).'</tr></thead>';
    $html = laidOut('<h2>Bảng <em>giá</em> &amp; thông số</h2><p>x</p><table>'.$head.'<tbody>'.rowsOf(3, 7).'</tbody></table>');

    expect($html)->toContain('<div class="sf-table sf-table--scroll sf-cols-7 sf-table--rowhead" role="region" aria-label="Bảng giá &amp; thông số" tabindex="0">');
});

it('names a scrolling table by its first column when no heading comes before it', function (): void {
    $table = '<table><thead><tr><th>Mã</th>'.str_repeat('<th>c</th>', 5).'</tr></thead><tbody>'.rowsOf(2, 6).'</tbody></table><h2>Sau bảng</h2>';

    expect(laidOut($table))->toContain('aria-label="'.__('storefront.table_named', ['name' => 'Mã']).'"')
        ->and(laidOut('<table><tbody>'.rowsOf(2, 6).'</tbody></table>'))->toContain('aria-label="'.__('storefront.table').'"');
});

it('scrolls every table with merged cells and labels none of its cells', function (): void {
    $html = laidOut('<table><thead><tr><th colspan="2">Thông số</th></tr></thead><tbody><tr><td>A</td><td>1</td></tr></tbody></table>');

    expect($html)->toContain('sf-table--scroll sf-cols-2 sf-table--merged')
        ->and($html)->toContain('<th colspan="2" scope="col">Thông số</th>')
        ->and($html)->not->toContain('data-label');
});

it('counts merged columns, but not a span past twenty', function (): void {
    expect(tableClass('<table><tbody><tr><td colspan="5">a</td><td>b</td></tr></tbody></table>'))->toContain('sf-cols-6')
        ->and(tableClass('<table><tbody><tr><td colspan="999">a</td></tr></tbody></table>'))->toContain('sf-cols-12');
});

it('takes a wholly bold first row as the header, the top-left cell allowed empty', function (): void {
    $html = laidOut('<table><tbody><tr><td></td><td><strong>Phay</strong></td><td><b>Tiện</b></td></tr>'
        .'<tr><td>Số máy</td><td>4</td><td>3</td></tr><tr><td>Hãng</td><td>Mazak</td><td>Okuma</td></tr></tbody></table>');

    expect($html)->toContain('<thead><tr><th scope="col"></th><th scope="col">Phay</th><th scope="col">Tiện</th></tr></thead>')
        ->and($html)->toContain('<td data-label="Tiện">Okuma</td>')
        ->and($html)->toContain('sf-table--stack');
});

it('keeps a bold first row as data when the table is too short or a cell is only partly bold', function (string $table): void {
    expect(laidOut($table))->not->toContain('<thead>');
})->with([
    'two rows' => '<table><tbody><tr><td><b>A</b></td><td><b>B</b></td><td><b>C</b></td></tr><tr><td>1</td><td>2</td><td>3</td></tr></tbody></table>',
    'partly bold' => '<table><tbody><tr><td><b>A</b> và b</td><td><b>B</b></td><td><b>C</b></td></tr>'.rowsOf(2, 3).'</tbody></table>',
]);

it('moves a first row of header cells into the header', function (): void {
    expect(laidOut('<table><tbody><tr><th>A</th><th>B</th><th>C</th></tr>'.rowsOf(2, 3).'</tbody></table>'))
        ->toContain('<thead><tr><th scope="col">A</th><th scope="col">B</th><th scope="col">C</th></tr></thead>');
});

it('drops rows with nothing in them but keeps a row holding only a picture', function (): void {
    $html = laidOut('<table><tbody><tr><td>a</td><td>b</td></tr><tr><td> </td><td>&nbsp;</td></tr><tr><td><img src="https://cdn.test/a.png" alt=""></td><td></td></tr></tbody></table>');

    expect(substr_count($html, '<tr>'))->toBe(2)
        ->and($html)->toContain('<img src="https://cdn.test/a.png" alt="">');
});

it('scrolls a table whose text is long or that has many rows', function (string $body): void {
    expect(tableClass('<table><thead><tr><th>A</th><th>B</th><th>C</th></tr></thead><tbody>'.$body.'</tbody></table>'))->toStartWith('sf-table sf-table--scroll');
})->with([
    'eleven rows' => rowsOf(11, 3),
    'a long cell' => rowsOf(1, 3, str_repeat('chữ ', 31)),
    'long on average' => rowsOf(2, 3, str_repeat('x', 41)),
]);

it('does not mark a first column of numbers as row headers', function (): void {
    expect(tableClass('<table><thead><tr><th>Mã</th><th>B</th><th>C</th></tr></thead><tbody>'.rowsOf(3, 3, '±0.01').'</tbody></table>'))
        ->not->toContain('rowhead');
});

it('lays out each outer table and leaves a nested one inside its cell', function (): void {
    $html = laidOut('<table><tbody><tr><td>a</td><td><table><tbody><tr><td style="width:500px;">trong</td></tr></tbody></table></td></tr></tbody></table><p>giữa</p><table><tbody><tr><td>b</td></tr></tbody></table>');

    expect(substr_count($html, '<div class="sf-table'))->toBe(2)
        ->and($html)->toContain('<td style="width:500px;">trong</td>')
        ->and($html)->toContain('</div><p>giữa</p><div class="sf-table')
        // the nested table keeps its width, so its outer table scrolls and a keyboard can reach it
        ->and($html)->toStartWith('<div class="sf-table sf-table--scroll sf-cols-2" role="region"');
});

it('leaves a table that never closes as it is', function (): void {
    $html = '<p>a</p><table><tbody><tr><td>x</td></tr></tbody>';

    expect(laidOut($html))->toBe($html);
});

it('escapes what it copies into attributes', function (): void {
    $html = laidOut('<table><thead><tr><th>A "x" &amp; &lt;b&gt;</th><th>B</th><th>C</th></tr></thead><tbody>'.rowsOf(2, 3).'</tbody></table>');

    expect($html)->toContain('data-label="A &quot;x&quot; &amp; b>"');
});

it('never leaves a tag opening inside a name it writes', function (): void {
    $html = laidOut('<h2>x&lt;/div&gt;&lt;img src=x onerror=1&gt;</h2><table><thead><tr><th>a&lt;img src=x&gt;</th><th>&lt; 5 mm</th><th>C</th><th>D</th><th>E</th></tr></thead><tbody>'.rowsOf(2, 5).'</tbody></table>');

    expect($html)->toContain('aria-label="x/div>img src=x onerror=1>"')
        ->and($html)->not->toContain('data-label="a<img')
        ->and($html)->not->toMatch('~="[^"]*<[a-z/!?]~i');
});

it('keeps an empty row that a merged cell spans, so no cell moves to another column', function (): void {
    $html = laidOut('<table><tbody><tr><td rowspan="2">A</td><td>B</td></tr><tr><td></td></tr><tr><td>C</td><td>D</td></tr></tbody></table>');

    expect(substr_count($html, '<tr>'))->toBe(3);
});

it('reads a line break or a paragraph in a cell or a heading as a space', function (): void {
    $html = laidOut('<h2>Bảng<br />giá</h2><table><thead><tr><th>Đơn<br />giá</th><th><p>Tên</p><p>máy</p></th><th><strong>Mã</strong>số</th><th>D</th><th>E</th></tr></thead><tbody>'.rowsOf(2, 5).'</tbody></table>');

    expect($html)->toContain('aria-label="Bảng giá"')
        ->and($html)->toContain('data-label="Đơn giá"')
        ->and($html)->toContain('data-label="Tên máy"')
        ->and($html)->toContain('data-label="Mãsố"');
});

it('marks header cells in the body as row headers', function (): void {
    $html = laidOut('<table><thead><tr><th>Máy</th><th>B</th><th>C</th></tr></thead><tbody><tr><th>Phay</th><td>1</td><td>2</td></tr><tr><th scope="col">Tiện</th><td>3</td><td>4</td></tr></tbody></table>');

    expect($html)->toContain('<th scope="row">Phay</th>')
        ->and($html)->toContain('<th scope="col">Tiện</th>')
        ->and($html)->not->toContain('<th scope="row" data-label');
});

it('gives every header row its scope and names the cells by the last one', function (): void {
    $html = laidOut('<table><thead><tr><th>Nhóm</th><th>Nhóm</th><th>Nhóm</th></tr><tr><th>a</th><th>b</th><th>c</th></tr></thead><tbody>'.rowsOf(2, 3).'</tbody></table>');

    expect(substr_count($html, 'scope="col"'))->toBe(6)
        ->and($html)->toContain('<td data-label="a">x</td><td data-label="b">x</td>');
});

it('names a table by a heading before it, never by one inside an earlier table', function (): void {
    $html = laidOut('<table><tbody><tr><td><h3>Ghi chú</h3></td><td>x</td></tr></tbody></table><p>p</p><table><thead><tr><th>A</th><th>B</th><th>C</th><th>D</th><th>E</th></tr></thead><tbody>'.rowsOf(2, 5).'</tbody></table>');

    expect($html)->not->toContain('aria-label="Ghi chú"')->toContain('aria-label="'.__('storefront.table_named', ['name' => 'A']).'"');
});

it('leaves the scope off a header that spans its whole row', function (): void {
    $html = laidOut('<table><thead><tr><th>Máy</th><th>B</th><th>C</th></tr></thead><tbody><tr><th colspan="3">Nhóm 1</th></tr><tr><th>Phay</th><td>1</td><td>2</td></tr></tbody></table>');

    expect($html)->toContain('<th colspan="3">Nhóm 1</th>')->toContain('<th scope="row">Phay</th>');
});

it('changes nothing on a second pass, even when a name holds a ">"', function (): void {
    $twice = laidOut('<h2>Áp suất &gt; 5 bar</h2><table><thead><tr><th>A</th><th>B</th><th>C</th><th>D</th><th>E</th></tr></thead><tbody>'.rowsOf(3, 5).'</tbody></table><table><thead><tr><th>&gt;5</th><th>B</th><th>C</th><th>D</th><th>E</th></tr></thead><tbody>'.rowsOf(3, 5).'</tbody></table>');

    expect(laidOut($twice))->toBe($twice)
        ->and(substr_count($twice, '<div class="sf-table'))->toBe(2);
});

it('changes nothing on a second pass', function (): void {
    $once = laidOut('<h2>Máy</h2><table><thead><tr><th>A</th><th>B</th><th>C</th><th>D</th><th>E</th></tr></thead><tbody>'.rowsOf(3, 5).'</tbody></table><table><tbody>'.rowsOf(2, 2).'</tbody></table>');

    expect(laidOut($once))->toBe($once);
});
