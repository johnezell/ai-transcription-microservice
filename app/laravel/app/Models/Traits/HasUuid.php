<?php

namespace App\Models\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    /**
     * Boot the trait.
     *
     * @return void
     */
    protected static function bootHasUuid()
    {
        static::creating(function ($model) {
            // Only set UUID if it's not already set
            if (!$model->{$model->getKeyName()}) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    /**
     * Override the getIncrementing method to prevent Laravel from attempting to auto-increment this key
     *
     * @return bool
     */
    public function getIncrementing()
    {
        return false;
    }

    /**
     * Override the getKeyType method to ensure that the primary key is a string
     *
     * @return string
     */
    public function getKeyType()
    {
        return 'string';
    }
} 