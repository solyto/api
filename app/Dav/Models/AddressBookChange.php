<?php

namespace App\Dav\Models;

use Illuminate\Database\Eloquent\Model;

class AddressBookChange extends Model
{
    protected $table = 'addressbookchanges';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'uri',
        'synctoken',
        'addressbookid',
        'operation',
    ];

    public function addressBook()
    {
        return $this->belongsTo(AddressBook::class, 'addressbookid');
    }
}
