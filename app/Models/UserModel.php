<?php

namespace App\Models;

use CodeIgniter\Shield\Models\UserModel as ShieldUserModel;

/**
 * Application user model with profile fields added to Shield's user model.
 */
class UserModel extends ShieldUserModel
{
    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'username',
        'status',
        'status_message',
        'active',
        'last_active',
        'first_name',
        'last_name',
    ];
}