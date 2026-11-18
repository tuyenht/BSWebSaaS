<?php

/*
 * Vietnamese UI text is sentence case: only the first letter and proper nouns are capitalised.
 * This walks every shipped pack and flags block headings, labels and titles written in English-style
 * Title Case ("Yêu Cầu Báo Giá"). Proper nouns a pack may capitalise are listed in its pack.json
 * under "proper_nouns" (the loader ignores that key).
 */

/**
 * @param  array<string, mixed>|list<mixed>  $node
 * @return list<array{path: string, value: string}> every string under a "vi" key
 */
function scVietnameseStrings(array $node, string $path = '', bool $inVi = false): array
{
    $found = [];
    foreach ($node as $key => $value) {
        $here = $path === '' ? (string) $key : $path.'.'.$key;
        $vi = $inVi || $key === 'vi';
        if (is_array($value)) {
            $found = [...$found, ...scVietnameseStrings($value, $here, $vi)];
        } elseif (is_string($value) && $vi) {
            $found[] = ['path' => $here, 'value' => $value];
        }
    }

    return $found;
}

/** @param list<string> $properNouns */
function scIsTitleCase(string $text, array $properNouns): bool
{
    if (mb_strlen($text) > 80 || $text === mb_strtoupper($text)) {
        return false;
    }
    usort($properNouns, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
    foreach ($properNouns as $noun) {
        $text = str_replace($noun, ' ', $text);
    }
    $words = [];
    foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
        $word = trim($word, '.,;:!?()"“”‘’–-&');
        if ($word === '' || $word === mb_strtoupper($word) || preg_match('/[0-9\/]/', $word) === 1) {
            continue;
        }
        $words[] = $word;
    }
    if (count($words) < 2) {
        return false;
    }
    foreach (array_slice($words, 1) as $word) {
        if (mb_substr($word, 0, 1) !== mb_strtoupper(mb_substr($word, 0, 1))) {
            return false;
        }
    }

    return true;
}

/** The key a string sits under, skipping the locale and list positions ("links.2.label" → label, "license_issuer.vi" → license_issuer). */
function scLabelKey(string $path): string
{
    $segments = array_values(array_filter(explode('.', $path), fn (string $s): bool => $s !== 'vi' && ! ctype_digit($s)));

    return (string) end($segments);
}

/** @return array<string, array<string, mixed>> pack folder → decoded files */
function scPackFiles(): array
{
    $packs = [];
    foreach (glob(base_path('database/content-packs/*/*.json')) ?: [] as $file) {
        $packs[basename(dirname($file))][basename($file)] = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    return $packs;
}

it('writes Vietnamese headings, labels and titles in sentence case', function (): void {
    $hits = [];
    foreach (scPackFiles() as $pack => $files) {
        $nouns = array_values(array_filter($files['pack.json']['proper_nouns'] ?? [], 'is_string'));
        foreach ($files as $name => $data) {
            if ($name === 'pack.json') {
                continue;
            }
            foreach (scVietnameseStrings($data) as $item) {
                $key = scLabelKey($item['path']);
                if (! in_array($key, ['heading', 'label', 'title', 'tips', 'license_issuer'], true) && ! str_ends_with($key, '_label')) {
                    continue;
                }
                if (scIsTitleCase($item['value'], $nouns)) {
                    $hits[] = "{$pack}/{$name} › {$item['path']} › {$item['value']}";
                }
            }
        }
    }

    expect($hits)->toBe([], "Title Case found; write it in sentence case:\n".implode("\n", $hits));
});

it('stores every pack string in Unicode NFC', function (): void {
    $bad = [];
    $walk = function (mixed $node, string $where) use (&$walk, &$bad): void {
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                $walk($value, $where.'.'.$key);
            }
        } elseif (is_string($node) && ! Normalizer::isNormalized($node, Normalizer::FORM_C)) {
            $bad[] = "{$where} › {$node}";
        }
    };
    foreach (scPackFiles() as $pack => $files) {
        foreach ($files as $name => $data) {
            $walk($data, "{$pack}/{$name}");
        }
    }

    expect($bad)->toBe([], "Not NFC:\n".implode("\n", $bad));
});

it('catches a Title Case heading', function (): void {
    expect(scIsTitleCase('Yêu Cầu Báo Giá', []))->toBeTrue()
        ->and(scIsTitleCase('Yêu cầu báo giá', []))->toBeFalse()
        ->and(scIsTitleCase('Quy trình ISO 9001 Cho An Vinh', ['An Vinh']))->toBeFalse()
        ->and(scIsTitleCase('Video Xưởng', []))->toBeTrue()
        ->and(scIsTitleCase('Sở Tài Chính Hà Nội', []))->toBeTrue()
        ->and(scIsTitleCase('Sở Tài chính Hà Nội', []))->toBeFalse()
        ->and(scIsTitleCase('Chat Zalo', ['Zalo']))->toBeFalse()
        ->and(scIsTitleCase('Cơ Khí Chính Xác An Vinh', ['An Vinh', 'Cơ Khí Chính Xác An Vinh']))->toBeFalse();
});
