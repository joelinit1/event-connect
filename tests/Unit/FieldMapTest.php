<?php

use Jma\Connect\Mapping\FieldMap;
use Jma\Connect\Mapping\Suggest;
use Jma\Connect\Mapping\Transform;

it('renames, reads JSON text columns by path and writes nested keys', function () {
    $map = new FieldMap([
        ['from' => 'email_address', 'to' => 'email', 'transform' => 'lower'],
        ['from' => 'rsvp.dietary', 'to' => 'extra.dietary'],
        ['from' => 'table_id', 'to' => 'table'],
    ]);

    expect($map->apply([
        'email_address' => ' Ada@Example.com ',
        'rsvp' => '{"dietary": "Vegan", "plus_one": true}',
        'table_id' => 12,
    ]))->toBe(['email' => 'ada@example.com', 'extra' => ['dietary' => 'Vegan'], 'table' => 12]);
});

it('copies every key of a JSON column with a .* path', function () {
    $map = new FieldMap([['from' => 'answers.*', 'to' => 'extra']]);

    expect($map->apply(['answers' => '{"a": 1, "b": "two"}']))->toBe(['extra' => ['a' => 1, 'b' => 'two']]);
});

it('splits and joins names', function () {
    $split = new FieldMap([
        ['from' => 'name', 'to' => 'first_name', 'transform' => 'first_word'],
        ['from' => 'name', 'to' => 'last_name', 'transform' => 'rest_words'],
    ]);
    $join = new FieldMap([['from' => '{first_name} {last_name}', 'to' => 'full_name']]);

    expect($split->apply(['name' => 'Ada King Lovelace']))->toBe(['first_name' => 'Ada', 'last_name' => 'King Lovelace'])
        ->and($split->apply(['name' => 'Cher']))->toBe(['first_name' => 'Cher', 'last_name' => ''])
        ->and($join->apply(['first_name' => 'Ada', 'last_name' => 'Lovelace']))->toBe(['full_name' => 'Ada Lovelace'])
        ->and($join->apply(['first_name' => 'Cher']))->toBe(['full_name' => 'Cher']);
});

it('leaves out rules whose source is missing, so "not sent" stays distinct from "empty"', function () {
    $map = new FieldMap([
        ['from' => 'company', 'to' => 'company'],
        ['from' => 'rsvp.dietary', 'to' => 'dietary'],
        ['from' => 'seat', 'to' => 'seat'],
    ]);

    expect($map->apply(['company' => null, 'rsvp' => '{}']))->toBe(['company' => null]);
});

it('fills defaults for empty or missing values', function () {
    $map = new FieldMap([['from' => 'category', 'to' => 'category', 'default' => 'Guest']]);

    expect($map->apply(['category' => '']))->toBe(['category' => 'Guest'])
        ->and($map->apply([]))->toBe(['category' => 'Guest'])
        ->and($map->apply(['category' => 'VIP']))->toBe(['category' => 'VIP']);
});

it('ignores incomplete rules and reports sources and targets', function () {
    $map = new FieldMap([
        ['from' => '', 'to' => 'x'],
        ['from' => 'a', 'to' => ''],
        ['from' => '{first} {last}', 'to' => 'name'],
        ['from' => 'rsvp.*', 'to' => 'extra'],
        ['from' => 'rsvp.diet', 'to' => 'extra.diet'],
    ]);

    expect($map->rules())->toHaveCount(3)
        ->and($map->sources())->toBe(['first', 'last', 'rsvp', 'rsvp.diet'])
        ->and($map->targets())->toBe(['name', 'extra']);
});

it('applies transforms', function (Transform $transform, mixed $in, mixed $out) {
    expect($transform->apply($in))->toBe($out);
})->with([
    [Transform::Upper, 'vip', 'VIP'],
    [Transform::Title, 'ADA LOVELACE', 'Ada Lovelace'],
    [Transform::LastWord, 'Ada King Lovelace', 'Lovelace'],
    [Transform::AllButLastWord, 'Ada King Lovelace', 'Ada King'],
    [Transform::Date, '2026-03-01 18:30:00', '2026-03-01T18:30:00+00:00'],
    [Transform::Date, 'not a date', null],
    [Transform::Integer, '12', 12],
    [Transform::Integer, 'T12', null],
    [Transform::Boolean, 'Yes', true],
    [Transform::Boolean, '0', false],
]);

it('flattens JSON columns into paths', function () {
    expect(array_keys(FieldMap::flatten(['id' => 1, 'rsvp' => '{"diet": "x", "q": {"a": 1}}', 'tags' => '["a"]'])))
        ->toBe(['id', 'rsvp.diet', 'rsvp.q.a', 'tags']);
});

it('suggests a provider mapping from a table with its own naming', function () {
    $rules = Suggest::forProvider(
        ['id', 'guest_name', 'Email Address', 'company_name', 'table_id', 'mobile', 'password', 'rsvp', 'notes', 'event_code', 'updated_at'],
        ['rsvp' => '{"dietary": "Vegan"}', 'notes' => 'plain text'],
        ['event_code'],
    );

    expect(collect($rules)->mapWithKeys(fn ($r) => [$r['to'] => $r['from']])->all())->toBe([
        'email' => 'Email Address',
        'company' => 'company_name',
        'table' => 'table_id',
        'extra.phone' => 'mobile',
        'extra' => 'rsvp.*',
        'first_name' => 'guest_name',
        'last_name' => 'guest_name',
    ]);
});

it('suggests a consumer mapping onto local columns, including extras and a joined name', function () {
    $rules = Suggest::forConsumer(
        ['first_name', 'last_name', 'email', 'table', 'extra.dietary', 'extra.phone', 'seat'],
        ['id', 'external_id', 'name', 'email_address', 'table_no', 'dietary_requirements', 'telephone', 'created_at'],
        ['external_id'],
    );

    expect(collect($rules)->map(fn ($r) => "{$r['from']} → {$r['to']}")->all())->toBe([
        'email → email_address',
        'table → table_no',
        'extra.dietary → dietary_requirements',
        'extra.phone → telephone',
        '{first_name} {last_name} → name',
    ]);
});
