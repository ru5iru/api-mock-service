<?php

namespace App\Services\Config;

final readonly class ImportSummary
{
    public function __construct(
        public int $endpointsCreated,
        public int $endpointsUpdated,
        public int $responsesCreated,
        public int $responsesUpdated,
        public int $responsesDeleted,
        public int $disabled,
        public int $warnings,
    ) {}

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'endpoints_created' => $this->endpointsCreated,
            'endpoints_updated' => $this->endpointsUpdated,
            'responses_created' => $this->responsesCreated,
            'responses_updated' => $this->responsesUpdated,
            'responses_deleted' => $this->responsesDeleted,
            'disabled' => $this->disabled,
            'warnings' => $this->warnings,
        ];
    }
}
