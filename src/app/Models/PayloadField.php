<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayloadField extends Model
{
    use HasFactory;

    protected $fillable = [
        'version_id',
        'entity_type',
        'event_type',
        'code',
        'label',
        'data_type',
        'is_filterable',
        'is_sortable',
        'is_aggregatable',
    ];

    protected $casts = [
        'is_filterable' => 'boolean',
        'is_sortable' => 'boolean',
        'is_aggregatable' => 'boolean',
    ];

    public function version()
    {
        return $this->belongsTo(Version::class);
    }

    public function payloadValues()
    {
        return $this->hasMany(PayloadValue::class);
    }
}
