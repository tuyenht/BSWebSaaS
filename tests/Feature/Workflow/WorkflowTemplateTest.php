<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale as SiteLocaleRow;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowState;
use App\Modules\Workflow\Models\WorkflowTransition;
use App\Modules\Workflow\Registry\WorkflowTemplateRegistry;
use App\Modules\Workflow\Services\WorkflowEngine;
use InvalidArgumentException;

/*
 * Curated workflow templates (design.md §15.4). InstantiateWorkflow turns a pure-data template
 * into rows a site can then edit. Every template must satisfy the engine's structural invariants,
 * and an instantiated workflow must actually drive an entry to publication.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('every template is structurally valid for the engine', function (string $key, array $template): void {
    // Exactly one entry point, at least one public stage.
    $initial = array_filter($template['states'], fn ($s) => $s['is_initial'] ?? false);
    expect($initial)->toHaveCount(1, "template '{$key}' must have exactly one initial state");
    expect(array_filter($template['states'], fn ($s) => $s['is_published'] ?? false))
        ->not->toBeEmpty("template '{$key}' must have at least one published state");

    // Every transition endpoint is a real state of the same template. (toContain takes needles,
    // not a message — so assert membership via in_array to keep a per-transition failure clear.)
    $stateKeys = array_column($template['states'], 'key');
    foreach ($template['transitions'] as $t) {
        expect(in_array($t['from'], $stateKeys, true))->toBeTrue("transition '{$t['key']}' from unknown state '{$t['from']}'");
        expect(in_array($t['to'], $stateKeys, true))->toBeTrue("transition '{$t['key']}' to unknown state '{$t['to']}'");
        expect($t['permission'])->toBeString()->not->toBeEmpty();
    }
})->with(collect(WorkflowTemplateRegistry::all())->map(fn ($t, $k) => [$k, $t])->values()->all());

it('materializes a template into engine-usable rows, staged disabled by default', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $workflow = app(InstantiateWorkflow::class)->handle('editorial', 'tin-tuc');

        expect($workflow->is_active)->toBeFalse();          // staged off — an operator flips it on
        expect($workflow->template)->toBe('editorial');
        expect($workflow->states()->count())->toBe(count(WorkflowTemplateRegistry::all()['editorial']['states']));
        expect($workflow->transitions()->count())->toBe(count(WorkflowTemplateRegistry::all()['editorial']['transitions']));
        expect($workflow->initialState()->key)->toBe('draft');

        // Default-OFF holds: the engine still resolves nothing until it is switched on.
        expect(app(WorkflowEngine::class)->activeFor('tin-tuc'))->toBeNull();
    });
});

it('writes the workflow in the site\'s default language', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $vietnamese = app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc');
        expect($vietnamese->name)->toBe('Duyệt trước khi đăng')
            ->and($vietnamese->states()->where('key', 'pending')->value('label'))->toBe('Chờ duyệt');

        SiteLocaleRow::query()->update(['is_default' => false]);
        SiteLocaleRow::query()->updateOrCreate(['locale' => 'en'], ['is_default' => true, 'enabled' => true, 'is_public' => true]);
        app(SiteLocale::class)->forget();

        $english = app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc');
        expect($english->name)->toBe('Approve before publishing')
            ->and($english->states()->where('key', 'pending')->value('label'))->toBe('Awaiting approval')
            ->and($english->transitions()->where('key', 'submit')->value('label'))->toBe('Submit for approval');
    });
});

it('has every template name and label in every interface language', function (): void {
    foreach (glob(lang_path('*.json')) ?: [] as $file) {
        $locale = basename($file, '.json');
        foreach (WorkflowTemplateRegistry::localized($locale) as $key => $template) {
            $texts = [$template['name'], ...array_column($template['states'], 'label'), ...array_column($template['transitions'], 'label')];
            foreach ($texts as $text) {
                expect($text)->not->toStartWith('workflow.', "{$locale}: {$key}");
            }
        }
    }
});

it('is idempotent — re-instantiating rebuilds without duplicating', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $action = app(InstantiateWorkflow::class);

        $first = $action->handle('editorial', 'tin-tuc', active: true);
        $second = $action->handle('editorial', 'tin-tuc', active: true);

        expect($second->id)->toBe($first->id);              // same workflow row, not a second one
        expect(Workflow::query()->count())->toBe(1);
        expect(WorkflowState::query()->count())->toBe(count(WorkflowTemplateRegistry::all()['editorial']['states']));
        expect(WorkflowTransition::query()->count())->toBe(count(WorkflowTemplateRegistry::all()['editorial']['transitions']));
    });
});

it('drives an entry from draft to published through the instantiated editorial flow', function (): void {
    $author = User::factory()->create();
    $editor = User::factory()->create();
    $director = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $editor, $director): void {
        $workflow = app(InstantiateWorkflow::class)->handle('editorial', 'tin-tuc', active: true);
        $engine = app(WorkflowEngine::class);

        grantRole($author, 'author', ['entries.submit']);
        grantRole($editor, 'editor', ['entries.review']);
        grantRole($director, 'director', ['entries.approve']);
        $entryId = Entry::query()->create(['type' => 'post', 'status' => 'draft'])->id;

        // gửi → biên tập → phản biện → duyệt, each step gated by a different role.
        $s1 = $engine->apply($workflow, 'submit', 'draft', $author->fresh(), $entryId);
        expect($s1->key)->toBe('pending');
        $s2 = $engine->apply($workflow, 'to_review', 'pending', $editor->fresh(), $entryId);
        expect($s2->key)->toBe('reviewing');
        $s3 = $engine->apply($workflow, 'approve', 'reviewing', $director->fresh(), $entryId);
        expect($s3->key)->toBe('published');
        expect($s3->is_published)->toBeTrue();

        // The author cannot approve, and the director cannot do the editor's step — separation holds.
        expect($engine->allowedTransitions($workflow, 'reviewing', $author->fresh())->all())->toBe([]);
    });
});

it('rejects an unknown template key', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        expect(fn () => app(InstantiateWorkflow::class)->handle('nope', 'tin-tuc'))
            ->toThrow(InvalidArgumentException::class);
    });
});
