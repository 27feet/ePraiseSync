<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BehaviourEmailTemplate extends Model
{
    protected $table = 'behaviour_email_templates';

    protected $fillable = [
        'key',
        'subject',
        'body_html',
        'body_text',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
