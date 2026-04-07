<?php

namespace App\Dav\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarChange extends Model
{
    protected $table = 'calendarchanges';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'uri',
        'synctoken',
        'calendarid',
        'operation',
    ];

    public function calendar()
    {
        return $this->belongsTo(Calendar::class, 'calendarid');
    }
}
