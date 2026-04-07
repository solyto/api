<?php

namespace App\Dav\Models;

use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    protected $table = 'cards';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = ['addressbookid', 'carddata', 'uri', 'lastmodified', 'etag', 'size'];

    public function addressBook()
    {
        return $this->belongsTo(AddressBook::class, 'addressbookid');
    }
}
