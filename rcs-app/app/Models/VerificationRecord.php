<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationRecord extends Model
{
    protected $guarded = [];
    protected $casts = ['fields' => 'array', 'evidence' => 'array', 'page' => 'integer'];
}
