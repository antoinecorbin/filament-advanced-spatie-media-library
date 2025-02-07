<?php

namespace AntoineCorbin\Form\Components;

use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use League\Flysystem\UnableToCheckFileExistence;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Translatable\HasTranslations;

class AdvancedMediaLibraryFileUpload extends SpatieMediaLibraryFileUpload
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadStateFromRelationshipsUsing(static function (SpatieMediaLibraryFileUpload $component, HasMedia $record) use (&$index): void {
            /** @var Model&HasMedia $record */
            $media = $record->load('media')
                ->getMedia($component->getCollection() ?? 'default')
                ->when(
                    $component->isTranslatable(),
                    fn(Collection $media) => $media->filter(fn(Media $media) => $media->getCustomProperty('locale') === $component->getLivewire()->activeLocale)
                )
                ->when(
                    $component->isRepeater(),
                    function (Collection $media) use ($component) {
                        $statePath = $component->getStatePath();
                        $repeaterState = $component->getParentRepeater()->getState();

                        preg_match('/blocks\.([a-f0-9-]+)\./', $statePath, $uuidMatches);
                        preg_match('/\.(\d+)\./', $statePath, $numericMatches);

                        $currentIndex = null;

                        if (isset($uuidMatches[1])) {
                            $currentUuid = $uuidMatches[1];
                            if (isset($repeaterState[$currentUuid])) {
                                $currentIndex = array_search($currentUuid, array_keys($repeaterState));
                            }
                        } elseif (isset($numericMatches[1])) {
                            $currentIndex = (int)$numericMatches[1];
                        }

                        if ($currentIndex !== null) {
                            return $media->filter(function (Media $media) use ($currentIndex) {
                                $repeaterIndex = $media->getCustomProperty('repeater_index');
                                return $repeaterIndex === $currentIndex;
                            });
                        }

                        return $media;
                    }
                )
                ->when(
                    $component->hasMediaFilter(),
                    fn(Collection $media) => $component->filterMedia($media)
                )
                ->when(
                    !$component->isMultiple(),
                    fn(Collection $media): Collection => $media->take(1),
                )
                ->mapWithKeys(function (Media $media): array {
                    $uuid = $media->getAttributeValue('uuid');

                    return [$uuid => $uuid];
                })
                ->toArray();

            $component->state($media);
        });

        $this->saveRelationshipsUsing(null);

        $this->saveUploadedFileUsing(static function (SpatieMediaLibraryFileUpload $component, TemporaryUploadedFile $file, ?Model $record): ?string {
            if (!method_exists($record, 'addMediaFromString')) {
                return $file;
            }

            try {
                if (!$file->exists()) {
                    return null;
                }
            } catch (UnableToCheckFileExistence $exception) {
                return null;
            }

            /** @var FileAdder $mediaAdder */
            $mediaAdder = $record->addMediaFromString($file->get());

            $filename = $component->getUploadedFileNameForStorage($file);

            $customProperties = $component->getCustomProperties();

            if ($component->isTranslatable()) {
                $customProperties['locale'] = $component->getLivewire()->activeLocale;
            }

            if ($component->isRepeater()) {
                $customProperties['repeater_index'] = $component->getRepeaterIndex(fileName: $file->getFilename());
            }

            $media = $mediaAdder
                ->addCustomHeaders($component->getCustomHeaders())
                ->usingFileName($filename)
                ->usingName($component->getMediaName($file) ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                ->storingConversionsOnDisk($component->getConversionsDisk() ?? '')
                ->withCustomProperties($customProperties)
                ->withManipulations($component->getManipulations())
                ->withResponsiveImagesIf($component->hasResponsiveImages())
                ->withProperties($component->getProperties())
                ->toMediaCollection($component->getCollection() ?? 'default', $component->getDiskName());

            return $media->getAttributeValue('uuid');
        });

        $this->beforeStateDehydrated(static function (AdvancedMediaLibraryFileUpload $component) {
            $component->deleteAbandonedFiles();
            $component->saveUploadedFiles();
            $component->updateRepeaterIndices();
        });

        $this->dehydrated(static function (AdvancedMediaLibraryFileUpload $component, ?array $state): string|array|null|TemporaryUploadedFile {
            $files = array_values($state ?? []);

            if ($component->isMultiple()) {
                return $files;
            }
            return $files[0] ?? null;
        });
    }

    protected function isTranslatable()
    {
        $model = $this->getRecord();

        if (!in_array(HasTranslations::class, class_uses_recursive($model))
            && !isset($model->translatable)
            || !in_array($this->getName(), $model->translatable)
            && !isset($this->getLivewire()->activeLocale)) {
            return false;
        }

        return true;
    }

    protected function isRepeater(): bool
    {
        return $this->getParentRepeater() !== null;
    }

    public function deleteAbandonedFiles(): void
    {
        /** @var Model&HasMedia $record */
        $record = $this->getRecord();

        $mediaCollection = $record->getMedia($this->getCollection() ?? 'default');

        if ($this->isRepeater()) {
            $parentRepeater = $this->getParentRepeater();
            $repeaterState = $parentRepeater->getState();
            $mediaFieldName = $this->getName();

            foreach ($repeaterState as $item) {
                if (!empty($item[$mediaFieldName])) {
                    $mediaCollection = $mediaCollection->whereNotIn('uuid', array_keys($item[$mediaFieldName]));
                }
            }
        } else {
            $mediaCollection = $mediaCollection->whereNotIn('uuid', array_keys($this->getState() ?? []));
        }

        $mediaCollection
            ->when($this->isTranslatable(), function (Collection $media) {
                return $media->filter(fn(Media $media) => $media->getCustomProperty('locale') === $this->getLivewire()->activeLocale);
            })
            ->when($this->hasMediaFilter(), fn(Collection $media): Collection => $this->filterMedia($media))
            ->each(fn(Media $media) => $media->delete());
    }

    public function updateRepeaterIndices(): void
    {
        if (!$this->isRepeater()) {
            return;
        }

        /** @var Model&HasMedia $record */
        $record = $this->getRecord();
        $parentRepeater = $this->getParentRepeater();
        $mediaCollection = $record->getMedia($this->getCollection() ?? 'default');

        $state = $parentRepeater->getState();
        $mediaFieldName = $this->getName();

        $uuidToIndexMap = [];
        foreach ($state as $index => $item) {
            if (!empty($item[$mediaFieldName])) {
                foreach (array_keys($item[$mediaFieldName]) as $mediaUuid) {
                    $uuidToIndexMap[$mediaUuid] = array_search($index, array_keys($state));
                }
            }
        }

        $mediaCollection
            ->when(
                $this->isTranslatable(),
                fn(Collection $media) => $media->filter(fn(Media $media) => $media->getCustomProperty('locale') === $this->getLivewire()->activeLocale)
            )
            ->each(function (Media $media) use ($uuidToIndexMap) {
                if (isset($uuidToIndexMap[$media->uuid])) {
                    $newIndex = $uuidToIndexMap[$media->uuid];
                    if ($media->getCustomProperty('repeater_index') !== $newIndex) {
                        $media->setCustomProperty('repeater_index', $newIndex);
                        $media->save();
                    }
                }
            });
    }

    protected function getRepeaterIndex(string $fileName): ?int
    {
        $parent = $this->getParentRepeater()
            ?? throw new \Exception('The AdvancedMediaLibraryFileUpload component must be used inside a repeater. Otherwise delete repeater() method.');

        $mediaFieldName = $this->getName();
        $repeaterItems = array_values($parent->getState());

        foreach ($repeaterItems as $position => $item) {
            if (!empty($item[$mediaFieldName]) && $this->fileExists($item[$mediaFieldName], $fileName)) {
                return $position;
            }
        }

        return null;
    }

    private function fileExists(array $files, string $fileName): bool
    {
        return array_reduce($files, fn($found, $file) => $found || ($file instanceof TemporaryUploadedFile && $file->getFilename() === $fileName),
            false
        );
    }
}
