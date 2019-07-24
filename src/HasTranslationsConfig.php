<?php

namespace SolutionForest\Translatable;

class HasTranslationsConfig
{
    public static $defaultLocale = 'zh-TW';
    public static $modifyToArrayAttributes = false;
    public static $disableCache = false;

    public static function setModifyToArrayAttributes($canModify)
    {
        self::$modifyToArrayAttributes = $canModify;
    }


    public static function setDefaultLocale($locale)
    {
        self::$defaultLocale = $locale;
    }


    public static function setDisableCache($cache)
    {
        self::$disableCache = $cache;
    }
}
