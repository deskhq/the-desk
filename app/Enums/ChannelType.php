<?php

declare(strict_types=1);

namespace App\Enums;

enum ChannelType: string
{
    /**
     * An ordinary named channel (public or private).
     */
    case Standard = 'standard';

    /**
     * A 1:1 direct message, modelled as a nameless private channel keyed on its
     * two participants (a single participant for a self-DM).
     */
    case Direct = 'direct';

    /**
     * A multi-party direct message (3+ participants). Reserved for a future
     * group-DM issue; no channel is created with this type yet.
     */
    case GroupDirect = 'group_direct';
}
