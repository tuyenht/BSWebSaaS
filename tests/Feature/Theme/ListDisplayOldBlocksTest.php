<?php

use Illuminate\Support\Facades\View;

/*
 * A list block saved before the display group (no `layout` key) draws the element tree it always did.
 * Each expected tree below was checked equal, byte for byte, against the output of the templates as
 * they were before the group existed; the tree is what stays locked here.
 */

/** The element tree of some HTML: one line per element, indented by depth, tag and classes only. */
function oldBlockTree(string $html): string
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>');
    $lines = [];
    $walk = function (DOMNode $node, int $depth) use (&$walk, &$lines): void {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $lines[] = str_repeat(' ', $depth).$child->tagName.($child->getAttribute('class') !== '' ? '.'.str_replace(' ', '.', $child->getAttribute('class')) : '');
                $walk($child, $depth + 1);
            }
        }
    };
    $walk($dom->getElementsByTagName('body')->item(0), 0);

    return implode("\n", $lines);
}

/** @return list<array<string, string>> */
function oldBlockCards(): array
{
    return array_map(fn (int $i): array => ['title' => "Bài {$i}", 'url' => "/b-{$i}", 'image' => '', 'srcset' => null, 'icon' => '', 'excerpt' => 'x', 'date' => '', 'datetime' => '', 'label' => '', 'sector' => 'ng', 'short_outcome' => 'kq'], [1, 2, 3]);
}

/** @return array<string, array{0: string, 1: array<string, mixed>}> */
function oldBlockCases(): array
{
    $cases = [
        'partners' => ['partners', ['props' => ['heading' => 'H', 'logos' => [['image_url' => 'https://f/1.webp', 'name' => 'A', 'url' => '/a'], ['image_url' => 'https://f/2.webp', 'name' => 'B']]], 'items' => [], 'variant' => 'list', 'display' => null]],
        'testimonial' => ['testimonial', ['props' => ['heading' => 'H', 'items' => [['quote' => 'q1', 'name' => 'n1'], ['quote' => 'q2', 'role' => 'r']]], 'items' => [], 'variant' => 'list', 'display' => null]],
        'team' => ['team', ['props' => ['heading' => 'H', 'members' => [['name' => 'm1', 'role' => 'r'], ['name' => 'm2']]], 'items' => [], 'variant' => 'list', 'display' => null]],
    ];
    foreach (['icon-cards', 'project-cards', 'news-cards', 'overlay', 'list', 'grid'] as $variant) {
        $cases['entry '.$variant] = ['entry_grid', ['props' => ['heading' => 'H', 'view_all_label' => 'Tất cả', 'view_all_url' => '/tat-ca', 'limit' => 3], 'items' => oldBlockCards(), 'variant' => $variant, 'display' => null]];
    }

    return $cases;
}

/** @return array<string, string> */
function oldBlockTrees(): array
{
    return [
        'partners' => <<<'TREE'
section.partners
 h2.partners__heading
 ul.partners__list
  li.partners__item
   a.partners__link
    img.partners__logo
  li.partners__item
   img.partners__logo
TREE,
        'testimonial' => <<<'TREE'
section.sf-band.sf-band--none.sf-quotes
 div.sf-container
  div.sf-section-head
   h2.sf-section-head__title
   span.sf-section-head__rule
  ul.sf-quotes__list.sf-quotes__list--grid-2.sf-quotes__list--n2
   li
    figure.sf-quote
     span.sf-quote__mark
      svg.sf-icon
       path
     blockquote.sf-quote__text
      p
     figcaption.sf-quote__by
      span.sf-quote__avatar.sf-quote__avatar--initial
      span.sf-quote__who
       span.sf-quote__name
   li
    figure.sf-quote
     span.sf-quote__mark
      svg.sf-icon
       path
     blockquote.sf-quote__text
      p
     figcaption.sf-quote__by
      span.sf-quote__who
       span.sf-quote__role
TREE,
        'team' => <<<'TREE'
section.sf-band.sf-band--none.sf-team
 div.sf-container
  div.sf-section-head
   h2.sf-section-head__title
   span.sf-section-head__rule
  ul.sf-team__list.sf-team__list--grid-3.sf-team__list--n2
   li.sf-member
    span.sf-member__photo.sf-member__photo--initial
    h3.sf-member__name
    p.sf-member__role
   li.sf-member
    span.sf-member__photo.sf-member__photo--initial
    h3.sf-member__name
TREE,
        'entry icon-cards' => <<<'TREE'
section.sf-band.sf-band--none.sf-cards.sf-cards--icon
 div.sf-container
  div.sf-section-head
   h2.sf-section-head__title
   span.sf-section-head__rule
  ul.sf-grid.sf-grid--4
   li
    article.sf-card.sf-card--icon
     h3.sf-card__title
      a.sf-card__link
     p.sf-card__text
     span.sf-card__more
      span.sf-card__arrow
   li
    article.sf-card.sf-card--icon
     h3.sf-card__title
      a.sf-card__link
     p.sf-card__text
     span.sf-card__more
      span.sf-card__arrow
   li
    article.sf-card.sf-card--icon
     h3.sf-card__title
      a.sf-card__link
     p.sf-card__text
     span.sf-card__more
      span.sf-card__arrow
  div.sf-view-all
   a.sf-view-all__link
TREE,
        'entry project-cards' => <<<'TREE'
section.sf-band.sf-band--none.sf-cards.sf-cards--project
 div.sf-container
  div.sf-section-head
   h2.sf-section-head__title
   span.sf-section-head__rule
  ul.sf-grid.sf-grid--4s
   li
    article.sf-card.sf-card--project
     div.sf-card__frame.sf-card__frame--8x5
      span.sf-card__shade
     div.sf-card__body
      h3.sf-card__title
       a.sf-card__link
      p.sf-card__text
   li
    article.sf-card.sf-card--project
     div.sf-card__frame.sf-card__frame--8x5
      span.sf-card__shade
     div.sf-card__body
      h3.sf-card__title
       a.sf-card__link
      p.sf-card__text
   li
    article.sf-card.sf-card--project
     div.sf-card__frame.sf-card__frame--8x5
      span.sf-card__shade
     div.sf-card__body
      h3.sf-card__title
       a.sf-card__link
      p.sf-card__text
  div.sf-view-all
   a.sf-view-all__link
TREE,
        'entry news-cards' => <<<'TREE'
section.sf-band.sf-band--none.sf-cards.sf-cards--news
 div.sf-container
  div.sf-section-head
   h2.sf-section-head__title
   span.sf-section-head__rule
  ul.sf-grid.sf-grid--4s
   li
    article.sf-card.sf-card--news
     div.sf-card__frame.sf-card__frame--8x5
     div.sf-card__body
      h3.sf-card__title
       a.sf-card__link
      p.sf-card__text
      span.sf-card__more
       span.sf-card__arrow
   li
    article.sf-card.sf-card--news
     div.sf-card__frame.sf-card__frame--8x5
     div.sf-card__body
      h3.sf-card__title
       a.sf-card__link
      p.sf-card__text
      span.sf-card__more
       span.sf-card__arrow
   li
    article.sf-card.sf-card--news
     div.sf-card__frame.sf-card__frame--8x5
     div.sf-card__body
      h3.sf-card__title
       a.sf-card__link
      p.sf-card__text
      span.sf-card__more
       span.sf-card__arrow
  div.sf-view-all
   a.sf-view-all__link
TREE,
        'entry overlay' => <<<'TREE'
section.sf-band.sf-band--none.sf-cards.sf-cards--product-overlay
 div.sf-container
  div.sf-section-head
   h2.sf-section-head__title
   span.sf-section-head__rule
  ul.sf-grid.sf-grid--3s
   li
    article.sf-card.sf-card--product-overlay
     div.sf-card__frame.sf-card__frame--4x3
      span.sf-card__shade
      span.sf-card__hover
       span.sf-card__eye
        svg.icon
         path
         path
      div.sf-card__caption
       h3.sf-card__title
        a.sf-card__link
   li
    article.sf-card.sf-card--product-overlay
     div.sf-card__frame.sf-card__frame--4x3
      span.sf-card__shade
      span.sf-card__hover
       span.sf-card__eye
        svg.icon
         path
         path
      div.sf-card__caption
       h3.sf-card__title
        a.sf-card__link
   li
    article.sf-card.sf-card--product-overlay
     div.sf-card__frame.sf-card__frame--4x3
      span.sf-card__shade
      span.sf-card__hover
       span.sf-card__eye
        svg.icon
         path
         path
      div.sf-card__caption
       h3.sf-card__title
        a.sf-card__link
  div.sf-view-all
   a.sf-view-all__link
TREE,
        'entry list' => <<<'TREE'
section.entry-grid
 h2.entry-grid__heading
 ul.entry-grid__list
  li.entry-grid__card
   h3.entry-grid__title
    a
   span.entry-grid__sector
   p.entry-grid__outcome
  li.entry-grid__card
   h3.entry-grid__title
    a
   span.entry-grid__sector
   p.entry-grid__outcome
  li.entry-grid__card
   h3.entry-grid__title
    a
   span.entry-grid__sector
   p.entry-grid__outcome
TREE,
        'entry grid' => <<<'TREE'
section.entry-grid
 h2.entry-grid__heading
 ul.entry-grid__list
  li.entry-grid__card
   h3.entry-grid__title
    a
   span.entry-grid__sector
   p.entry-grid__outcome
  li.entry-grid__card
   h3.entry-grid__title
    a
   span.entry-grid__sector
   p.entry-grid__outcome
  li.entry-grid__card
   h3.entry-grid__title
    a
   span.entry-grid__sector
   p.entry-grid__outcome
TREE,
    ];
}

it('draws a block saved before the display group with the element tree it always had', function (string $name): void {
    app()->setLocale('vi');
    [$view, $data] = oldBlockCases()[$name];

    expect(oldBlockTree(View::make('theme::sections.'.$view, $data)->render()))->toBe(oldBlockTrees()[$name]);
})->with(array_keys(oldBlockCases()));
