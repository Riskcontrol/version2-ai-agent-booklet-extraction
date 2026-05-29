<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'filename', 'path', 'session', 'status', 'csv_url', 'xlsx_url', 'docx_url',
        'extraction_type', 'date_received', 'completed_date', 'client_name',
        'user_email', 'partner_request_id', 'payment_reference', 'api_key_tier', 'page_start', 'page_end',
        'pages_requested', 'pages_processed', 'pages_with_results', 'result_rows', 'credits_reserved',
        'credits_consumed', 'credits_refunded', 'credit_status', 'failed_reason',
    ];

    protected $casts = [
        'page_start' => 'integer',
        'page_end' => 'integer',
        'pages_requested' => 'integer',
        'pages_processed' => 'integer',
        'pages_with_results' => 'integer',
        'result_rows' => 'integer',
        'credits_reserved' => 'integer',
        'credits_consumed' => 'integer',
        'credits_refunded' => 'integer',
    ];

    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }
}
