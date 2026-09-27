<?php

namespace App\DTOs;

class DateAdjustmentCommand
{
    public function __construct(
        public string $reportingAction = 'keep', // 'keep', 'set', 'clear'
        public ?string $reportingDate = null,
        public string $dueDateAction = 'keep', // 'keep', 'set'
        public ?string $dueDate = null,
        public string $reason = '',
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            reportingAction: $data['reporting_action'] ?? 'keep',
            reportingDate: $data['reporting_date'] ?? null,
            dueDateAction: $data['due_date_action'] ?? 'keep',
            dueDate: $data['due_date'] ?? null,
            reason: trim((string) ($data['reason'] ?? '')),
        );
    }
}
