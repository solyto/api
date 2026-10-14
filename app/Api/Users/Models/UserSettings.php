<?php

namespace App\Api\Users\Models;

use App\Api\Users\Factories\UserSettingsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSettings extends Model
{
    use HasFactory;

    protected static function newFactory(): UserSettingsFactory
    {
        return UserSettingsFactory::new();
    }

    protected $table = 'user_settings';

    protected $fillable = [
        'user_id',
        'navigation',
        'timezone',
        'date_format',
        'time_format',
        'language',
        'first_visit',
        'check_in_settings',
        'journal_enabled',
        'journal_calendar_mark',
        'check_in_calendar_mark',
        'weather_city',
        'weather_latitude',
        'weather_longitude',
        'temperature_unit',
    ];

    protected $casts = [
        'check_in_settings' => 'array',
        'first_visit' => 'boolean',
        'journal_enabled' => 'boolean',
        'journal_calendar_mark' => 'boolean',
        'check_in_calendar_mark' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
