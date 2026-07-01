<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
    ];

    public function versionPlayers()
    {
        return $this->hasMany(VersionPlayer::class);
    }

    public function versions()
    {
        return $this->belongsToMany(Version::class, 'version_players')
            ->withPivot('id')
            ->withTimestamps();
    }
}
