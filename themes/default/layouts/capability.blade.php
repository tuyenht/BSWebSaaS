{{--
    A capability post (the detail layout a category picks as "capability"): the standard post page
    without a table of contents, without the links to the previous and next post and without related
    cards, so the page closes after its own content (the last question, or the last block) and the
    blocks of the `after_post` slot. The side column is whatever the `post_aside` slot holds for the
    category (the other capabilities, the consult box, a document card).
--}}
@include('theme::layouts.post', ['sfPlain' => true])
