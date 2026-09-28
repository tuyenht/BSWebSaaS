<?php

declare(strict_types=1);

namespace App\Modules\Cms\Validation;

/**
 * The rules one locale's content must pass to be written: the editor's save and a revision
 * restore read them from here, so nothing reaches entry_translations by one road that the other
 * would refuse. `blocks` is the body's block list, checked against the component registry
 * (ValidPageJson).
 */
final readonly class EntryContentRules
{
    public const string SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(private ValidPageJson $blocks) {}

    /**
     * @return array{title: list<string>, slug: list<string>, blocks: list<ValidPageJson>}
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:200', 'regex:'.self::SLUG_PATTERN],
            'blocks' => [$this->blocks],
        ];
    }
}
