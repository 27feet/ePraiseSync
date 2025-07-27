<?php

namespace App\Models;

use App\Models\ArborPointScale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ArborPointCategory extends Model
{
    public $timestamps = true;
    protected $fillable = [
        'arbor_id',
        'arbor_display_name',
        'arbor_point_scale_id',
    ];

    protected $casts = [
        'arbor_id' => 'integer',
        'arbor_display_name' => 'string',
        'arbor_point_scale_id' => 'integer',
    ];

    public function ArborPointScale(): HasOne
    {
        return $this->hasOne(ArborPointScale::class, 'arbor_id', 'arbor_point_scale_id');
    }

    public function EpraisePointCategories(): HasMany
    {
        return $this->hasMany(EpraisePointCategory::class, 'arbor_point_category_id', 'id');
    }
}
