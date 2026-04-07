<?php

namespace App\Api\Users\Models;

use App\Api\Users\Factories\VerificationTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VerificationToken extends Model
{
    use HasFactory;

    protected static function newFactory(): VerificationTokenFactory
    {
        return VerificationTokenFactory::new();
    }

    protected $table = 'verification_tokens';

    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
    ];
}
