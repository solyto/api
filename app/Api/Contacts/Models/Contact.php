<?php

namespace App\Api\Contacts\Models;

use App\Api\Contacts\Factories\ContactFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

    protected $fillable = [
        'address_book_id',
        'first_name',
        'last_name',
        'middle_name',
        'email',
        'phone',
        'organization',
        'note',
        'address',
        'city',
        'postal_code',
        'country',
        'groups',
        'photo',
        'user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function addressBook(): BelongsTo
    {
        return $this->belongsTo(AddressBook::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
