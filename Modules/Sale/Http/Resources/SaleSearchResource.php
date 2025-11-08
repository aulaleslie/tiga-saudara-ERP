<?php

namespace Modules\Sale\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleSearchResource extends JsonResource
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
            'reference' => $this->reference,
            'status' => $this->status,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            
            // Customer Information
            'customer' => [
                'id' => $this->customer?->id,
                'name' => $this->customer?->name,
                'email' => $this->customer?->email,
                'phone' => $this->customer?->phone,
            ],
            
            // Seller/User Information
            'seller' => [
                'id' => $this->seller?->id ?? $this->created_by,
                'name' => $this->seller?->name,
                'email' => $this->seller?->email,
            ],
            
            // Tenant Information
            'tenant' => [
                'id' => $this->tenantSetting?->id ?? $this->setting_id,
                'name' => $this->tenantSetting?->name,
                'business_registration' => $this->tenantSetting?->business_registration,
            ],
            
            // Location Information
            'location' => [
                'id' => $this->location?->id,
                'name' => $this->location?->name,
                'address' => $this->location?->address,
            ],
            
            // Financial Information
            'amounts' => [
                'subtotal' => (float) $this->total_amount - (float) $this->tax_amount - (float) $this->discount_amount + (float) $this->shipping_amount,
                'tax_amount' => (float) $this->tax_amount,
                'discount_amount' => (float) $this->discount_amount,
                'shipping_amount' => (float) $this->shipping_amount,
                'total_amount' => (float) $this->total_amount,
                'paid_amount' => (float) $this->paid_amount,
                'due_amount' => (float) $this->due_amount,
            ],
            
            // Serial Numbers Summary
            'serial_numbers' => $this->when($this->relationLoaded('details'), function () {
                return $this->details->flatMap(function ($detail) {
                    return $detail->serialNumbers->map(function ($serial) {
                        return [
                            'id' => $serial->id,
                            'serial_number' => $serial->serial_number,
                            'product_id' => $serial->product_id,
                            'product_name' => $serial->product?->name,
                        ];
                    });
                })->values();
            }),
            
            // Sale Details
            'details' => $this->when($this->relationLoaded('details'), function () {
                return $this->details->map(function ($detail) {
                    return [
                        'id' => $detail->id,
                        'product_id' => $detail->product_id,
                        'product_name' => $detail->product?->name,
                        'quantity' => $detail->quantity,
                        'unit_price' => (float) $detail->unit_price,
                        'sub_total' => (float) $detail->sub_total,
                        'serial_numbers_count' => count($detail->serial_number_ids ?? []),
                    ];
                });
            }),
        ];
    }
}
