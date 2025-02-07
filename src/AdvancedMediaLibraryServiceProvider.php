<?php

namespace AntoineCorbin;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AdvancedMediaLibraryServiceProvider extends PackageServiceProvider
{
    public static string $name = 'advanced-media-library';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name);
    }
}