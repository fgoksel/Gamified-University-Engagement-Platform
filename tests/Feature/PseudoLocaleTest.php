<?php

namespace Tests\Feature;

use App\Support\PseudoLocale;
use Tests\TestCase;

/**
 * The made-up test language (Task #95): what it changes, and what it must leave alone so the
 * texts still work.
 */
class PseudoLocaleTest extends TestCase
{
    public function test_words_are_accented_and_wrapped_in_brackets(): void
    {
        $this->assertSame('⟦Šáṽé⟧', PseudoLocale::convert('Save'));
        $this->assertSame('⟦Ƥáĝé 2 óƒ 5.⟧', PseudoLocale::convert('Page 2 of 5.'));
    }

    public function test_placeholders_tags_and_entities_stay_as_they_are(): void
    {
        $this->assertSame('⟦Ĥéłłó :name!⟧', PseudoLocale::convert('Hello :name!'));
        $this->assertSame('⟦Ţĥé :attribute ƒíéłđ.⟧', PseudoLocale::convert('The :attribute field.'));
        $this->assertSame('⟦Ƒíłţéŕíñĝ ƀý <strong>:title</strong> &amp; ɱóŕé⟧', PseudoLocale::convert('Filtering by <strong>:title</strong> &amp; more'));
    }

    public function test_every_form_of_a_plural_text_keeps_its_numbers(): void
    {
        $this->assertSame(
            '{1} ⟦:count ƥóíñţ⟧|[0,*] ⟦:count ƥóíñţš⟧',
            PseudoLocale::convert('{1} :count point|[0,*] :count points'),
        );
    }

    public function test_groups_of_texts_keep_their_shape(): void
    {
        $this->assertSame(
            ['min' => ['string' => '⟦Ţóó šĥóŕţ.⟧'], 'count' => 3, 'on' => true],
            PseudoLocale::lines(['min' => ['string' => 'Too short.'], 'count' => 3, 'on' => true]),
        );
    }

    public function test_the_translator_gives_made_up_texts_for_the_language_only(): void
    {
        $this->assertSame('The email field is required.', __('validation.required', ['attribute' => 'email']));

        app()->setLocale('qps');

        // The texts of Laravel and of the admin panel change too, not only the ones of this project.
        $this->assertSame('⟦Ţĥé email ƒíéłđ íš ŕéɋúíŕéđ.⟧', __('validation.required', ['attribute' => 'email']));
        $this->assertSame(PseudoLocale::convert(__('filament-panels::layout.actions.logout.label', [], 'en')), __('filament-panels::layout.actions.logout.label'));
        $this->assertSame(PseudoLocale::convert('You received 1 point'), trans_choice('notifications.feed.points.title', 1));
    }
}
