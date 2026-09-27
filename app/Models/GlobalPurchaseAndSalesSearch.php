<?php

namespace App\Models;

use App\Models\User;
use Modules\Setting\Entities\Setting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Global Purchase and Sales Search Audit Model
 *
 * Tracks all global search operations for audit, analytics, and compliance purposes.
 */
class GlobalPurchaseAndSalesSearch extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'setting_id',
        'search_query',
        'search_type',
        'transaction_types',
        'filters_applied',
        'results_count',
        'response_time_ms',
        'tenant_context',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'transaction_types' => 'array',
        'filters_applied' => 'array',
        'results_count' => 'integer',
        'response_time_ms' => 'integer',
    ];

    /**
     * Relationship with User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship with Setting (Tenant)
     */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    /**
     * Scope for filtering by search type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('search_type', $type);
    }

    /**
     * Scope for filtering by user
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for filtering by tenant
     */
    public function scopeByTenant($query, int $settingId)
    {
        return $query->where('setting_id', $settingId);
    }

    /**
     * Scope for filtering by date range
     */
    public function scopeDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Get search type options
     */
    public static function getSearchTypes(): array
    {
        return [
            'serial' => 'Serial Number',
            'purchase_ref' => 'Purchase Reference',
            'sales_ref' => 'Sales Reference',
            'supplier' => 'Supplier Name',
            'customer' => 'Customer Name',
            'all' => 'All Fields',
        ];
    }
}