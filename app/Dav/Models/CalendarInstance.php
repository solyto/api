<?php

namespace App\Dav\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarInstance extends Model
{
    protected $table = 'calendarinstances';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'calendarid',
        'principaluri',
        'access',
        'displayname',
        'uri',
        'description',
        'calendarorder',
        'calendarcolor',
        'timezone',
        'transparent',
        'share_href',
        'share_displayname',
        'share_invitestatus',
    ];

    public const ACCESS_OWNER     = 1;
    public const ACCESS_READ      = 2;
    public const ACCESS_READWRITE = 3;
    public const STATUS_NO_RESPONSE = 1;
    public const STATUS_ACCEPTED    = 2;
    public const STATUS_DECLINED    = 3;
    public const STATUS_INVALID     = 4;

    public function calendar()
    {
        return $this->belongsTo(Calendar::class, 'calendarid');
    }

    public function scopeForPrincipal($query, string $principalUri)
    {
        return $query->where('principaluri', $principalUri);
    }

    public function scopeVisibleToPrincipal($query, string $principalUri)
    {
        return $query->where('principaluri', $principalUri)
                     ->where(function($q) {
                         $q->whereNull('share_href')
                           ->orWhere('share_invitestatus', self::STATUS_ACCEPTED);
                     });
    }

    public function scopePendingInvites($query)
    {
        return $query->where('share_invitestatus', self::STATUS_NO_RESPONSE);
    }

    public function scopeAcceptedShares($query)
    {
        return $query->where('share_invitestatus', self::STATUS_ACCEPTED);
    }

    public function isShared(): bool
    {
        return !is_null($this->share_href);
    }

    public function isPending(): bool
    {
        return $this->share_invitestatus === self::STATUS_NO_RESPONSE;
    }

    public function isAccepted(): bool
    {
        return $this->share_invitestatus === self::STATUS_ACCEPTED;
    }

    public function isReadOnly(): bool
    {
        return $this->access === self::ACCESS_READ;
    }
}
