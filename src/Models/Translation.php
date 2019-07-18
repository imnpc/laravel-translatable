<?php

namespace SolutionForest\Translatable\Models;

use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;

class Translation extends Model
{
    protected $table = "sf_translations";

    protected $fillable = ['lang', 'content', 'searchable'];

    public static function getCacheKeyByOneLanguage($model)
    {
        return 'translation_' . $model->translatable_id . '_' . $model->translatable_type . '_' . $model->lang . '_' . $model->content_key;
    }
    public static function getCacheKeyByOneLanguageFromValue($translatable_id, $translatable_type, $lang, $content_key)
    {
        return 'translation_' . $translatable_id . '_' . $translatable_type . '_' . $lang . '_' . $content_key;
    }

    public static function getCacheKey($model)
    {
        return 'translation_' . $model->translatable_id . '_' . $model->translatable_type . '_' . $model->content_key;
    }
    public static function getCacheKeyFromValue($translatable_id, $translatable_type, $content_key)
    {
        return 'translation_' . $translatable_id . '_' . $translatable_type . '_' . $content_key;
    }

    public static function boot()
    {
        parent::boot();

        static::updated(function ($model) {
            Cache::forget(self::getCacheKey($model));
            Cache::forget(self::getCacheKeyByOneLanguage($model));
        });

        static::created(function ($model) {
            Cache::forget(self::getCacheKey($model));
            Cache::forget(self::getCacheKeyByOneLanguage($model));
        });
    }
}
