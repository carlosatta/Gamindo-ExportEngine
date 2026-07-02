<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExportRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'version_id',
        'status',
        'format',
        'request_payload',
        'progress',
        'attempts',
        'file_path',
        'error_message',
        'started_at',
        'completed_at',
        'next_retry_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'progress' => 'integer',
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function version()
    {
        return $this->belongsTo(Version::class);
    }
}
