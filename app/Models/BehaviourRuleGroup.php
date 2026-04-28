<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BehaviourRuleGroup extends Model
{
    protected $fillable = [
        'name',
        'source',
        'categories',
        'year_groups',
        'timeframe',
        'rolling_days',
        'metric',
        'counter_mode',
        'rollover_every',
        'mode',
        'reset_on_term_change',
        'mute_lower_on_first_run',
        'digest_attach_csv',
        'digest_fields',
        'staff_roles',
        'extra_emails',
        'recipient_staff',
        'recipient_parents',
        'is_active',
        'last_evaluated_at',
    ];

    protected $casts = [
        'categories' => 'array',
        'year_groups' => 'array',
        'digest_fields' => 'array',
        'staff_roles' => 'array',
        'extra_emails' => 'array',
        'rollover_every' => 'integer',
        'reset_on_term_change' => 'boolean',
        'mute_lower_on_first_run' => 'boolean',
        'digest_attach_csv' => 'boolean',
        'recipient_staff' => 'boolean',
        'recipient_parents' => 'boolean',
        'is_active' => 'boolean',
        'last_evaluated_at' => 'datetime',
    ];

    public function levels(): HasMany
    {
        return $this->hasMany(BehaviourRuleLevel::class)->orderBy('sort_order')->orderBy('threshold');
    }
}
