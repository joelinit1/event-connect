<?php

namespace Jma\Connect\Mapping;

/**
 * An admin-edited list of rules that turns one row shape into another, so a
 * new table layout is a settings change rather than a code change.
 *
 * Each rule is {from, to, transform?, default?}:
 *
 * - `from` is a path into the input row. Dots step into nested arrays and
 *   into columns holding JSON text, so `rsvp.dietary` reads the dietary key
 *   of a JSON `rsvp` column. A path ending `.*` copies every key under it.
 *   A path containing `{...}` is a template: `{first_name} {last_name}`.
 * - `to` is the output key. Dots write nested (`extra.dietary`).
 * - `transform` is a Transform value.
 * - `default` is used when the value comes out empty.
 *
 * A rule whose source isn't in the row at all (and has no default) is
 * skipped, so the output only carries keys the input actually had. That
 * keeps "not sent" distinct from "sent empty" all the way through a sync.
 */
final class FieldMap
{
    /** @var list<array{from: string, to: string, transform: ?Transform, default: ?string}> */
    private array $rules;

    /**
     * @param  iterable<array<string, mixed>>|null  $rules
     */
    public function __construct(?iterable $rules)
    {
        $this->rules = [];

        foreach ($rules ?? [] as $rule) {
            $from = trim((string) ($rule['from'] ?? ''));
            $to = trim((string) ($rule['to'] ?? ''));

            if ($to === '' || ($from === '' && blank($rule['default'] ?? null))) {
                continue;
            }

            $this->rules[] = [
                'from' => $from,
                'to' => $to,
                'transform' => Transform::tryFrom((string) ($rule['transform'] ?? '')),
                'default' => blank($rule['default'] ?? null) ? null : (string) $rule['default'],
            ];
        }
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    /**
     * @return list<array{from: string, to: string, transform: ?Transform, default: ?string}>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Output keys the rules write, top level only (`extra.x` → `extra`).
     *
     * @return list<string>
     */
    public function targets(): array
    {
        return array_values(array_unique(array_map(
            fn (array $rule) => explode('.', $rule['to'])[0],
            $this->rules,
        )));
    }

    /**
     * Input paths the rules read, with templates expanded to their parts.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        $paths = [];

        foreach ($this->rules as $rule) {
            array_push($paths, ...self::pathsIn($rule['from']));
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function apply(array $row): array
    {
        $out = [];

        foreach ($this->rules as $rule) {
            if (str_ends_with($rule['from'], '.*')) {
                [$present, $value] = self::read($row, substr($rule['from'], 0, -2));
                $value = self::decodeJson($value);

                if ($present && is_array($value)) {
                    foreach ($value as $key => $item) {
                        self::write($out, $rule['to'].'.'.$key, $item);
                    }
                }

                continue;
            }

            [$present, $value] = self::value($row, $rule['from']);

            if ($present && $rule['transform'] !== null) {
                $value = $rule['transform']->apply($value);
            }

            if (self::isBlank($value) && $rule['default'] !== null) {
                [$present, $value] = [true, $rule['default']];
            }

            if ($present) {
                self::write($out, $rule['to'], is_string($value) ? trim($value) : $value);
            }
        }

        return $out;
    }

    /**
     * Every leaf path in a row, with JSON text columns opened up, e.g.
     * ['first_name', 'rsvp.dietary', 'rsvp.plus_one']. Used to show admins
     * what a source actually contains.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed> path => value
     */
    public static function flatten(array $row, string $prefix = ''): array
    {
        $flat = [];

        foreach ($row as $key => $value) {
            $path = $prefix.$key;
            $decoded = self::decodeJson($value);

            if (is_array($decoded) && $decoded !== [] && ! array_is_list($decoded)) {
                $flat += self::flatten($decoded, $path.'.');
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: bool, 1: mixed}
     */
    private static function value(array $row, string $from): array
    {
        if (! str_contains($from, '{')) {
            return self::read($row, $from);
        }

        $present = false;

        $value = preg_replace_callback('/\{([^}]+)\}/', function (array $m) use ($row, &$present) {
            [$found, $part] = self::read($row, trim($m[1]));
            $present = $present || $found;

            return is_scalar($part) ? (string) $part : '';
        }, $from);

        return [$present, preg_replace('/\s+/', ' ', trim($value))];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: bool, 1: mixed} [whether the path exists, its value]
     */
    public static function read(array $row, string $path): array
    {
        if (array_key_exists($path, $row)) {
            return [true, $row[$path]];
        }

        $current = $row;

        foreach (explode('.', $path) as $segment) {
            $current = self::decodeJson($current);

            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return [false, null];
            }

            $current = $current[$segment];
        }

        return [true, $current];
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private static function write(array &$out, string $to, mixed $value): void
    {
        $segments = explode('.', $to);
        $ref = &$out;

        foreach (array_slice($segments, 0, -1) as $segment) {
            if (! isset($ref[$segment]) || ! is_array($ref[$segment])) {
                $ref[$segment] = [];
            }

            $ref = &$ref[$segment];
        }

        $ref[end($segments)] = $value;
    }

    /**
     * @return list<string>
     */
    private static function pathsIn(string $from): array
    {
        if (preg_match_all('/\{([^}]+)\}/', $from, $m)) {
            return array_map('trim', $m[1]);
        }

        return [str_ends_with($from, '.*') ? substr($from, 0, -2) : $from];
    }

    private static function decodeJson(mixed $value): mixed
    {
        if (! is_string($value) || ! in_array(ltrim($value)[0] ?? '', ['{', '['], true)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
