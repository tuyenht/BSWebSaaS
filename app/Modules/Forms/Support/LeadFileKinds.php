<?php

declare(strict_types=1);

namespace App\Modules\Forms\Support;

use InvalidArgumentException;
use ZipArchive;

/**
 * Which files a form's file field takes («Tệp đính kèm», board «Biểu mẫu»: three fixed kinds) and
 * whether a sent file really is one of them. The file's own bytes decide, never the browser's type
 * or the name alone (CLAUDE.md §2.5):
 *
 *  1. the name: every part after its first dot is checked against BLOCKED first, so `cv.php.pdf` or
 *     `x.html.` is refused whatever it holds; then its last part must be one the kind takes;
 *  2. the bytes: the start of the file must carry the signature of that extension. finfo calls
 *     STEP, IGES and DXF `text/plain`, so each format has its own check here:
 *     STEP `ISO-10303-21;` · IGES the start record's section letter `S` in column 73 · DWG `AC10nn`
 *     · DXF `0` / `SECTION` (or the binary DXF sentinel) · PDF `%PDF-` · DOC the OLE header
 *     `D0 CF 11 E0 A1 B1 1A E1` · DOCX a zip holding `word/document.xml` and `[Content_Types].xml`
 *     and no `vbaProject.bin` (a macro project: a .docm renamed), nor a macro type declared in
 *     `[Content_Types].xml` · JPG `FF D8 FF` · PNG its 8-byte header.
 *
 * Only the first HEAD_BYTES are read; a DOCX is opened through its central directory (ZipArchive),
 * only `[Content_Types].xml` is read (at most 1 MB). A kept file is still only ever handed out as a download
 * (`application/octet-stream` + `attachment`), never shown in a page: the platform runs no virus
 * scan, a DOC can carry macros this check cannot see, and a DOCX can still point at a remote
 * template.
 */
final class LeadFileKinds
{
    /** @var array<string, list<string>> kind => the extensions it takes */
    public const array KINDS = [
        'cad' => ['step', 'stp', 'iges', 'igs', 'dwg', 'dxf', 'pdf'],
        'cv' => ['pdf', 'doc', 'docx'],
        'img' => ['jpg', 'jpeg', 'png'],
    ];

    /** @var array<string, string> extension => the type recorded for a kept file */
    public const array MIME = [
        'step' => 'model/step',
        'stp' => 'model/step',
        'iges' => 'model/iges',
        'igs' => 'model/iges',
        'dwg' => 'image/vnd.dwg',
        'dxf' => 'image/vnd.dxf',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    /**
     * Name parts never taken, wherever they sit in the name (a server that runs `x.php.pdf` as PHP,
     * a computer that runs `x.exe`): server code, pages and scripts, programs, macro documents.
     * Words that also occur in ordinary names (`com`, `app`, `so`) are left out: as the last part
     * they are no kind's extension anyway.
     */
    public const array BLOCKED = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'pgif',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'ashx', 'asmx', 'jsp', 'jspx',
        'htaccess', 'htpasswd', 'html', 'htm', 'xhtml', 'shtml', 'xht', 'svg', 'svgz',
        'js', 'mjs', 'cjs', 'jse', 'vbs', 'vbe', 'wsf', 'wsh', 'hta', 'ps1', 'psm1', 'bat', 'cmd',
        'exe', 'dll', 'scr', 'msi', 'msp', 'cpl', 'lnk', 'jar', 'apk',
        'docm', 'dotm', 'xlsm', 'xltm', 'pptm', 'potm', 'ppsm', 'swf',
    ];

    /** Enough for every signature here (an IGES start record is 80 columns plus its line break). */
    private const int HEAD_BYTES = 512;

    /** A real document holds a few dozen parts; far more is not a Word file. */
    private const int DOCX_MAX_ENTRIES = 5000;

    /**
     * Why $name / $path is not a file of $kind, or null when it is one.
     *
     * @return 'blocked'|'wrong_type'|'forged'|null
     */
    public static function refusal(string $kind, string $name, string $path): ?string
    {
        $takes = self::KINDS[$kind] ?? throw new InvalidArgumentException("Unknown file kind [{$kind}].");

        $parts = explode('.', mb_strtolower($name));
        array_shift($parts);
        foreach ($parts as $part) {
            if (in_array(trim($part), self::BLOCKED, true)) {
                return 'blocked';
            }
        }

        $extension = self::extension($name);
        if ($extension === null || ! in_array($extension, $takes, true)) {
            return 'wrong_type';
        }

        return self::looksLike($extension, $path) ? null : 'forged';
    }

    /** The name's last part, lower case, or null when it has none. */
    public static function extension(string $name): ?string
    {
        $dot = strrpos($name, '.');
        if ($dot === false || $dot === 0) {
            return null;
        }
        $extension = mb_strtolower(substr($name, $dot + 1));

        return $extension === '' ? null : $extension;
    }

    /**
     * The visitor's file name as it may be kept and shown: no folder, no control or quote
     * characters, at most 255 characters with its extension kept.
     */
    public static function cleanName(string $name): string
    {
        $name = str_replace('\\', '/', mb_scrub($name, 'UTF-8'));
        $slash = strrpos($name, '/');
        if ($slash !== false) {
            $name = substr($name, $slash + 1);
        }
        $name = (string) preg_replace('/[\x00-\x1F\x7F"<>|*?:\p{Cf}]+/u', '', $name);
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return 'tep';
        }
        if (mb_strlen($name) <= 255) {
            return $name;
        }
        $extension = self::extension($name);
        if ($extension === null || mb_strlen($extension) > 20) {
            return mb_substr($name, 0, 255);
        }

        return mb_substr($name, 0, 254 - mb_strlen($extension)).'.'.$extension;
    }

    private static function looksLike(string $extension, string $path): bool
    {
        $head = self::head($path);
        if ($head === '') {
            return false;
        }

        return match ($extension) {
            'step', 'stp' => str_starts_with(ltrim(self::withoutBom($head)), 'ISO-10303-21;'),
            'iges', 'igs' => self::isIges($head),
            'dwg' => preg_match('/\AAC10\d\d/', $head) === 1,
            'dxf' => self::isDxf($head),
            'pdf' => str_starts_with($head, '%PDF-'),
            'doc' => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'docx' => str_starts_with($head, "PK\x03\x04") && self::isDocx($path),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'png' => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
            default => false,
        };
    }

    private static function head(string $path): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        try {
            $head = fread($handle, self::HEAD_BYTES);
        } finally {
            fclose($handle);
        }

        return is_string($head) ? $head : '';
    }

    private static function withoutBom(string $head): string
    {
        return str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head;
    }

    /** The first record of an IGES file: 72 columns of text, `S` in column 73, its sequence number in 74–80. */
    private static function isIges(string $head): bool
    {
        return strlen($head) >= 80 && $head[72] === 'S' && preg_match('/\A *\d+\z/', substr($head, 73, 7)) === 1;
    }

    /** An ASCII DXF opens a section (after an optional 999 comment); a binary one carries its sentinel. */
    private static function isDxf(string $head): bool
    {
        if (str_starts_with($head, "AutoCAD Binary DXF\r\n\x1A\x00")) {
            return true;
        }

        return preg_match('/\A[ \t]*(?:999\r?\n[^\n]*\n[ \t]*)?0\r?\nSECTION\r?\n/', self::withoutBom($head)) === 1;
    }

    private static function isDocx(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            if ($zip->numFiles < 2 || $zip->numFiles > self::DOCX_MAX_ENTRIES) {
                return false;
            }
            if ($zip->locateName('word/document.xml') === false || $zip->locateName('[Content_Types].xml') === false) {
                return false;
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if (is_string($entry) && str_ends_with(mb_strtolower($entry), 'vbaproject.bin')) {
                    return false;
                }
            }
            // a macro part under another name still has to be declared as one
            $types = $zip->getFromName('[Content_Types].xml', 1024 * 1024);
            if (! is_string($types) || stripos($types, 'macroEnabled') !== false || stripos($types, 'vbaProject') !== false) {
                return false;
            }

            return true;
        } finally {
            $zip->close();
        }
    }
}
