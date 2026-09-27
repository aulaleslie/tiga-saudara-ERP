<?php

namespace App\Services\Reports;

class StockBucketData
{
    public float $taxGood;
    public float $nonTaxGood;
    public float $taxBroken;
    public float $nonTaxBroken;

    public float $totalGood;
    public float $totalBroken;

    public function __construct(
        float $taxGood = 0.0,
        float $nonTaxGood = 0.0,
        float $taxBroken = 0.0,
        float $nonTaxBroken = 0.0
    ) {
        $this->taxGood = $taxGood;
        $this->nonTaxGood = $nonTaxGood;
        $this->taxBroken = $taxBroken;
        $this->nonTaxBroken = $nonTaxBroken;

        $this->totalGood = $this->taxGood + $this->nonTaxGood;
        $this->totalBroken = $this->taxBroken + $this->nonTaxBroken;
    }

    public static function fromRaw(array|object $raw): self
    {
        $arr = (array) $raw;
        return new self(
            (float) ($arr['quantity_tax'] ?? $arr['tax_good'] ?? 0),
            (float) ($arr['quantity_non_tax'] ?? $arr['non_tax_good'] ?? 0),
            (float) ($arr['broken_quantity_tax'] ?? $arr['tax_broken'] ?? 0),
            (float) ($arr['broken_quantity_non_tax'] ?? $arr['non_tax_broken'] ?? 0)
        );
    }

    public function add(self $other): self
    {
        return new self(
            $this->taxGood + $other->taxGood,
            $this->nonTaxGood + $other->nonTaxGood,
            $this->taxBroken + $other->taxBroken,
            $this->nonTaxBroken + $other->nonTaxBroken
        );
    }

    public function toArray(): array
    {
        return [
            'tax_good' => $this->taxGood,
            'non_tax_good' => $this->nonTaxGood,
            'tax_broken' => $this->taxBroken,
            'non_tax_broken' => $this->nonTaxBroken,
            'total_good' => $this->totalGood,
            'total_broken' => $this->totalBroken,
        ];
    }
}
