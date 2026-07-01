<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'version_id',
        'player_id',
        'version_player_id',
        'transaction_id',
        'type',
        'amount',
        'currency',
        'occurred_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    public function version()
    {
        return $this->belongsTo(Version::class);
    }

    public function player()
    {
        return $this->belongsTo(Player::class);
    }

    public function versionPlayer()
    {
        return $this->belongsTo(VersionPlayer::class);
    }
}
