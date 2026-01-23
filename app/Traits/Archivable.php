<?php

namespace App\Traits;

use App\Models\User;
use App\Scopes\ArchivingScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait Archivable
{
    /**
     * Boot the archivable trait for a model.
     *
     * @return void
     */
    public static function bootArchivable()
    {
        static::addGlobalScope(new ArchivingScope);
    }

    /**
     * Initialize the archivable trait for an instance.
     *
     * @return void
     */
    public function initializeArchivable()
    {
        if (! isset($this->casts[$this->getArchivedAtColumn()])) {
            $this->casts[$this->getArchivedAtColumn()] = 'datetime';
        }
    }

    /**
     * Get the name of the "archived_at" column.
     *
     * @return string
     */
    public function getArchivedAtColumn()
    {
        return defined('static::ARCHIVED_AT') ? static::ARCHIVED_AT : 'archived_at';
    }

    /**
     * Get the fully qualified "archived_at" column.
     *
     * @return string
     */
    public function getQualifiedArchivedAtColumn()
    {
        return $this->qualifyColumn($this->getArchivedAtColumn());
    }

    /**
     * Get the name of the "archived_by" column.
     *
     * @return string
     */
    public function getArchivedByColumn()
    {
        return defined('static::ARCHIVED_BY') ? static::ARCHIVED_BY : 'archived_by';
    }

    /**
     * Get the fully qualified "archived_by" column.
     *
     * @return string
     */
    public function getQualifiedArchivedByColumn()
    {
        return $this->qualifyColumn($this->getArchivedByColumn());
    }

    /**
     * Scope a query to only include archived records.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeArchived(Builder $query)
    {
        return $query->withoutGlobalScope(ArchivingScope::class)->whereNotNull($this->getQualifiedArchivedAtColumn());
    }

    /**
     * Scope a query to only include active (non-archived) records.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive(Builder $query)
    {
        return $query->whereNull($this->getQualifiedArchivedAtColumn());
    }

    /**
     * Determine if the model instance is archived.
     *
     * @return bool
     */
    public function isArchived()
    {
        return ! is_null($this->{$this->getArchivedAtColumn()});
    }

    /**
     * Relationship to the user who archived the record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, $this->getArchivedByColumn());
    }
}
