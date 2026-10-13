<?php

namespace Jma\Connect\Mapping;

use Jma\Connect\Data\Dates;
use Throwable;

/**
 * What a field-map rule does to a value after reading it. Kept to a closed
 * list so admins pick from a dropdown and nothing is evaluated as code.
 */
enum Transform: string
{
    case Lower = 'lower';
    case Upper = 'upper';
    case Title = 'title';
    case FirstWord = 'first_word';
    case RestWords = 'rest_words';
    case LastWord = 'last_word';
    case AllButLastWord = 'all_but_last_word';
    case Date = 'date';
    case Integer = 'integer';
    case Boolean = 'boolean';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Lower->value => 'lowercase',
            self::Upper->value => 'UPPERCASE',
            self::Title->value => 'Title Case',
            self::FirstWord->value => 'First word (e.g. first name from a full name)',
            self::RestWords->value => 'Everything after the first word',
            self::LastWord->value => 'Last word',
            self::AllButLastWord->value => 'Everything before the last word',
            self::Date->value => 'Date/time (ISO 8601)',
            self::Integer->value => 'Whole number',
            self::Boolean->value => 'Yes/no',
        ];
    }

    public function apply(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        $string = trim((string) $value);
        $words = $string === '' ? [] : preg_split('/\s+/', $string);

        return match ($this) {
            self::Lower => mb_strtolower($string),
            self::Upper => mb_strtoupper($string),
            self::Title => mb_convert_case(mb_strtolower($string), MB_CASE_TITLE),
            self::FirstWord => $words[0] ?? '',
            self::RestWords => implode(' ', array_slice($words, 1)),
            self::LastWord => count($words) > 0 ? $words[count($words) - 1] : '',
            self::AllButLastWord => implode(' ', array_slice($words, 0, -1)),
            self::Date => $this->date($string),
            self::Integer => is_numeric($string) ? (int) $string : null,
            self::Boolean => in_array(mb_strtolower($string), ['1', 'true', 'yes', 'y', 'on', 'x'], true),
        };
    }

    private function date(string $value): ?string
    {
        try {
            return Dates::format(Dates::parse($value));
        } catch (Throwable) {
            return null;
        }
    }
}
