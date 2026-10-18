<?php

namespace App\Services\Mutations;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Carries template diagnostics back to any editor without depending on Livewire. */
final class ResponseValidationException extends ValidationException
{
    public function __construct(array $errors, public readonly ?array $templateIssues = null)
    {
        parent::__construct(Validator::make([], []));
        $this->validator->errors()->merge($errors);
    }
}
