<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RncModelFields
{
    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'boolean'];

    public static function defaults(string $layout): array
    {
        return $layout === 'inspection_form' ? [
            ['key' => 'work_type', 'label' => 'Tipo de obra', 'type' => 'select', 'required' => false, 'options' => ['Pista Nova', 'Pista Existente']],
            ['key' => 'pavement_type', 'label' => 'Tipo de pavimento', 'type' => 'select', 'required' => false, 'options' => ['Rígido', 'Flexível']],
        ] : [];
    }

    public static function definitions(string $layout, ?array $fields): array
    {
        if ($fields === null) return self::defaults($layout);
        return array_values(array_map(fn ($field) => [
            'key' => $field['key'], 'label' => trim($field['label']), 'type' => $field['type'],
            'required' => (bool) ($field['required'] ?? false),
            'options' => $field['type'] === 'select' ? array_values(array_unique(array_map('trim', $field['options'] ?? []))) : [],
        ], $fields));
    }

    public static function validateDefinitions(string $layout, mixed $fields): array
    {
        $data = Validator::make(['field_definitions' => $fields], [
            'field_definitions' => ['present', 'array', 'max:30'],
            'field_definitions.*' => ['array:key,label,type,required,options'],
            'field_definitions.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,59}$/D', 'distinct'],
            'field_definitions.*.label' => ['required', 'string', 'max:120'],
            'field_definitions.*.type' => ['required', Rule::in(self::TYPES)],
            'field_definitions.*.required' => ['required', 'boolean'],
            'field_definitions.*.options' => ['present', 'array', 'max:30'],
            'field_definitions.*.options.*' => ['required', 'string', 'max:120', 'distinct'],
        ])->validate()['field_definitions'];
        foreach ($data as $index => $field) {
            if ($field['type'] === 'select' && ! count($field['options'])) {
                throw ValidationException::withMessages(["field_definitions.{$index}.options" => 'Informe pelo menos uma opção.']);
            }
        }
        foreach (self::defaults($layout) as $builtin) {
            $field = collect($data)->firstWhere('key', $builtin['key']);
            if (! $field || $field['label'] !== $builtin['label'] || $field['type'] !== 'select' || $field['options'] !== $builtin['options']) {
                throw ValidationException::withMessages(['field_definitions' => 'Mantenha os campos Tipo de obra e Tipo de pavimento e suas opções neste modelo.']);
            }
        }
        // These keys have dedicated positions in the inspection header.
        if ($layout !== 'inspection_form' && collect($data)->contains(fn ($field) => in_array($field['key'], ['work_type', 'pavement_type'], true))) {
            throw ValidationException::withMessages(['field_definitions' => 'Esses campos pertencem ao formulário de inspeção.']);
        }
        return self::definitions($layout, $data);
    }

    public static function values(array $fields, mixed $values): array
    {
        $keys = array_column($fields, 'key');
        $rules = ['model_field_values' => ['nullable', 'array', 'max:30', function ($attribute, $value, $fail) use ($keys) {
            if (is_array($value) && array_diff(array_keys($value), $keys)) $fail('Existem campos que não pertencem ao modelo desta RNC.');
        }]];
        $attributes = [];
        foreach ($fields as $field) {
            $key = 'model_field_values.'.$field['key'];
            $rules[$key] = [$field['required'] ? 'required' : 'nullable', ...match ($field['type']) {
                'number' => ['numeric', 'between:-999999999999,999999999999'],
                'date' => ['date_format:Y-m-d'],
                'boolean' => [Rule::in(['yes', 'no'])],
                'select' => ['string', Rule::in($field['options'])],
                default => ['string', 'max:'.($field['type'] === 'textarea' ? 10000 : 1000)],
            }];
            $attributes[$key] = $field['label'];
        }
        $validated = Validator::make(['model_field_values' => $values], $rules, [], $attributes)->validate();
        return collect($validated['model_field_values'] ?? [])->map(fn ($value) => $value === '' ? null : $value)->all();
    }

    public static function rows(array $fields, array $values): array
    {
        return array_map(function ($field) use ($values) {
            $value = $values[$field['key']] ?? null;
            if ($field['type'] === 'boolean') $value = match ($value) { 'yes' => 'Sim', 'no' => 'Não', default => null };
            if ($field['type'] === 'date' && $value) $value = implode('/', array_reverse(explode('-', $value)));
            return ['key' => $field['key'], 'label' => $field['label'], 'value' => $value ?? 'Não informado'];
        }, $fields);
    }
}
