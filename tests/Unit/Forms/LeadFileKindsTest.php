<?php

use App\Modules\Forms\Support\LeadFileKinds;

/*
 * Which files a form's file field takes. The name's parts are checked against the blocklist first,
 * then the last part against the kind, then the file's first bytes against that type's signature:
 * a genuine sample of every type passes, and the same name over forged bytes (another format, or
 * plain text) is refused. finfo would call STEP, IGES and DXF plain text, so each has its own check.
 */

function lfkSample(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'lfk');
    file_put_contents($path, $bytes);

    return $path;
}

/** @param array<string, string> $entries */
function lfkZip(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'lfk');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();

    return $path;
}

function lfkIges(string $column73): string
{
    $start = str_pad('Bản vẽ trục thép — IGES 5.3', 72).$column73.sprintf('%7d', 1)."\n";
    $global = str_pad('1H,,1H;,4HPART', 72).'G'.sprintf('%7d', 1)."\n";

    return $start.$global;
}

function lfkDocx(array $extra = []): string
{
    return lfkZip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>', ...$extra]);
}

/**
 * A zip written byte by byte (stored, no compression), so it can hold what ZipArchive would never
 * write: two entries of one name. @param list<array{0: string, 1: string}> $entries name, body
 */
function lfkRawZip(array $entries): string
{
    $local = '';
    $central = '';
    foreach ($entries as [$name, $body]) {
        $offset = strlen($local);
        $crc = crc32($body);
        $size = strlen($body);
        $local .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 0, $crc, $size, $size, strlen($name), 0).$name.$body;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
    }
    $end = pack('VvvvvVVv', 0x06054B50, 0, 0, count($entries), count($entries), strlen($central), strlen($local), 0);

    return lfkSample($local.$central.$end);
}

dataset('genuine', fn (): array => [
    'STEP (.step)' => ['cad', 'truc.step', fn () => lfkSample("ISO-10303-21;\nHEADER;\nFILE_DESCRIPTION(('Truc'),'2;1');\nENDSEC;\n")],
    'STEP with a BOM (.stp)' => ['cad', 'truc.STP', fn () => lfkSample("\xEF\xBB\xBF  ISO-10303-21;\nHEADER;\n")],
    'IGES (.iges)' => ['cad', 'banve.iges', fn () => lfkSample(lfkIges('S'))],
    'IGES (.igs)' => ['cad', 'banve.igs', fn () => lfkSample(str_replace("\n", "\r\n", lfkIges('S')))],
    'DWG' => ['cad', 'banve.dwg', fn () => lfkSample('AC1032'.str_repeat("\x00", 64))],
    'ASCII DXF' => ['cad', 'banve.dxf', fn () => lfkSample("  0\nSECTION\n  2\nHEADER\n")],
    'ASCII DXF with a comment' => ['cad', 'banve.dxf', fn () => lfkSample("999\nDXF written by test\n  0\r\nSECTION\r\n")],
    'binary DXF' => ['cad', 'banve.dxf', fn () => lfkSample("AutoCAD Binary DXF\r\n\x1A\x00".str_repeat("\x01", 16))],
    'PDF drawing' => ['cad', 'banve.pdf', fn () => lfkSample("%PDF-1.7\n%\xE2\xE3\xCF\xD3\n")],
    'PDF CV' => ['cv', 'Hồ sơ Nguyễn Văn A.pdf', fn () => lfkSample("%PDF-1.4\n")],
    'DOC' => ['cv', 'cv.doc', fn () => lfkSample("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 64))],
    'DOCX' => ['cv', 'cv.docx', fn () => lfkDocx()],
    'DOCX written by hand' => ['cv', 'cv.docx', fn () => lfkRawZip([['[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types/>'], ['word/document.xml', '<w:document/>']])],
    'DOCX whose types part has a UTF-8 BOM' => ['cv', 'cv.docx', fn () => lfkDocx(['[Content_Types].xml' => "\xEF\xBB\xBF<Types/>"])],
    'JPG' => ['img', 'anh.jpg', fn () => lfkSample("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00")],
    'JPEG' => ['img', 'anh.JPEG', fn () => lfkSample("\xFF\xD8\xFF\xE1\x00\x10Exif\x00")],
    'PNG' => ['img', 'anh.png', fn () => lfkSample("\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR")],
]);

it('takes a genuine file of every type each kind lists', function (string $kind, string $name, Closure $sample): void {
    expect(LeadFileKinds::refusal($kind, $name, $sample()))->toBeNull();
})->with('genuine');

dataset('forged', fn (): array => [
    'STEP that is text' => ['cad', 'truc.step', fn () => lfkSample("HEADER;\nISO-10303-21;\n")],
    'IGES without its section letter' => ['cad', 'banve.igs', fn () => lfkSample(lfkIges(' '))],
    'IGES too short' => ['cad', 'banve.iges', fn () => lfkSample("S      1\n")],
    'DWG that is a zip' => ['cad', 'banve.dwg', fn () => lfkSample("PK\x03\x04AC1032")],
    'DWG of no version' => ['cad', 'banve.dwg', fn () => lfkSample('AC9999')],
    'DXF that is text' => ['cad', 'banve.dxf', fn () => lfkSample("SECTION\n  0\n")],
    'PDF that is a page' => ['cv', 'cv.pdf', fn () => lfkSample('<html><script>alert(1)</script>%PDF-1.4')],
    'DOC that is a zip' => ['cv', 'cv.doc', fn () => lfkDocx()],
    'DOCX with a macro project' => ['cv', 'cv.docx', fn () => lfkDocx(['word/vbaProject.bin' => "\xD0\xCF\x11\xE0"])],
    'DOCX with a macro project in capitals' => ['cv', 'cv.docx', fn () => lfkDocx(['word/VBAPROJECT.BIN' => 'x'])],
    'DOCX declaring a macro type' => ['cv', 'cv.docx', fn () => lfkZip(['[Content_Types].xml' => '<Types><Default ContentType="application/vnd.ms-word.document.macroEnabled.main+xml"/></Types>', 'word/document.xml' => '<w:document/>'])],
    'DOCX with a renamed macro part' => ['cv', 'cv.docx', fn () => lfkZip(['[Content_Types].xml' => '<Types><Override PartName="/word/x.bin" ContentType="application/vnd.ms-office.vbaProject"/></Types>', 'word/document.xml' => '<w:document/>', 'word/x.bin' => 'x'])],
    'DOCX that is another zip' => ['cv', 'cv.docx', fn () => lfkZip(['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<x/>'])],
    'DOCX that is no zip' => ['cv', 'cv.docx', fn () => lfkSample("PK\x03\x04 broken")],
    'JPG that is a GIF' => ['img', 'anh.jpg', fn () => lfkSample('GIF89a')],
    'PNG that is a JPG' => ['img', 'anh.png', fn () => lfkSample("\xFF\xD8\xFF\xE0")],
    'an empty file' => ['img', 'anh.png', fn () => lfkSample('')],
]);

it('refuses a file whose bytes are not its type', function (string $kind, string $name, Closure $sample): void {
    expect(LeadFileKinds::refusal($kind, $name, $sample()))->toBe('forged');
})->with('forged');

dataset('docx tricks', fn (): array => [
    // the macro word sits past the first megabyte: the part is refused, not read in part
    'types part over 1 MB' => [fn () => lfkDocx(['[Content_Types].xml' => '<Types>'.str_repeat(' ', 1024 * 1024).'<Default ContentType="application/vnd.ms-word.document.macroEnabled.main+xml"/></Types>'])],
    'types part over 1 MB with no macro' => [fn () => lfkDocx(['[Content_Types].xml' => '<Types>'.str_repeat(' ', 1024 * 1024).'</Types>'])],
    'types part in UTF-16 LE' => [fn () => lfkDocx(['[Content_Types].xml' => "\xFF\xFE".mb_convert_encoding('<Types><Default ContentType="application/vnd.ms-word.document.macroEnabled.main+xml"/></Types>', 'UTF-16LE', 'UTF-8')])],
    'types part in UTF-16 BE' => [fn () => lfkDocx(['[Content_Types].xml' => "\xFE\xFF".mb_convert_encoding('<Types/>', 'UTF-16BE', 'UTF-8')])],
    'types part in UTF-16 with no BOM' => [fn () => lfkDocx(['[Content_Types].xml' => mb_convert_encoding('<Types/>', 'UTF-16LE', 'UTF-8')])],
    'types part declaring another encoding' => [fn () => lfkDocx(['[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-32"?><Types/>'])],
    'types part holding a NUL byte' => [fn () => lfkDocx(['[Content_Types].xml' => "<Types>\x00</Types>"])],
    'types part with a DTD' => [fn () => lfkDocx(['[Content_Types].xml' => '<!DOCTYPE Types [<!ENTITY m "macro">]><Types><Default ContentType="application/vnd.ms-word.document.&m;Enabled.main+xml"/></Types>'])],
    'macro word spelt with character references' => [fn () => lfkDocx(['[Content_Types].xml' => '<Types><Default ContentType="application/vnd.ms-word.document.macro&#69;nabled.main+xml"/></Types>'])],
    'macro part named with a hex reference' => [fn () => lfkDocx(['[Content_Types].xml' => '<Types><Override PartName="/word/x.bin" ContentType="application/vnd.ms-office.vba&#x50;roject"/></Types>', 'word/x.bin' => 'x'])],
    'empty types part' => [fn () => lfkDocx(['[Content_Types].xml' => ''])],
    // a clean part first, the one declaring the macro second: a reader may take either
    'two types parts' => [fn () => lfkRawZip([['[Content_Types].xml', '<Types/>'], ['word/document.xml', '<w:document/>'], ['[Content_Types].xml', '<Types><Default ContentType="application/vnd.ms-word.document.macroEnabled.main+xml"/></Types>']])],
    'two types parts in other cases' => [fn () => lfkRawZip([['[Content_Types].xml', '<Types/>'], ['word/document.xml', '<w:document/>'], ['[CONTENT_TYPES].XML', '<Types/>']])],
    'two document parts' => [fn () => lfkRawZip([['[Content_Types].xml', '<Types/>'], ['word/document.xml', '<w:document/>'], ['Word/Document.xml', '<w:document/>']])],
    'two macro projects' => [fn () => lfkRawZip([['[Content_Types].xml', '<Types/>'], ['word/document.xml', '<w:document/>'], ['word/vbaProject.bin', 'x'], ['word/VbaProject.Bin', 'x']])],
    'a macro project in another folder' => [fn () => lfkDocx(['customXml/item1vbaPROJECT.bin' => 'x'])],
    'a macro project behind a backslash' => [fn () => lfkRawZip([['[Content_Types].xml', '<Types/>'], ['word/document.xml', '<w:document/>'], ['word\\vbaProject.bin', 'x']])],
    'types part behind a leading slash, twice' => [fn () => lfkRawZip([['[Content_Types].xml', '<Types/>'], ['word/document.xml', '<w:document/>'], ['/[Content_Types].xml', '<Types/>']])],
    'no types part' => [fn () => lfkZip(['word/document.xml' => '<w:document/>', 'docProps/app.xml' => '<x/>'])],
]);

it('refuses a DOCX whose parts could hide a macro from this check', function (Closure $sample): void {
    expect(LeadFileKinds::refusal('cv', 'cv.docx', $sample()))->toBe('forged');
})->with('docx tricks');

it('refuses a blocked name part before anything else, wherever it sits', function (string $name): void {
    expect(LeadFileKinds::refusal('cv', $name, lfkSample("%PDF-1.4\n")))->toBe('blocked');
})->with(['cv.php.pdf', 'cv.PHTML.pdf', 'cv.phar', 'cv.exe', 'cv.html.pdf', 'cv.svg', 'cv.js', 'cv.php .pdf', 'cv.docm', 'cv.pdf.php']);

it('refuses a type the kind does not take, or a name with no type', function (string $kind, string $name): void {
    expect(LeadFileKinds::refusal($kind, $name, lfkSample("%PDF-1.4\n")))->toBe('wrong_type');
})->with([
    ['cv', 'cv.txt'], ['img', 'banve.pdf'], ['img', 'banve.dwg'], ['cv', 'banve.step'], ['cad', 'cv.docx'],
    ['cv', 'cv'], ['cv', '.pdf'], ['cv', 'cv.'], ['cv', 'bao-gia.com'],
]);

it('takes ordinary names that hold dots', function (): void {
    expect(LeadFileKinds::refusal('cv', 'Nguyễn.Văn.A.congty.com.pdf', lfkSample("%PDF-1.4\n")))->toBeNull();
});

it('rejects an unknown kind loudly', function (): void {
    expect(fn () => LeadFileKinds::refusal('video', 'a.mp4', lfkSample('x')))->toThrow(InvalidArgumentException::class);
});

it('keeps a clean name: no folder, no control or quote characters, at most 255 characters with its type', function (): void {
    expect(LeadFileKinds::cleanName('C:\\Users\\hoa\\Hồ sơ "mới".pdf'))->toBe('Hồ sơ mới.pdf')
        ->and(LeadFileKinds::cleanName("../../etc/pass\x00wd.pdf"))->toBe('passwd.pdf')
        ->and(LeadFileKinds::cleanName('   '))->toBe('tep')
        ->and(LeadFileKinds::cleanName('..'))->toBe('tep')
        ->and(LeadFileKinds::cleanName("bad\xFFbyte.pdf"))->toBe('badbyte.pdf')
        // no direction or invisible characters: «cv\u{202E}fdp.docx» would show as «cvxcod.pdf»
        ->and(LeadFileKinds::cleanName("cv\u{202E}fdp\u{200B}.docx"))->toBe('cvfdp.docx')
        ->and(LeadFileKinds::cleanName("\u{2067}cv\u{2069}.pdf"))->toBe('cv.pdf');
    $long = LeadFileKinds::cleanName(str_repeat('ă', 300).'.docx');
    expect(mb_strlen($long))->toBe(255)->and($long)->toEndWith('ă.docx');
});
