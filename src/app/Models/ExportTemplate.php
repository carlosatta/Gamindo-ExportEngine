<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExportTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'definition',
    ];

    protected $casts = [
        'definition' => 'array',
    ];
}
