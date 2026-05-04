<?php

namespace App\Api\Libraries\Models;

use App\Api\Libraries\Factories\LibraryPlantFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryPlant extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): LibraryPlantFactory
    {
        return LibraryPlantFactory::new();
    }

    protected $table = 'library_plants';

    protected $fillable = [
        'name',
        'latin_name',
        'location',
        'sunlight',
        'current_size',
        'max_size',
        'acquired_at',
        'winter_hardy',
        'instructions',
        'cover_path',
        'link',
        'wishlist',
        'user_id',
    ];

    protected $casts = [
        'acquired_at' => 'date',
        'winter_hardy' => 'boolean',
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

}
