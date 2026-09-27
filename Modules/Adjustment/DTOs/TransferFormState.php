<?php

namespace Modules\Adjustment\DTOs;

class TransferFormState
{
    public ?int $originLocationId;
    public ?int $destinationLocationId;

    /**
     * The transfer's single explicit stock condition (Transfer::CONDITION_GOOD
     * or Transfer::CONDITION_BREAKAGE). Null only for historical mixed-condition
     * records that have not yet been given an explicit mode.
     */
    public ?string $stockCondition;

    /**
     * @var array<int, TransferFormLineState>
     * Key is product_id for normal lines.
     * To support broken vs normal mode for the same product, the key could be combination of product_id and mode, e.g. "prod_1_normal"
     */
    public array $lines = [];

    public function __construct(
        ?int $originLocationId = null,
        ?int $destinationLocationId = null,
        ?string $stockCondition = null
    ) {
        $this->originLocationId = $originLocationId;
        $this->destinationLocationId = $destinationLocationId;
        $this->stockCondition = $stockCondition;
    }
    
    public function getLineKey(int $productId, bool $isBrokenMode): string
    {
        return $productId . '_' . ($isBrokenMode ? 'broken' : 'normal');
    }
    
    public function addLine(TransferFormLineState $line): void
    {
        $key = $this->getLineKey($line->productId, $line->isBrokenMode);
        $this->lines[$key] = $line;
    }
}
