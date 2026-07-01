<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayloadValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'version_id',
        'payload_field_id',
        'entity_type',
        'entity_id',
        'value_string',
        'value_integer',
        'value_decimal',
        'value_boolean',
        'value_datetime',
        'value_text',
    ];

    protected $casts = [
        'value_integer' => 'integer',
        'value_decimal' => 'decimal:6',
        'value_boolean' => 'boolean',
        'value_datetime' => 'datetime',
    ];

    public function version()
    {
        return $this->belongsTo(Version::class);
    }

    public function payloadField()
    {
        return $this->belongsTo(PayloadField::class);
    }
}
