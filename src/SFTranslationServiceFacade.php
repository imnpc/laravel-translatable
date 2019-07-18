<?php

namespace SolutionForest\Translatable;

use Illuminate\Support\Facades\Facade;

class SFTranslationServiceFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'Translation-package';
    }
}
