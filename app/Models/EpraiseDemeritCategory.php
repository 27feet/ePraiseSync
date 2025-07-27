<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class EpraiseDemeritCategory extends Model
{

    public $timestamps = true;
    protected $fillable = [

        'epraise_name',
        'arbor_point_category_id',
        'arbor_behaviour_category_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'epraise_name' => 'string',
        'arbor_point_category_id' => 'integer',
        'arbor_behaviour_category_id' => 'integer',
    ];

    public function arborPointCategory(): BelongsTo
    {
        return $this->belongsTo(ArborPointCategory::class, 'arbor_point_category_id', 'id');
    }
}
