<?php

namespace SolutionForest\Translatable;

class HasTranslationsConfig
{
    public static $defaultLocale = 'zh-TW';
    public static $modifyToArrayAttributes = false;

    public static function setModifyToArrayAttributes($canModify)
    {
        self::$modifyToArrayAttributes = $canModify;
    }

    public static function setDeafultLocale($locale)
    {
        self::$defaultLocale = $locale;
    }
}
