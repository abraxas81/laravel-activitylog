<?php

namespace Spatie\Activitylog\Traits;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Activitylog\Exceptions\CouldNotLogChanges;

trait DetectsChanges
{
    protected $oldAttributes = [];

    protected static function bootDetectsChanges()
    {
        $testing = static::eventsToBeRecorded();

        if (static::eventsToBeRecorded()->contains('updated')) {
            static::updating(function (Model $model) {
                static::handleOldValues($model);
            });

        }
        if (static::eventsToBeRecorded()->contains('pivotAttached')) {
            static::pivotAttaching(function (Model $model) {
                static::handleOldValues($model);
            });
        }

        if (static::eventsToBeRecorded()->contains('pivotDetached')) {
            static::pivotDetaching(function (Model $model) {
                static::handleOldValues($model);
            });
        }

        if (static::eventsToBeRecorded()->contains('pivotUpdated')) {
            static::pivotUpdating(function (Model $model) {
                static::handleOldValues($model);
            });
        }

    }

    private static function handleOldValues(Model $model)
    {
        //temporary hold the original attributes on the model
        //as we'll need these in the updating event
        $oldValues = (new static)->setRawAttributes($model->getOriginal());

        $model->oldAttributes = static::logChanges($oldValues);
    }

    public function attributesToBeLogged(): array
    {
        $attributes = [];

        if (isset(static::$logFillable) && static::$logFillable) {
            $attributes = array_merge($attributes, $this->getFillable());
        }

        if ($this->shouldLogUnguarded()) {
            $attributes = array_merge($attributes, array_diff(array_keys($this->getAttributes()), $this->getGuarded()));
        }

        if (isset(static::$logAttributes) && is_array(static::$logAttributes)) {
            $attributes = array_merge($attributes, array_diff(static::$logAttributes, ['*']));

            if (in_array('*', static::$logAttributes)) {
                $attributes = array_merge($attributes, array_keys($this->getAttributes()));
            }
        }

        if (isset(static::$logAttributesToIgnore) && is_array(static::$logAttributesToIgnore)) {
            $attributes = array_diff($attributes, static::$logAttributesToIgnore);
        }

        return $attributes;
    }

    public function shouldLogOnlyDirty(): bool
    {
        if (! isset(static::$logOnlyDirty)) {
            return false;
        }

        return static::$logOnlyDirty;
    }

    public function shouldLogUnguarded(): bool
    {
        if (! isset(static::$logUnguarded)) {
            return false;
        }

        if (! static::$logUnguarded) {
            return false;
        }

        if (in_array('*', $this->getGuarded())) {
            return false;
        }

        return true;
    }

    public function attributeValuesToBeLogged(string $processingEvent): array
    {
        if (! count($this->attributesToBeLogged())) {
            return [];
        }

        $properties['attributes'] = static::logChanges(
            $this->exists
                ? $this->fresh() ?? $this
                : $this
        );

        if (static::eventsToBeRecorded()->contains('updated') && ($processingEvent == 'updated' || $processingEvent == 'pivotAttached' || $processingEvent == 'pivotDetached'|| $processingEvent == 'pivotUpdated')) {
            $nullProperties = array_fill_keys(array_keys($properties['attributes']), null);

            $properties['old'] = array_merge($nullProperties, $this->oldAttributes);

            $this->oldAttributes = [];
        }

        if ($this->shouldLogOnlyDirty() && isset($properties['old'])) {

            foreach ($properties['attributes'] as $key=>$value){
                if ($properties['old'][$key] === $value && !is_array($value)){
                    unset($properties['attributes'][$key]);
                }

                if ($value instanceof \Illuminate\Support\Collection) {

                    if (Str::contains($key, '.')) {

                        $new = $value->count() > 0 ? $value->all() : [];
                        $old = $properties['old'][$key]->count() > 0 ? $properties['old'][$key]->all() : [];

                        $difference = array_diff($new, $old);

                        if (empty($difference)){
                            unset($properties['attributes'][$key]);
                        }
                    }
                }
            }
            $properties['old'] = collect($properties['old'])
                ->only(array_keys($properties['attributes']))
                ->all();
        }

        return $properties;
    }

    public static function logChanges(Model $model): array
    {
        $changes = [];
        $attributes = $model->attributesToBeLogged();

        foreach ($attributes as $attribute) {
            if (Str::contains($attribute, '.')) {
                $changes += self::getRelatedModelAttributeValue($model, $attribute);
            } elseif (Str::contains($attribute, '->')) {
                Arr::set(
                    $changes,
                    str_replace('->', '.', $attribute),
                    static::getModelAttributeJsonValue($model, $attribute)
                );
            } else {
                $changes[$attribute] = $model->getAttribute($attribute);

                if (
                    in_array($attribute, $model->getDates())
                    && ! is_null($changes[$attribute])
                ) {
                    $changes[$attribute] = $model->serializeDate(
                        $model->asDateTime($changes[$attribute])
                    );
                }
            }
        }

        return $changes;
    }

    protected static function getRelatedModelAttributeValue(Model $model, string $attribute): array
    {
        if (substr_count($attribute, '.') > 1) {
            throw CouldNotLogChanges::invalidAttribute($attribute);
        }

        [$relatedModelName, $relatedAttribute] = explode('.', $attribute);

        $relatedModelName = Str::camel($relatedModelName);

        $relatedModel = $model->$relatedModelName ?? $model->$relatedModelName();

        if ($relatedModel instanceof Collection){
            return ["{$relatedModelName}.{$relatedAttribute}" => $relatedModel->pluck($relatedAttribute)];
        }

        return ["{$relatedModelName}.{$relatedAttribute}" => $relatedModel->$relatedAttribute ?? null];
    }

    protected static function getModelAttributeJsonValue(Model $model, string $attribute)
    {
        $path = explode('->', $attribute);
        $modelAttribute = array_shift($path);
        $modelAttribute = collect($model->getAttribute($modelAttribute));

        return data_get($modelAttribute, implode('.', $path));
    }
}
