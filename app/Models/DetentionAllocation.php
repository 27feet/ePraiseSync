<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DetentionAllocation extends Model
{
    public $timestamps = true;

    protected $fillable = [

        'arbor_id',
        'detention_session_id',
        'student_id',
        'reason_for_detention',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'arbor_id' => 'integer',
        'detention_session_id' => 'integer',
        'student_id' => 'integer',
        'reason_for_detention' => 'integer',
    ];


    public function session(): BelongsTo
    {
        return $this->belongsTo(DetentionSession::class, 'arbor_id', 'detention_session_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(DetentionSession::class, 'arbor_id', 'student_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(ArborBehaviourCategory::class, 'arbor_id', 'reason_for_detention');
    }
}
