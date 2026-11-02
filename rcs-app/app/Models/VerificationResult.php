<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationResult extends Model
{
    protected $guarded = [];
    protected $casts = ['comparisons' => 'array', 'candidates' => 'array', 'manual' => 'boolean'];

    public function certificate()
    {
        return $this->belongsTo(VerificationRecord::class, 'certificate_id');
    }

    public function report()
    {
        return $this->belongsTo(VerificationRecord::class, 'report_id');
    }
}
