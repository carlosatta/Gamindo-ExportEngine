<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VersionPlayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'version_id',
        'player_id',
        'external_player_id',
        'registered_at',
        'language',
        'utm_source',
        'company',
        'marketing_optin',
        'status',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'marketing_optin' => 'boolean',
    ];

    public function version()
    {
        return $this->belongsTo(Version::class);
    }

    public function player()
    {
        return $this->belongsTo(Player::class);
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function answers()
    {
        return $this->hasMany(Answer::class);
    }

    public function rewards()
    {
        return $this->hasMany(Reward::class);
    }
}
