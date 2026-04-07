<?php

namespace App\Dav\Models;

use Illuminate\Database\Eloquent\Model;

class Principal extends Model
{
    protected $table = 'principals';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = ['uri', 'email', 'displayname'];
}
