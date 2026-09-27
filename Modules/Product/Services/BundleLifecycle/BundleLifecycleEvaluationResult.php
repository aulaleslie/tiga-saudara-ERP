<?php

namespace Modules\Product\Services\BundleLifecycle;

use JsonSerializable;

class BundleLifecycleEvaluationResult implements JsonSerializable
{
    /**
     * @param bool $isEligible
     * @param array<int, string> $reasons
     * @param array<int, array<string, mixed>> $warnings
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly bool $isEligible,
        public readonly array $reasons = [],
        public readonly array $warnings = [],
        public readonly array $context = []
    ) {}

    public static function eligible(array $context = []): self
    {
        return new self(
            isEligible: true,
            reasons: [],
            warnings: [],
            context: $context
        );
    }

    public static function ineligible(array $reasons, array $warnings = [], array $context = []): self
    {
        return new self(
            isEligible: false,
            reasons: array_values(array_unique($reasons)),
            warnings: $warnings,
            context: $context
        );
    }

    public static function warning(array $warnings, array $context = []): self
    {
        $allReasons = [];
        foreach ($warnings as $w) {
            if (isset($w['reasons']) && is_array($w['reasons'])) {
                foreach ($w['reasons'] as $r) {
                    $allReasons[] = $r;
                }
            } elseif (isset($w['reason'])) {
                $allReasons[] = $w['reason'];
            }
        }

        return new self(
            isEligible: empty($warnings),
            reasons: array_values(array_unique($allReasons)),
            warnings: $warnings,
            context: $context
        );
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    public function primaryReason(): ?string
    {
        return $this->reasons[0] ?? null;
    }

    public function primaryMessage(): ?string
    {
        $reason = $this->primaryReason();
        return $reason !== null ? BundleLifecycleReason::label($reason) : null;
    }

    public function jsonSerialize(): array
    {
        return [
            'is_eligible' => $this->isEligible,
            'has_warnings' => $this->hasWarnings(),
            'reasons' => $this->reasons,
            'primary_reason' => $this->primaryReason(),
            'primary_message' => $this->primaryMessage(),
            'warnings' => $this->warnings,
            'context' => $this->context,
        ];
    }
}
