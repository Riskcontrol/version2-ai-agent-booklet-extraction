<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'filename', 'path', 'session', 'status', 'csv_url', 'xlsx_url', 'docx_url',
        'extraction_type', 'date_received', 'completed_date', 'client_name',
    ];

    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }
}
