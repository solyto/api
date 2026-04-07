<?php

namespace App\Api\Libraries\Models;

use App\Api\Libraries\Factories\LibraryRecipeFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryRecipe extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): \App\Api\Libraries\Factories\LibraryRecipeFactory
    {
        return LibraryRecipeFactory::new();
    }

    protected $table = 'library_recipes';

    protected $fillable = [
        'title',
        'rating',
        'time_to_make',
        'description',
        'ingredients',
        'type',
        'cover_path',
        'link',
        'user_id',
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
}
