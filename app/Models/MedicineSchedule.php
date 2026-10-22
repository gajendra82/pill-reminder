<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicineSchedule extends Model
{
    protected $fillable = [
        'medicine_id',
        'scheduled_time',
        'days_of_week',
        'starts_on',
        'ends_on',
        'grace_period_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'days_of_week' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'grace_period_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function doseEvents(): HasMany
    {
        return $this->hasMany(DoseEvent::class);
    }
}
