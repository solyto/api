<?php

namespace App\Dav\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarSubscription extends Model
{
    protected $table = 'calendarsubscriptions';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'uri',
        'principaluri',
        'source',
        'displayname',
        'refreshrate',
        'calendarorder',
        'calendarcolor',
        'striptodos',
        'stripalarms',
        'stripattachments',
        'lastmodified',
    ];

    protected $casts = [
        'calendarorder'    => 'integer',
        'striptodos'       => 'boolean',
        'stripalarms'      => 'boolean',
        'stripattachments' => 'boolean',
        'lastmodified'     => 'integer',
    ];

    public function scopeForPrincipal($query, string $principalUri)
    {
        return $query->where('principaluri', $principalUri);
    }

    public function isShared(): bool
    {
        return !empty($this->source);
    }

    public function isExternal(): bool
    {
        return $this->source && str_starts_with($this->source, 'http');
    }
}
