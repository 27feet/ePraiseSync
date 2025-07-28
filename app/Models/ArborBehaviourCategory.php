<?php

namespace App\Models;

use App\Models\ArborPointScale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ArborBehaviourCategory extends Model
{
    public $timestamps = true;
    protected $fillable = [
        'arbor_id',
        'arbor_display_name',
        'severity',
        'active',
    ];

    protected $casts = [
        'arbor_id' => 'integer',
        'arbor_display_name' => 'string',
        'severity' => 'integer',
        'active' => 'boolean',
    ];

    public function detentionAllocations(): HasMany
    {
        return $this->hasMany(DetentionAllocation::class, 'reason_for_detention', 'arbor_id');
    }
}
