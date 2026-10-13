<?php

namespace Jma\Connect\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * The host app's own Eloquent model for a table, found by scanning
 * app/Models, so synced rows are written the way the app writes them:
 * through its creating/saving hooks (generated tokens, UUIDs, QR codes),
 * casts and soft deletes, which a plain table insert would skip.
 */
final class LocalModels
{
    /** @var array<string, class-string<Model>>|null table => model */
    private static ?array $byTable = null;

    /**
     * @return class-string<Model>|null
     */
    public static function for(?string $table): ?string
    {
        if (blank($table)) {
            return null;
        }

        return (self::$byTable ??= self::discover())[$table] ?? null;
    }

    public static function flush(): void
    {
        self::$byTable = null;
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private static function discover(): array
    {
        $candidates = [];

        foreach ([...config('connect.consumer.models', []), ...self::appModels()] as $class) {
            try {
                $reflection = new ReflectionClass($class);

                if (! $reflection->isSubclassOf(Model::class) || ! $reflection->isInstantiable()) {
                    continue;
                }

                /** @var Model $model */
                $model = new $class;

                // A model on another database isn't writing this app's table.
                if (! in_array($model->getConnectionName(), [null, config('database.default')], true)) {
                    continue;
                }

                $candidates[$model->getTable()][] = $class;
            } catch (Throwable) {
                continue;
            }
        }

        return array_map(fn (array $classes) => self::best($classes), $candidates);
    }

    /**
     * Several models can share a table (e.g. Admin extends User). Prefer the
     * one named after it, then the parent-most one.
     *
     * @param  list<class-string<Model>>  $classes
     * @return class-string<Model>
     */
    private static function best(array $classes): string
    {
        $table = (new $classes[0])->getTable();

        return collect($classes)->first(fn (string $class) => class_basename($class) === Str::studly(Str::singular($table)))
            ?? collect($classes)->first(fn (string $class) => ! collect($classes)->contains(fn (string $other) => $other !== $class && is_subclass_of($class, $other)))
            ?? $classes[0];
    }

    /**
     * @return list<string>
     */
    private static function appModels(): array
    {
        $directory = app_path('Models');

        if (! is_dir($directory)) {
            return [];
        }

        $namespace = app()->getNamespace().'Models\\';

        return collect(File::allFiles($directory))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->map(fn ($file) => $namespace.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
            ->filter(fn (string $class) => class_exists($class))
            ->values()
            ->all();
    }
}
