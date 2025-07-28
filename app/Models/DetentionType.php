<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DetentionType extends Model
{
    public $timestamps = true;

    protected $fillable = [

        'arbor_id',
        'academic_year_id',
        'name',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'arbor_id' => 'integer',
        'academic_year_id' => 'integer',
        'name' => 'string',
    ];

    public function sessions(): BelongsTo
    {
        return $this->belongsTo(DetentionSession::class, 'arbor_id', 'detention_type_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'arbor_id', 'academic_year_id');
    }
}
