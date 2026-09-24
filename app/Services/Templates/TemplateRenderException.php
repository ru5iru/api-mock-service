<?php

namespace App\Services\Templates;

use RuntimeException;

final class TemplateRenderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $templatePath = '/',
        public readonly string $token = '',
        public readonly string $issueCode = 'TEMPLATE_RENDER_FAILED',
    ) {
        parent::__construct($message);
    }
}
