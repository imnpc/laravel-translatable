<?php

namespace SolutionForest\Translatable;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;
use SolutionForest\Translatable\Events\TranslationHasBeenSet;
use SolutionForest\Translatable\Exceptions\AttributeIsNotTranslatable;
use SolutionForest\Translatable\Models\Translation;

trait HasTranslations
{
    public $newTranslations;

//    public function __construct()
//    {
//        parent::__construct();
//        $this->with[] = 'translation_relation';
//    }

    public function translation_relation()
    {
        return $this->morphMany('SolutionForest\Translatable\Models\Translation', 'translatable');
    }


    public function pushNewTranslation($key, $isSearchable, $content, $locale)
    {
        if (!$this->newTranslations) {
            $this->newTranslations = new Collection();
        }
        $this->newTranslations->push([
            'key' => $key,
            'isSearchable' => $isSearchable,
            'content' => is_array($content) ? json_encode($content) : $content,
            'locale' => $locale,
        ]);
    }

    public function getAttributeValue($key)
    {
        if (!$this->isTranslatableAttribute($key)) {
            return parent::getAttributeValue($key);
        }

        $value = $this->getTranslation($key, $this->getLocale());

        return $value ?? parent::getAttributeValue($key);
    }

    public function setAttribute($key, $value)
    {
        // Pass arrays and untranslatable attributes to the parent method.
        if (!$this->isTranslatableAttribute($key)) {
            return parent::setAttribute($key, $value);
        }

        if (is_array($value)) {
            $this->setTranslations($key, $value);
        }else{
            // If the attribute is translatable and not already translated, set a
            // translation for the current app locale.
            $this->setTranslation($key, $this->getLocale(), $value);
        }

        $value = $this->getTranslation($key, $this->getLocale());

        return parent::setAttribute($key, $value);
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

        if (HasTranslationsConfig::$disableCache) {
            Cache::forget($cacheKey);
        }

        return Cache::rememberForever($cacheKey, function () use ($key, $lang) {
            if($obj = $this->translation_relation->where('content_key', $key)->where('lang',$lang)){
                return $obj->first();
            }


            if ($t = Translation::where('translatable_id', $this->id)
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

        if (HasTranslationsConfig::$disableCache) {
            Cache::forget($cacheKey);
        }


        return Cache::rememberForever($cacheKey, function () use ($key) {

            if($obj = $this->translation_relation->where('content_key', $key)){
                return $obj->map(function ($item) use ($key) {
                    $content = $item->content ?? '';
                    if ($item->lang == HasTranslationsConfig::$defaultLocale && empty($content)) {
                        $content = $this->{$key} ?? '';
                    }
                    return ['lang' => $item->lang, 'value' => $content];
                })
                    ->pluck('value', 'lang')
                    ->toArray();
            }

            $t = Translation::where('translatable_id', $this->id)
                ->where('translatable_type', self::class)
                ->where('content_key', $key)
                ->get();

            return count($t) > 0 ? $t->map(function ($item) use ($key) {
                $content = $item->content ?? '';
                if ($item->lang == HasTranslationsConfig::$defaultLocale && empty($content)) {
                    $content = $this->{$key} ?? '';
                }
                return ['lang' => $item->lang, 'value' => $content];
            })
                ->pluck('value', 'lang')
                ->toArray() : [config('app.locale') => parent::getAttributeValue($key)];
        });
    }

    public function getTranslations(string $key = null): array
    {
        if ($key !== null) {
            $this->guardAgainstNonTranslatableAttribute($key);
            $translations = $this->getAllTranslateContentByFieldKey($key);
            // ray([$key=>$this->getAttributes()]);

            if(($translations[config('app.locale')]??null) == null && array_key_exists($key,$this->getAttributes())){
                $translation = array_merge($translations,[config('app.locale')=>$this->getAttributes()[$key]]);
            }else{
                $translation = $translations;
            }
            return ($translation);
        }
        return array_reduce(
            $this->getTranslatableAttributes(),
            function ($result, $item) {
                $result[$item] = $this->getTranslations($item);
                return $result;
            }
        );
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
        if ($locale == config('app.locale') ?? HasTranslationsConfig::$defaultLocale) {
            $this->attributes[$key] = $value;

        }

        if (!$this->exists) {
            $this->pushNewTranslation($key, $this->isTranslateSearchableAttribute($key) ? 1 : 0, $value, $locale);
            return $this;
        }

        if ($oldValue !== $value && count($this->toArray()) != 0) {

            $cacheKey = Translation::getCacheKeyFromValue($this->id, self::class, $key);
            Cache::forget($cacheKey);
            $cacheKey = Translation::getCacheKeyByOneLanguageFromValue($this->id, self::class, $locale, $key);
            Cache::forget($cacheKey);


            $tModel = $this->getCurrentLocaleTranslateByFieldKey($key, $locale);
            $tModel->searchable = $this->isTranslateSearchableAttribute($key) ? 1 : 0;
            $tModel->content = is_array($value) ? json_encode($value) : $value;
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

    public function updateStackTranslation()
    {
        if ($this->newTranslations && $this->newTranslations->count() > 0) {

            $this->newTranslations->each(function ($item) {

                $t = new Translation();
                $t->translatable_id = $this->id;
                $t->translatable_type = self::class;
                $t->lang = $item['locale'];
                $t->searchable = $item['isSearchable'];
                $t->content_key = $item['key'];
                $t->content = $item['content'];
                $t->save();

                $cacheKey = Translation::getCacheKeyByOneLanguageFromValue($this->id, self::class, $item['locale'], $item['key']);
                Cache::forget($cacheKey);
            });
        }
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
        return Config::get('app.locale') ?? HasTranslationsConfig::$defaultLocale;
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

    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();

        if (HasTranslationsConfig::$modifyToArrayAttributes) {
            foreach ($attributes as $key => $v) {
                if ($this->isTranslatableAttribute($key)) {
                    $attributes[$key] = $this->getTranslations($key);
                }
            }
        } else {
            foreach ($attributes as $key => $v) {
                if ($this->isTranslatableAttribute($key)) {
                    $attributes[$key] = $this->getTranslation($key, $this->getLocale());
                }
            }
        }
        return $attributes;
    }

    /**
     * Save the model to the database.
     *
     * @param  array $options
     * @return bool
     */
    public function save(array $options = [])
    {
        $saved = parent::save($options);
        // For First time Translation, Current Model do not include ID ,
        // we need update it later.
        $this->updateStackTranslation();
        return $saved;
    }

    public function fromJson($value, $asObject = false)
    {
        return is_array($value) ? $value : json_decode($value, ! $asObject);
    }


}