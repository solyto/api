<?php

namespace App\Dav\Models;

use Illuminate\Database\Eloquent\Model;

class AddressBook extends Model
{
    protected $table = 'addressbooks';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = ['principaluri', 'displayname', 'uri', 'description', 'synctoken', 'color'];

    public function cards()
    {
        return $this->hasMany(Card::class, 'addressbookid');
    }
}
