<?php

namespace App\Dav\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarObject extends Model
{
    protected $table = 'calendarobjects';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'calendarid', 'uri', 'calendardata', 'lastmodified',
        'etag', 'size', 'componenttype', 'firstoccurence', 'lastoccurence', 'uid'
    ];

    public function calendar()
    {
        return $this->belongsTo(Calendar::class, 'calendarid');
    }

    public function calendarInstanceForPrincipal(string $principalUri)
    {
        return CalendarInstance::where('calendarid', $this->calendarid)
                               ->where('principaluri', $principalUri)
                               ->first();
    }
}
