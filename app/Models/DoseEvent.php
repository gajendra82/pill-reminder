<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoseEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REMINDED = 'reminded';

    public const STATUS_SENSOR_DETECTED = 'sensor_detected';

    public const STATUS_TAKEN = 'taken';

    public const STATUS_MISSED = 'missed';

    public const STATUS_SKIPPED = 'skipped';

    public const CONFIRMATION_USER = 'user';

    public const CONFIRMATION_SCHEDULER = 'scheduler';

    /**
     * Values allowed by the dose_events.status enum.
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_REMINDED,
        self::STATUS_SENSOR_DETECTED,
        self::STATUS_TAKEN,
        self::STATUS_MISSED,
        self::STATUS_SKIPPED,
    ];

    /**
     * Statuses a person may record. Reminder delivery and sensor detection are not this API.
     *
     * @var list<string>
     */
    public const USER_RECORDABLE_STATUSES = [
        self::STATUS_TAKEN,
        self::STATUS_MISSED,
        self::STATUS_SKIPPED,
    ];

    /**
     * Open rows may move to a manual outcome. A recorded outcome is not replaced.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => self::USER_RECORDABLE_STATUSES,
        self::STATUS_REMINDED => self::USER_RECORDABLE_STATUSES,
        self::STATUS_SENSOR_DETECTED => self::USER_RECORDABLE_STATUSES,
    ];

    protected $fillable = [
        'medicine_schedule_id',
        'scheduled_for',
        'reminder_triggered_at',
        'sensor_detected_at',
        'confirmed_taken_at',
        'missed_at',
        'status',
        'confirmation_method',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'reminder_triggered_at' => 'datetime',
            'sensor_detected_at' => 'datetime',
            'confirmed_taken_at' => 'datetime',
            'missed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MedicineSchedule::class, 'medicine_schedule_id');
    }
}
