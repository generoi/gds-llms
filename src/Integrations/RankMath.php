<?php

namespace GeneroWP\Llms\Integrations;

class RankMath
{
    public static function isActive(): bool
    {
        return class_exists('RankMath', false);
    }

    /**
     * Rank Math instantiates RankMath\LLMS\LLMS_Txt only when its `llms-txt`
     * module is switched on, and registers the same `^llms\.txt$` rule. Where it
     * does, llms.txt stands down entirely rather than race it for the rule.
     */
    public static function handlesLlmsTxt(): bool
    {
        return class_exists('RankMath\\LLMS\\LLMS_Txt', false);
    }
}
