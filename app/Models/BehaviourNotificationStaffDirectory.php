<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BehaviourNotificationStaffDirectory extends Model
{
    protected $table = 'behaviour_notification_staff_directory';

    protected $fillable = [
        'display_name',
        'email',
        'normalized_name',
    ];
}
