<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoActivityEvent extends Model
{
    public const TYPE_SYNCED = 'demo_schedule_synced';

    public const TYPE_TRIGGERED = 'demo_reminder_triggered';

    public const TYPE_TAKEN = 'demo_taken';

    public const TYPE_MISSED = 'demo_missed';

    public const SOURCE_READ_BACK = 'server_read_back';

    public const SOURCE_DEVICE = 'device';

    public const SOURCE_USER = 'user';

    public const SOURCE_SCHEDULER = 'scheduler';

    /**
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_SYNCED,
        self::TYPE_TRIGGERED,
        self::TYPE_TAKEN,
        self::TYPE_MISSED,
    ];

    /**
     * @var array<string, string>
     */
    public const DESCRIPTIONS = [
        self::TYPE_SYNCED => 'DEMO. The schedule time was verified by a Blynk read-back. This does not mean the buzzer rang.',
        self::TYPE_TRIGGERED => 'DEMO. The device reported that the reminder was triggered. This does not mean the medicine was taken.',
        self::TYPE_TAKEN => 'DEMO. The user confirmed that the medicine was taken during the demo.',
        self::TYPE_MISSED => 'DEMO. A confirmed demo reminder passed its grace period without a demo taken confirmation.',
    ];

    protected $fillable = [
        'medicine_schedule_id',
        'event_type',
        'scheduled_for',
        'occurred_at',
        'confirmation_method',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MedicineSchedule::class, 'medicine_schedule_id');
    }
}
