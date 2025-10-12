<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BehaviourNotificationParentContact extends Model
{
    // Table created by the migration you just ran:
    // database/migrations/2025_09_07_xxxxxx_create_behaviour_notification_parent_contacts_table.php
    protected $table = 'behaviour_notification_parent_contacts';

    protected $fillable = [
        'student_record_id',
        'student_arbor_id',
        'title',
        'surname',
        'email',
        'email_normalized',
    ];

    protected $casts = [
        'student_record_id' => 'integer',
        'student_arbor_id'  => 'integer',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];
}
