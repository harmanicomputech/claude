<?php

namespace App\Support;

/**
 * Queue names, in the order workers take jobs from them. On election day
 * thousands of emails can pile up; urgent alerts and agents' SMS receipts
 * must never wait behind them.
 */
final class Queues
{
    /** SMS receipts, urgent incident alerts, PINs. */
    public const HIGH = 'high';

    /** Dashboard deliveries. */
    public const DEFAULT = 'default';

    /** Reminder SMS sent to many agents at once. */
    public const BULK = 'bulk';

    /** Notification and summary emails. */
    public const MAIL = 'mail';

    /** For `queue:work --queue=…`: always drain earlier queues first. */
    public const WORKER_ORDER = self::HIGH.','.self::DEFAULT.','.self::BULK.','.self::MAIL;
}
