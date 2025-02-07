# Filament Advanced Spatie Media Library

This package extends the functionality of the Filament Spatie Media Library plugin by adding support for translations with Spatie Translatable and compatibility with Filament repeaters.

## Installation

You can install the package via composer:

```bash
composer require antoinecorbin/filament-advanced-spatie-media-library
```

## Configuration

The package works automatically without additional configuration. It uses the existing Spatie Media Library configuration.

## Usage

### Basic Usage

```php
use AntoineCorbin\Form\Components\AdvancedMediaLibraryFileUpload;

AdvancedMediaLibraryFileUpload::make('media')
```

### With Translations

To use translations, your model must use Spatie's `HasTranslations` trait:

```php
use Spatie\Translatable\HasTranslations;

class Post extends Model
{
    use HasTranslations;
    
    public array $translatable = ['media'];
}
```

### In a Repeater

The component works automatically within a Filament repeater:

```php
Repeater::make('sections')
    ->schema([
        AdvancedMediaLibraryFileUpload::make('images')
    ])
```

## Features

- Full translation support with Spatie Translatable
- Smart media management in repeaters
- Automatic abandoned file cleanup
- Repeater index updates
- Multiple collection support
- Image manipulation compatibility
- Responsive images support

### Advanced Usage

The component automatically handles:
- File uploads and organization within repeater fields
- Translation management for multilingual media
- Media collection management
- Custom properties preservation
- Automatic cleanup of unused media

### Available Methods

```php
AdvancedMediaLibraryFileUpload::make('media')
    ->collection('images') // Set a specific media collection
    ->multiple() // Allow multiple file uploads
    ->responsiveImages() // Enable responsive images
    ->customProperties([]) // Add custom properties to media
    ->withManipulations([]) // Add image manipulations
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.