<?php

declare(strict_types=1);

namespace App\Core\Validation;

final class ValidationException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors field => message
     * @param array<string, mixed>  $old    safe values to repopulate the form
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $old = [],
        string $message = 'Please correct the highlighted fields.',
    ) {
        parent::__construct($message);
    }

    public function first(): string
    {
        $errors = $this->errors;

        return $errors === [] ? $this->getMessage() : (string) reset($errors);
    }
}
