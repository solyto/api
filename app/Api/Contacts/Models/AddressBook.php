<?php

namespace App\Api\Contacts\Models;

use App\Api\Contacts\Factories\AddressBookFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AddressBook extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): AddressBookFactory
    {
        return AddressBookFactory::new();
    }

    protected $fillable = [
        'user_id',
        'title',
        'color',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
