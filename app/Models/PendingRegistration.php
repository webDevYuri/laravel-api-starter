<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PendingRegistration extends Model
{
    use HasUuids;

    protected $fillable = [
        'email', 'password', 'fname', 'mname', 'lname',
        'last_otp_sent_at', 'expires_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_otp_sent_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function otpChallenges(): HasMany
    {
        return $this->hasMany(OtpChallenge::class);
    }
}
