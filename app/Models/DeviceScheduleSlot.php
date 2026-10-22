<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceScheduleSlot extends Model
{
    protected $fillable = [
        'user_id',
        'slot',
        'medicine_id',
        'schedule_id',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'assigned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MedicineSchedule::class, 'schedule_id');
    }
}
