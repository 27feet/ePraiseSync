<?php

namespace App\Models;

use App\Models\ArborPointCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ArborPointScale extends Model
{
    public $timestamps = true;
    protected $fillable = [
        'arbor_id',
        'arbor_name',
        'arbor_display_name',
    ];

    protected $casts = [
        'arbor_id' => 'integer',
        'arbor_name' => 'string',
        'arbor_display_name' => 'string',
    ];

    public function ArborPointCategories(): BelongsToMany
    {
        return $this->belongsToMany(ArborPointCategory::class, 'arbor_point_scale_id', 'arbor_id');
    }
}
