<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

/**
 * A site's opening hours as stored in site_profiles.business_hours: which days, from when to when,
 * in a shape close to schema.org's OpeningHoursSpecification —
 * `{days: 'mon-fri'|'mon-sat'|'mon-sun'|'appointment', opens: 'HH:MM', closes: 'HH:MM'}`.
 * "By appointment" keeps its times out: it has none. The one place the shape is checked, for the
 * settings screen that writes it and the storefront that prints it, so a stored value the shape
 * does not allow is read as no hours at all (nothing printed) rather than half-printed.
 */
final readonly class BusinessHours
{
    /** The day ranges offered, each with the schema.org days it covers. */
    public const array DAYS = [
        'mon-fri' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        'mon-sat' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        'mon-sun' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
        'appointment' => [],
    ];

    /** The times offered for opening and closing (the design's list). */
    public const array TIMES = ['06:00', '06:30', '07:00', '07:30', '08:00', '08:30', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00',
        '15:00', '16:00', '16:30', '17:00', '17:30', '18:00', '19:00', '20:00', '21:00', '22:00'];

    private function __construct(
        public string $days,
        public ?string $opens,
        public ?string $closes,
    ) {}

    /** Hours from their parts, or null when they do not make a valid range (closing must follow opening). */
    public static function of(string $days, ?string $opens, ?string $closes): ?self
    {
        if (! array_key_exists($days, self::DAYS)) {
            return null;
        }
        if ($days === 'appointment') {
            return new self($days, null, null);
        }
        if (! self::isTime($opens) || ! self::isTime($closes) || strcmp((string) $closes, (string) $opens) <= 0) {
            return null;
        }

        return new self($days, $opens, $closes);
    }

    /** The stored value read back; anything that is not the shape above reads as no hours. */
    public static function from(mixed $stored): ?self
    {
        if (! is_array($stored) || ! is_string($stored['days'] ?? null)) {
            return null;
        }
        $opens = $stored['opens'] ?? null;
        $closes = $stored['closes'] ?? null;

        return self::of($stored['days'], is_string($opens) ? $opens : null, is_string($closes) ? $closes : null);
    }

    /** A 24-hour "HH:MM". */
    public static function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $value) === 1;
    }

    public function byAppointment(): bool
    {
        return $this->days === 'appointment';
    }

    /**
     * The stored shape.
     *
     * @return array{days: string, opens?: string, closes?: string}
     */
    public function toArray(): array
    {
        return $this->opens === null || $this->closes === null
            ? ['days' => $this->days]
            : ['days' => $this->days, 'opens' => $this->opens, 'closes' => $this->closes];
    }

    /**
     * schema.org openingHoursSpecification entries (none for "by appointment").
     *
     * @return list<array<string, mixed>>
     */
    public function openingHoursSpecification(): array
    {
        if ($this->opens === null || $this->closes === null) {
            return [];
        }

        return [[
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => self::DAYS[$this->days],
            'opens' => $this->opens,
            'closes' => $this->closes,
        ]];
    }
}
