<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehaviourRuleLevel extends Model
{
    protected $fillable = [
        'behaviour_rule_group_id',
        'threshold',
        'template_key',
        'recipient_staff',
        'recipient_parents',
        'staff_roles',
        'extra_emails',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'recipient_staff' => 'boolean',
        'recipient_parents' => 'boolean',
        'staff_roles' => 'array',
        'extra_emails' => 'array',
        'is_active' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(BehaviourRuleGroup::class, 'behaviour_rule_group_id');
    }
}
