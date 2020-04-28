<?php

namespace Whitecube\Sluggable;

use Illuminate\Routing\Route;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Support\Facades\Route as Router;

trait HasSlug
{
    /**
     * Register the saving event callback
     */
    public static function bootHasSlug()
    {
        static::saving(function($model) {
            $attribute = $model->getSlugStorageAttribute();

            if($model->attributeIsTranslatable($attribute)) {
                $model->translateSlugs($attribute);
            } else {
                if(!is_null($model->$attribute)) return;

                $sluggable = $model->getSluggable();
                $model->attributes[$attribute] = str_slug($model->$sluggable);
            }
        });
    }

    /**
     * Generate translated slugs. Keeps any existing translated slugs.
     *
     * @param string $attribute
     */
    public function translateSlugs($attribute)
    {
        $sluggable = $this->getSluggable();
        $value = isset($this->attributes[$attribute])
            ? json_decode($this->attributes[$attribute], true)
            : [];

        foreach($this->getTranslatedLocales($this->getSluggable()) as $locale) {
            if(!isset($value[$locale]) || is_null($value[$locale])) {
                $value[$locale] = str_slug($this->getTranslation($sluggable, $locale));
            }
        }

        $this->attributes[$attribute] = json_encode($value);
    }

    /**
     * Get the attribute name used to generate the slug from
     *
     * @return string
     */
    public function getSluggable()
    {
        return $this->sluggable;
    }

    /**
     * Get the attribute name used to store the slug into
     *
     * @return string
     */
    public function getSlugStorageAttribute()
    {
        return $this->slugStorageAttribute ?? 'slug';
    }

    /**
     * Check if the model has the HasTranslations trait.
     *
     * @return bool
     */
    protected function hasTranslatableTrait()
    {
        return method_exists($this, 'getTranslations');
    }

    /**
     * Check if the given attribute is translatable.
     *
     * @param string $attribute
     * @return bool
     */
    protected function attributeIsTranslatable($attribute)
    {
        return $this->hasTranslatableTrait() && $this->isTranslatableAttribute($attribute);
    }

    /**
     * Override the route key name to allow proper
     * Route-Model binding.
     *
     * @return string
     */
    public function getRouteKeyName()
    {
        return $this->getSlugStorageAttribute();
    }

    /**
     * Generate an URI containing the model's slug from
     * given route.
     *
     * @param Illuminate\Routing\Route $route
     * @param null|string $locale
     * @param bool $fullUrl
     * @return string
     */
    public function getSluggedUrlForRoute(Route $route, $locale = null, $fullUrl = true)
    {
        $parameters = $this->getTranslatedSlugRouteParameters($route, $locale);

        return app(UrlGenerator::class)->toRoute($route, $parameters, $fullUrl);
    }

    /**
     * Get a bound route's parameters with the
     * model's slug set to the desired locale.
     *
     * @param Illuminate\Routing\Route $route
     * @param null|string $locale
     * @return array
     */
    public function getTranslatedSlugRouteParameters(Route $route, $locale = null)
    {
        $parameters = $route->signatureParameters(UrlRoutable::class);

        $parameter = array_reduce($parameters, function($carry, $parameter) {
            if($carry || $parameter->getClass()->name !== get_class()) return $carry;
            return $parameter;
        });

        if(!$parameter) {
            return $route->parameters();
        }

        $key = $this->getRouteKeyName();

        $value = ($this->attributeIsTranslatable($key) && $locale)
            ? $this->getTranslation($key, $locale)
            : $this->$key;

        $route->setParameter($parameter->name, $value);

        return $route->parameters();
    }

    /**
     * Resolve the route binding with the translatable slug in mind
     *
     * @param $value
     * @return mixed
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $key = $this->getRouteKeyName();

        if(!$this->attributeIsTranslatable($key)) {
            return parent::resolveRouteBinding($value);
        }

        $locale = app()->getLocale();

        // Return exact match if we find it
        if($result = $this->where($key . '->' . $locale, $value)->first()) {
            return $result;
        }

        // If cross-lang redirects are disabled, stop here
        if(!($this->slugTranslationRedirect ?? true)) {
            return;
        }

        // Get the models where this slug exists in other langs as well
        $results = $this->whereRaw('JSON_SEARCH(`'.$key.'`, "one", "'.$value.'")')->get();

        // If we have zero or multiple results, don't guess
        if($results->count() !== 1) {
            return;
        }

        // Redirect to the current route using the translated model key
        return abort(301, '', ['Location' => $results->first()->getSluggedUrlForRoute(
            Router::current(), $locale, false
        )]);
    }
}