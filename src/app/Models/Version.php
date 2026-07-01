<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Version extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function versionPlayers()
    {
        return $this->hasMany(VersionPlayer::class);
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

    public function payloadFields()
    {
        return $this->hasMany(PayloadField::class);
    }

    public function payloadValues()
    {
        return $this->hasMany(PayloadValue::class);
    }

    public function exportRequests()
    {
        return $this->hasMany(ExportRequest::class);
    }

    public function exportTemplates()
    {
        return $this->hasMany(ExportTemplate::class);
    }
}
