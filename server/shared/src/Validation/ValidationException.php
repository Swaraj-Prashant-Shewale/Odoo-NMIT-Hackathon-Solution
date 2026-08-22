<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Validation;

/**
 * Raised when submitted data fails one or more rules.
 *
 * The kernel converts this into a 422 response carrying a field-by-field list
 * of problems, which the web client renders next to the offending inputs.
 */
final class ValidationException extends \RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(string $message, private readonly array $errors)
    {
        parent::__construct($message);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** The first problem reported, useful for a single-line flash message. */
    public function firstError(): string
    {
        foreach ($this->errors as $messages) {
            if ($messages !== []) {
                return (string) $messages[0];
            }
        }

        return $this->getMessage();
    }
}
