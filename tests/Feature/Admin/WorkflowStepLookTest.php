<?php

use App\Modules\Admin\Support\Entries\WorkflowStepLook;
use App\Modules\Workflow\Models\WorkflowState;

/*
 * How a workflow step looks in «Hộp duyệt»: a step key the design names keeps its own colour, a step a
 * website named itself takes the colour of what it does, and the icon always follows what it does.
 */

function lookState(array $flags): WorkflowState
{
    return (new WorkflowState)->forceFill($flags + ['is_initial' => false, 'is_published' => false, 'is_terminal' => false]);
}

it('keeps the colour of a step key the design names', function (string $key): void {
    expect(WorkflowStepLook::tone(lookState([]), $key))->toBe($key);
})->with(['published', 'scheduled', 'pending', 'reviewing', 'draft', 'private', 'archived']);

it('colours a step a website named itself by what it does', function (array $flags, string $tone): void {
    expect(WorkflowStepLook::tone(lookState($flags), 'buoc_rieng'))->toBe($tone);
})->with([
    'goes live' => [['is_published' => true], 'published'],
    'first step' => [['is_initial' => true], 'draft'],
    'closing step' => [['is_terminal' => true], 'archived'],
    'a review step' => [[], 'reviewing'],
]);

it('reads an unknown step with no configuration as a review step', function (): void {
    expect(WorkflowStepLook::tone(null, 'mat_cau_hinh'))->toBe('reviewing')
        ->and(WorkflowStepLook::icon(null))->toBe('ri-user-search');
});

it('picks the node icon by what the step does', function (array $flags, string $icon): void {
    expect(WorkflowStepLook::icon(lookState($flags)))->toBe($icon);
})->with([
    'goes live' => [['is_published' => true], 'ri-global'],
    'first step' => [['is_initial' => true], 'ri-draft'],
    'closing step' => [['is_terminal' => true], 'ri-archive'],
    'a review step' => [[], 'ri-user-search'],
]);
