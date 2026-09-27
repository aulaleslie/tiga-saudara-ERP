<?php

namespace Modules\Sale\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SerialNumberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'serial_number' => $this->serial_number,
            'product' => [
                'id' => $this->product?->id,
                'name' => $this->product?->name,
                'sku' => $this->product?->sku,
                'category_id' => $this->product?->category_id,
            ],
            'location' => [
                'id' => $this->location?->id,
                'name' => $this->location?->name,
                'address' => $this->location?->address,
            ],
            'status' => $this->status ?? 'unknown',
            'tax_classification' => $this->tax_id ? [
                'id' => $this->tax_id,
                'name' => $this->tax?->name ?? 'N/A',
            ] : null,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
