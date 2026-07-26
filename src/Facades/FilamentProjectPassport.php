<?php

namespace EgyptDevRu\FilamentProjectPassport\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \EgyptDevRu\FilamentProjectPassport\FilamentProjectPassport
 */
class FilamentProjectPassport extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \EgyptDevRu\FilamentProjectPassport\FilamentProjectPassport::class;
    }
}
