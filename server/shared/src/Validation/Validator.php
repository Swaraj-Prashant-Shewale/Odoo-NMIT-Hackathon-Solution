<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Validation;

use Dayflow\Kernel\Security\Password;
use Dayflow\Kernel\Security\Roles;

/**
 * Declarative input validation.
 *
 * Controllers describe what they expect and receive back a clean, typed array.
 * Anything not described is discarded, so a request can never write a field the
 * endpoint did not ask for. Rules run left to right and stop at the first
 * failure for a given field, which keeps error messages readable.
 *
 * Example:
 *   $data = Validator::make($request->all(), [
 *       'email'     => 'required|email|max:150',
 *       'role'      => 'required|role',
 *       'starts_on' => 'required|date|after_or_equal:today',
 *   ])->validated();
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels Friendly field names for messages.
     */
    private function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $labels = [],
    ) {
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        $validator = new self($data, $rules, $labels);
        $validator->run();

        return $validator;
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The cleaned input.
     *
     * @return array<string, mixed>
     * @throws ValidationException when any rule failed.
     */
    public function validated(): array
    {
        if ($this->fails()) {
            throw new ValidationException('Some of the information supplied is not valid.', $this->errors);
        }

        return $this->validated;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            $isRequired = in_array('required', $rules, true);
            $isPresent = $value !== null && $value !== '' && $value !== [];

            if (!$isPresent) {
                if ($isRequired) {
                    $this->fail($field, sprintf('%s is required.', $this->label($field)));
                } elseif (in_array('nullable', $rules, true) && array_key_exists($field, $this->data)) {
                    $this->validated[$field] = null;
                }

                continue;
            }

            // A query string can always be made to yield an array: "?status=open"
            // and "?status[]=open" reach PHP as a string and as an array
            // respectively, and only the caller decides which. Rules that
            // expect a scalar would cast it, and "Array to string conversion"
            // becomes an ErrorException and then a 500 — an error page where a
            // plain validation message belongs.
            if (is_array($value) && !in_array('array', $rules, true) && !in_array('json', $rules, true)) {
                $this->fail($field, sprintf('%s must be a single value.', $this->label($field)));

                continue;
            }

            foreach ($rules as $rule) {
                if (in_array($rule, ['required', 'nullable'], true)) {
                    continue;
                }

                $result = $this->apply($field, $rule, $value);

                if ($result === null) {
                    // Rule failed; skip remaining rules for this field.
                    continue 2;
                }

                $value = $result;
            }

            $this->validated[$field] = $value;
        }
    }

    /**
     * True for a genuine number, false for text that merely looks like one.
     *
     * "411001" is a postal code, not the number four hundred and eleven
     * thousand; 411001 is the number. The distinction is the whole point.
     */
    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /** Returns the (possibly coerced) value, or null when the rule fails. */
    private function apply(string $field, string $rule, mixed $value): mixed
    {
        [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
        $label = $this->label($field);

        switch ($name) {
            case 'string':
                if (!is_string($value)) {
                    return $this->fail($field, sprintf('%s must be text.', $label));
                }

                return $value;

            case 'int':
            case 'integer':
                if (!is_numeric($value) || (string) (int) $value !== (string) $value) {
                    return $this->fail($field, sprintf('%s must be a whole number.', $label));
                }

                return (int) $value;

            case 'numeric':
                if (!is_numeric($value)) {
                    return $this->fail($field, sprintf('%s must be a number.', $label));
                }

                return (float) $value;

            case 'bool':
            case 'boolean':
                $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed === null) {
                    return $this->fail($field, sprintf('%s must be true or false.', $label));
                }

                return $parsed;

            case 'array':
                if (!is_array($value)) {
                    return $this->fail($field, sprintf('%s must be a list.', $label));
                }

                return $value;

            case 'email':
                $email = filter_var((string) $value, FILTER_VALIDATE_EMAIL);
                if ($email === false) {
                    return $this->fail($field, sprintf('%s must be a valid email address.', $label));
                }

                return strtolower((string) $email);

            case 'url':
                if (filter_var((string) $value, FILTER_VALIDATE_URL) === false) {
                    return $this->fail($field, sprintf('%s must be a valid web address.', $label));
                }

                return (string) $value;

            case 'uuid':
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $value) !== 1) {
                    return $this->fail($field, sprintf('%s is not a valid reference.', $label));
                }

                return strtolower((string) $value);

            // "min" and "max" mean magnitude for a number and length for text.
            //
            // Which one applies is decided by the value's actual type, never by
            // whether the text happens to look like a number. Asking
            // is_numeric() instead - as this once did - made "max:20" on a text
            // column reject the postal code 411001 for being larger than twenty,
            // and a phone number typed without spaces along with it. Every rule
            // that coerces (integer, numeric, money) runs before these and hands
            // on a real int or float, so by the time either arrives here the
            // type already says which comparison was meant.
            case 'min':
                if (self::isNumber($value)) {
                    if ((float) $value < (float) $argument) {
                        return $this->fail($field, sprintf('%s must be at least %s.', $label, $argument));
                    }

                    return $value;
                }

                if (mb_strlen((string) $value) < (int) $argument) {
                    return $this->fail($field, sprintf('%s must be at least %s characters.', $label, $argument));
                }

                return $value;

            case 'max':
                if (self::isNumber($value)) {
                    if ((float) $value > (float) $argument) {
                        return $this->fail($field, sprintf('%s must be no more than %s.', $label, $argument));
                    }

                    return $value;
                }

                if (mb_strlen((string) $value) > (int) $argument) {
                    return $this->fail($field, sprintf('%s must be no more than %s characters.', $label, $argument));
                }

                return $value;

            case 'between':
                [$low, $high] = array_pad(explode(',', (string) $argument), 2, '0');
                if ((float) $value < (float) $low || (float) $value > (float) $high) {
                    return $this->fail($field, sprintf('%s must be between %s and %s.', $label, $low, $high));
                }

                return $value;

            case 'in':
                $options = explode(',', (string) $argument);
                if (!in_array((string) $value, $options, true)) {
                    return $this->fail($field, sprintf('%s must be one of: %s.', $label, implode(', ', $options)));
                }

                return (string) $value;

            case 'date':
                $date = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);
                if ($date === false || $date->format('Y-m-d') !== (string) $value) {
                    return $this->fail($field, sprintf('%s must be a date in YYYY-MM-DD format.', $label));
                }

                return (string) $value;

            case 'time':
                if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', (string) $value) !== 1) {
                    return $this->fail($field, sprintf('%s must be a time in HH:MM format.', $label));
                }

                return (string) $value;

            case 'datetime':
                if (strtotime((string) $value) === false) {
                    return $this->fail($field, sprintf('%s must be a valid date and time.', $label));
                }

                return (string) $value;

            case 'after_or_equal':
                $compare = $argument === 'today' ? date('Y-m-d') : (string) ($this->data[$argument] ?? $argument);
                if (strtotime((string) $value) < strtotime($compare)) {
                    return $this->fail($field, sprintf('%s cannot be earlier than %s.', $label, $this->label((string) $argument)));
                }

                return (string) $value;

            case 'before_or_equal':
                $compare = $argument === 'today' ? date('Y-m-d') : (string) ($this->data[$argument] ?? $argument);
                if (strtotime((string) $value) > strtotime($compare)) {
                    return $this->fail($field, sprintf('%s cannot be later than %s.', $label, $this->label((string) $argument)));
                }

                return (string) $value;

            case 'same':
                if ((string) $value !== (string) ($this->data[$argument] ?? null)) {
                    return $this->fail($field, sprintf('%s does not match %s.', $label, $this->label((string) $argument)));
                }

                return $value;

            case 'different':
                if ((string) $value === (string) ($this->data[$argument] ?? null)) {
                    return $this->fail($field, sprintf('%s must be different from %s.', $label, $this->label((string) $argument)));
                }

                return $value;

            case 'alpha':
                if (preg_match('/^[\p{L} ]+$/u', (string) $value) !== 1) {
                    return $this->fail($field, sprintf('%s may only contain letters.', $label));
                }

                return (string) $value;

            case 'alpha_num':
                if (preg_match('/^[\p{L}\p{Nd}]+$/u', (string) $value) !== 1) {
                    return $this->fail($field, sprintf('%s may only contain letters and numbers.', $label));
                }

                return (string) $value;

            case 'slug':
                if (preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/', (string) $value) !== 1) {
                    return $this->fail($field, sprintf('%s may only contain lowercase letters, numbers, hyphens and underscores.', $label));
                }

                return (string) $value;

            case 'phone':
                if (preg_match('/^\+?[0-9][0-9 ()-]{6,19}$/', (string) $value) !== 1) {
                    return $this->fail($field, sprintf('%s must be a valid phone number.', $label));
                }

                return (string) $value;

            case 'employee_code':
                if (preg_match('/^[A-Z0-9][A-Z0-9-]{2,19}$/i', (string) $value) !== 1) {
                    return $this->fail($field, sprintf('%s must be 3 to 20 letters, numbers or hyphens.', $label));
                }

                return strtoupper((string) $value);

            case 'role':
                if (!Roles::isValid((string) $value)) {
                    return $this->fail($field, sprintf('%s is not a recognised role.', $label));
                }

                return (string) $value;

            case 'password':
                $problems = Password::problems((string) $value, $this->passwordContext());
                if ($problems !== []) {
                    foreach ($problems as $problem) {
                        $this->fail($field, $problem);
                    }

                    return null;
                }

                return (string) $value;

            case 'money':
                // Accepts "1234.56" and stores minor units to avoid float drift.
                if (!is_numeric($value) || (float) $value < 0) {
                    return $this->fail($field, sprintf('%s must be an amount of zero or more.', $label));
                }

                return (int) round(((float) $value) * 100);

            case 'youtube':
                if (\Dayflow\Kernel\Support\Str::youtubeId((string) $value) === null) {
                    return $this->fail($field, sprintf('%s must be a valid YouTube video link.', $label));
                }

                return (string) $value;

            case 'json':
                if (is_array($value)) {
                    return $value;
                }
                $decoded = json_decode((string) $value, true);
                if (!is_array($decoded)) {
                    return $this->fail($field, sprintf('%s must be valid structured data.', $label));
                }

                return $decoded;

            case 'safe_text':
                // Rejects control characters that have no business in a form
                // field, while leaving normal punctuation and newlines alone.
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', (string) $value) === 1) {
                    return $this->fail($field, sprintf('%s contains characters that are not allowed.', $label));
                }

                return (string) $value;

            default:
                return $value;
        }
    }

    /** Fields a password must not simply repeat. */
    private function passwordContext(): array
    {
        return array_values(array_filter([
            $this->data['first_name'] ?? null,
            $this->data['last_name'] ?? null,
            isset($this->data['email']) ? explode('@', (string) $this->data['email'])[0] : null,
        ]));
    }

    private function fail(string $field, string $message): null
    {
        $this->errors[$field][] = $message;

        return null;
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }
}
