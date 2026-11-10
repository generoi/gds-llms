<?php

/**
 * Polylang's pll_get_term_translations(), answering from
 * $GLOBALS['pll_term_translations'] (term id => [language => term id]).
 * Only this function: Polylang::isActive() keys on pll_languages_list(), so
 * the other tests still run as a site without Polylang.
 *
 * @return array<string, int>
 */
function pll_get_term_translations(int $term_id): array
{
    return $GLOBALS['pll_term_translations'][$term_id] ?? [];
}
