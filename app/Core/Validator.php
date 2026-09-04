<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight input validator used by controllers to normalise and validate
 * form submissions consistently. Define rules as [field => rule] where rule is
 * a pipe-separated string ("required|email|max:255") or an array of rules.
 *
 * Rules:
 *   required   - value is present and not empty/whitespace
 *   email      - valid email address
 *   numeric    - integer or numeric string
 *   min:N      - minimum length (strings) / minimum numeric value
 *   max:N      - maximum length (strings) / maximum numeric value
 *   in:a,b,c   - value must be one of the comma-separated list
 *   regex:/.../ - value must match the regular expression
 *   date       - value parses as a date
 *
 * Usage:
 *   $errors = Validator::validate($data, ['email' => 'required|email']);
 *   if ($errors) { ... show errors ... }
 */
class Validator
{
    /**
     * Validate an input array against a rules map. Returns an associative
     * array of [field => first error message]; empty when everything passes.
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;

            if (is_string($rule)) {
                $rule = array_map('trim', explode('|', $rule));
            }

            foreach ((array) $rule as $single) {
                $error = self::check($field, $value, (string) $single);
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Run one rule against a value. Returns the error message or null.
     */
    private static function check(string $field, $value, string $rule): ?string
    {
        $name = str_replace(['_', '.'], ' ', $field);

        // Split "max:255" style rules into name + parameter.
        if (strpos($rule, ':') !== false) {
            [$rule, $param] = explode(':', $rule, 2);
        } else {
            $param = null;
        }

        switch ($rule) {
            case 'required':
                if ($value === null || (is_string($value) && trim($value) === '') || $value === []) {
                    return ucfirst($name) . ' is required.';
                }
                break;

            case 'email':
                if ($value !== null && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    return 'A valid email address is required.';
                }
                break;

            case 'numeric':
                if ($value !== null && !is_numeric($value)) {
                    return ucfirst($name) . ' must be a number.';
                }
                break;

            case 'min':
                if ($value !== null && $value !== '') {
                    if (is_numeric($value) && $value < (float) $param) {
                        return ucfirst($name) . ' must be at least ' . $param . '.';
                    }
                    if (is_string($value) && mb_strlen($value) < (int) $param) {
                        return ucfirst($name) . ' must be at least ' . $param . ' characters.';
                    }
                }
                break;

            case 'max':
                if ($value !== null && $value !== '') {
                    if (is_numeric($value) && $value > (float) $param) {
                        return ucfirst($name) . ' must be at most ' . $param . '.';
                    }
                    if (is_string($value) && mb_strlen($value) > (int) $param) {
                        return ucfirst($name) . ' must be at most ' . $param . ' characters.';
                    }
                }
                break;

            case 'in':
                $allowed = array_map('trim', explode(',', (string) $param));
                if ($value !== null && !in_array((string) $value, $allowed, true)) {
                    return ucfirst($name) . ' must be one of: ' . implode(', ', $allowed) . '.';
                }
                break;

            case 'regex':
                if ($value !== null && $value !== '' && !preg_match((string) $param, (string) $value)) {
                    return ucfirst($name) . ' is not valid.';
                }
                break;

            case 'date':
                if ($value !== null && $value !== '' && strtotime((string) $value) === false) {
                    return ucfirst($name) . ' must be a valid date.';
                }
                break;
        }

        return null;
    }
}