<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    protected $fillable = [
        'document_id',
        'date_received',
        'completed_date',
        'client_name',
        'name',
        'institution',
        'course',
        'qualification',
        'grade',
        'session',
        'matric_number',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
