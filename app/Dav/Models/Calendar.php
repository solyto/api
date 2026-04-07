<?php

namespace App\Dav\Models;

use Illuminate\Database\Eloquent\Model;

class Calendar extends Model
{
    protected $table = 'calendars';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = ['synctoken', 'components'];

    public function objects()
    {
        return $this->hasMany(CalendarObject::class, 'calendarid');
    }
}
