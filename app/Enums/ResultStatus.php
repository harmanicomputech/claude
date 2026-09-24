<?php

namespace App\Enums;

enum ResultStatus: string
{
    /** The PU's official result (only one per PU). */
    case Accepted = 'accepted';

    /** A correction waiting for a coordinator to approve or reject it. */
    case Pending = 'pending';

    /** A correction a coordinator turned down. */
    case Rejected = 'rejected';

    /** A formerly accepted result replaced by an approved correction. */
    case Superseded = 'superseded';
}
