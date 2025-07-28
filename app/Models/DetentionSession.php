<?php

namespace App\Models;

use App\Models\DetentionAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DetentionSession extends Model
{
    public $timestamps = true;

    protected $fillable = [

        'arbor_id',
        'detention_type_id',
        'start_date_time',
        'end_date_time',
        'durationMinutes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'arbor_id' => 'integer',
        'detention_type_id' => 'integer',
        'start_date_time' => 'datetime',
        'end_date_time' => 'datetime',
        'durationMinutes' => 'float',
    ];


    public function detention_type(): HasOne
    {
        return $this->hasOne(DetentionType::class, 'arbor_id', 'detention_type_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(DetentionAllocation::class, 'detention_session_id', 'arbor_id');
    }
}
