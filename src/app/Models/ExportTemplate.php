<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExportTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'version_id',
        'name',
        'request_payload',
    ];

    protected $casts = [
        'request_payload' => 'array',
    ];

    public function version()
    {
        return $this->belongsTo(Version::class);
    }
}
