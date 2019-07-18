<?php

namespace SolutionForest\Translatable;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;
use SolutionForest\Translatable\Events\TranslationHasBeenSet;
use SolutionForest\Translatable\Exceptions\AttributeIsNotTranslatable;
use SolutionForest\Translatable\Models\Translation;

trait HasTranslations
{

    public static $defaultLocale = 'zh-TW';

    public static function test($id, $key, $value)
    {
        $model = self::find($id);
        var_dump($model->{$key});
        $model->{$key} = $value;
        $model->save();
        var_dump($model->{$key});
    }

    public static function test2($id, $lang, $key, $value)
    {
        $model = self::find($id);
        $model->setTranslation($key, $lang, $value);
        $model->save();
    }

    public function translation_relation()
    {
        return $this->morphMany('App\Models\Translation', 'translatable');
    }

    public function getAttributeValue($key)
    {
        if (!$this->isTranslatableAttribute($key)) {
            return parent::getAttributeValue($key);
        }
        return $this->getTranslation($key, $this->getLocale());
    }

    public function setAttribute($key, $value)
    {
        // Pass arrays and untranslatable attributes to the parent method.
        if (!$this->isTranslatableAttribute($key) || is_array($value)) {
            return parent::setAttribute($key, $value);
        }
        // If the attribute is translatable and not already translated, set a
        // translation for the current app locale.
        return $this->setTranslation($key, $this->getLocale(), $value);
    }

    public function translate(string $key, string $locale = ''): string
    {
        return $this->getTranslation($key, $locale);
    }

    public function getTranslation(string $key, string $locale, bool $useFallbackLocale = true)
    {
        $locale = $this->normalizeLocale($key, $locale, $useFallbackLocale);
        $translations = $this->getTranslations($key);
        $translation = $translations[$locale] ?? '';
        if ($this->hasGetMutator($key)) {
            return $this->mutateAttribute($key, $translation);
        }
        return $translation;
    }

    public function getTranslationWithFallback(string $key, string $locale): string
    {
        return $this->getTranslation($key, $locale, true);
    }

    public function getTranslationWithoutFallback(string $key, string $locale)
    {
        return $this->getTranslation($key, $locale, false);
    }

    public function getCurrentLocaleTranslateByFieldKey($key, $lang = null): Translation
    {
        $cacheKey = Translation::getCacheKeyByOneLanguageFromValue($this->id, self::class, $lang ?? $this->getLocale(), $key);
        return Cache::rememberForever($cacheKey, function () use ($key, $lang) {
            if ($t =  Translation::where('translatable_id', $this->id)
                ->where('translatable_type', self::class)
                ->where('lang', $lang ?? $this->getLocale())
                ->where('content_key', $key)
                ->first()
            ) {
                return $t;
            } else {
                $t = new Translation();
                $t->translatable_id = $this->id;
                $t->translatable_type = self::class;
                $t->lang = $lang ?? $this->getLocale();
                $t->searchable = $this->isTranslateSearchableAttribute($key) ? 1 : 0;
                $t->content_key = $key;
                return $t;
            }
        });
    }

    public function getCurrentLocaleTranslateContentByFieldKey($key)
    {
        $t = $this->getCurrentLocaleTranslateByFieldKey($key);
        return $t ? $t->content : '';
    }

    public function getAllTranslateContentByFieldKey($key)
    {
        $cacheKey = Translation::getCacheKeyFromValue($this->id, self::class, $key);
        return Cache::rememberForever($cacheKey, function () use ($key) {
            $t = Translation::where('translatable_id', $this->id)
                ->where('translatable_type', self::class)
                ->where('content_key', $key)
                ->get();
            return $t ? $t->map(function ($item) {
                return ['lang' => $item->lang, 'value' => $item->content];
            })
                ->pluck('value', 'lang')
                ->toArray() : [];
        });
    }

    public function getTranslations(string $key = null): array
    {
        if ($key !== null) {
            $this->guardAgainstNonTranslatableAttribute($key);
            return $this->getAllTranslateContentByFieldKey($key);
        }
        return array_reduce($this->getTranslatableAttributes(), function ($result, $item) {
            $result[$item] = $this->getTranslations($item);
            return $result;
        });
    }

    public function setTranslation(string $key, string $locale, $value): self
    {
        $this->guardAgainstNonTranslatableAttribute($key);
        $translations = $this->getTranslations($key);
        $oldValue = $translations[$locale] ?? '';
        if ($this->hasSetMutator($key)) {
            $method = 'set' . Str::studly($key) . 'Attribute';
            $this->{$method}($value, $locale);
            $value = $this->attributes[$key];
        }
        $translations[$locale] = $value;
        if ($locale == $this::$defaultLocale) {
            $this->attributes[$key] = $value;
        }
        if ($oldValue !== $value) {
            $tModel = $this->getCurrentLocaleTranslateByFieldKey($key, $locale);
            $tModel->searchable = $this->isTranslateSearchableAttribute($key) ? 1 : 0;
            $tModel->content = $value;
            $tModel->save();
            event(new TranslationHasBeenSet($this, $key, $locale, $oldValue, $value));
        }
        return $this;
    }


    public function setTranslations(string $key, array $translations): self
    {
        $this->guardAgainstNonTranslatableAttribute($key);
        foreach ($translations as $locale => $translation) {
            $this->setTranslation($key, $locale, $translation);
        }
        return $this;
    }

    public function forgetTranslation(string $key, string $locale): self
    {
        Translation::where('translatable_id', $this->id)
            ->where('translatable_type', self::class)
            ->where('lang', $locale)
            ->where('content_key', $key)
            ->delete();
        $cacheKey = Translation::getCacheKeyFromValue($this->id, self::class, $key);
        Cache::forget($cacheKey);
        return $this;
    }

    public function forgetAllTranslations(string $locale): self
    {
        collect($this->getTranslatableAttributes())->each(function (string $attribute) use ($locale) {
            $this->forgetTranslation($attribute, $locale);
        });
        return $this;
    }

    public function getTranslatedLocales(string $key): array
    {
        return array_keys($this->getTranslations($key));
    }

    public function isTranslatableAttribute(string $key): bool
    {
        return in_array($key, $this->getTranslatableAttributes());
    }

    public function isTranslateSearchableAttribute(string $key): bool
    {
        return in_array($key, $this->getTranslateSearchableAttributes());
    }

    public function hasTranslation(string $key, string $locale = null): bool
    {
        $locale = $locale ?: $this->getLocale();
        return isset($this->getTranslations($key)[$locale]);
    }

    protected function guardAgainstNonTranslatableAttribute(string $key)
    {
        if (!$this->isTranslatableAttribute($key)) {
            throw AttributeIsNotTranslatable::make($key, $this);
        }
    }

    protected function normalizeLocale(string $key, string $locale, bool $useFallbackLocale): string
    {
        if (in_array($locale, $this->getTranslatedLocales($key))) {
            return $locale;
        }
        if (!$useFallbackLocale) {
            return $locale;
        }
        if (!is_null($fallbackLocale = Config::get('app.fallback_locale'))) {
            return $fallbackLocale;
        }
        return $locale;
    }

    protected function getLocale(): string
    {
        return Config::get('app.locale') ?? self::$defaultLocale;
    }

    public function getTranslatableAttributes(): array
    {
        return is_array($this->translatable)
            ? $this->translatable
            : [];
    }

    public function getTranslateSearchableAttributes(): array
    {
        return is_array($this->translate_searchable)
            ? $this->translate_searchable
            : [];
    }

    public function getTranslationsAttribute(): array
    {
        return collect($this->getTranslatableAttributes())
            ->mapWithKeys(function (string $key) {
                return [$key => $this->getTranslations($key)];
            })
            ->toArray();
    }
}
