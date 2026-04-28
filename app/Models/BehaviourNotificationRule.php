<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BehaviourNotificationRule extends Model
{
    protected $table = 'behaviour_notification_rules';

    protected $fillable = [
        'source',                // 'demerit' | 'intervention'
        'category',              // e.g. 'Uniform', 'Late', 'Skirt'
        'timeframe',             // 'term' | 'rolling_school_days'
        'rolling_days',          // nullable, only for rolling windows
        'threshold',             // e.g. 5, 10, 15, 20
        'reset_on_term_change',  // bool
        'recipient_parents',     // bool
        'recipient_staff',       // bool
        'staff_roles',           // json array, e.g. ["tutor","head_of_year"]
        'extra_emails',          // json array of strings
        'template_key',          // string
        'is_active',             // bool
        'mode',            // 'daily_batch' | 'realtime_today'
        'metric',          // 'count' | 'sum'
        'categories',      // json|null (array of categories)
        'year_groups',     // json|null (array of year group codes like 'Y7','Y8')
        'template_key',

    ];

    protected $casts = [
        'rolling_days'          => 'integer',
        'threshold'             => 'integer',
        'reset_on_term_change'  => 'boolean',
        'recipient_parents'     => 'boolean',
        'recipient_staff'       => 'boolean',
        'staff_roles'           => 'array',
        'extra_emails'          => 'array',
        'is_active'             => 'boolean',
        'categories'            => 'array',
        'year_groups'           => 'array',
    ];

    /* ---------- Scopes ---------- */

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeFor(Builder $q, string $source, string $category): Builder
    {
        return $q->where('source', $source)->where('category', $category);
    }

    public function scopeTerm(Builder $q): Builder
    {
        return $q->where('timeframe', 'term');
    }

    public function scopeRolling(Builder $q): Builder
    {
        return $q->where('timeframe', 'rolling_school_days');
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('threshold');
    }

    public function categoriesOrSingle(): array
    {
        if (is_array($this->categories) && count($this->categories)) {
            return $this->categories;
        }
        return $this->category ? [$this->category] : [];
    }

    /**
     * Canonicalise categories to an array and keep legacy category in sync.
     */
    public function setCategoriesAttribute($value): void
    {
        $cats = is_array($value) ? array_values(array_filter($value, fn($v) => $v !== null && $v !== '')) : [];
        $this->attributes['categories'] = $cats ? json_encode($cats) : null;
        if (!empty($cats)) {
            $this->attributes['category'] = $cats[0];
        }
    }

    public function scopeRealtimeToday(Builder $q): Builder
    {
        return $q->where('mode', 'realtime_today');
    }
}
