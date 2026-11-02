<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationBatch extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['archive_path', 'worker_run'];
    protected $casts = [
        'inventory' => 'array', 'notices' => 'array', 'size' => 'integer', 'bytes_received' => 'integer',
        'pages_requested' => 'integer', 'pages_processed' => 'integer', 'pages_with_results' => 'integer',
        'files_processed' => 'integer', 'revision' => 'integer', 'credits_reserved' => 'integer',
        'credits_consumed' => 'integer', 'credits_refunded' => 'integer',
    ];
}
