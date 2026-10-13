<?php

namespace App\Api\Notes\Models;

use App\Api\Notes\Factories\NoteCategoryFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NoteCategory extends Model
{
    use HasFactory;

    /**
     * Title of the server-managed personal journal folder.
     */
    public const JOURNAL_TITLE = '.journal';

    protected static function newFactory(): NoteCategoryFactory
    {
        return NoteCategoryFactory::new();
    }

    protected $fillable = [
        'title',
        'user_id',
        'parent_id',
        'sort_order',
        'is_system',
        'is_hidden',
    ];

    protected $attributes = [
        'is_system' => false,
        'is_hidden' => false,
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'is_system' => 'boolean',
        'is_hidden' => 'boolean',
    ];

    /**
     * Provision the root-level journal folder for a user, unless that user
     * already owns one. Shared by the new-user observer and the backfill
     * migration so the journal definition lives in one place.
     */
    public static function provisionJournal(User $user): self
    {
        return static::firstOrCreate(
            [
                'user_id' => $user->id,
                'title' => self::JOURNAL_TITLE,
                'parent_id' => null,
            ],
            [
                'is_system' => true,
                'is_hidden' => true,
            ]
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(NoteCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(NoteCategory::class, 'parent_id')->orderBy('sort_order');
    }

    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function getAncestors()
    {
        $ancestors = collect();
        $category = $this->parent;

        while ($category) {
            $ancestors->prepend($category);
            $category = $category->parent;
        }

        return $ancestors;
    }

    public function isRoot(): bool
    {
        return is_null($this->parent_id);
    }

    public function hasChildren(): bool
    {
        return $this->children()->count() > 0;
    }

    public function getFullPath(string $separator = ' > '): string
    {
        $path = $this->getAncestors()->pluck('title')->toArray();
        $path[] = $this->title;

        return implode($separator, $path);
    }
}
