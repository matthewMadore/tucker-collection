<?php

namespace App\Concerns;

use BackedEnum;

/**
 * For status enums that define a state machine: the enum lists its allowed
 * edges in allowedTransitions(); anything not listed is illegal.
 */
trait HasStatusTransitions
{
    /**
     * @return list<self>
     */
    abstract public function allowedTransitions(): array;

    /**
     * A status from a different enum is never a legal move.
     */
    public function canTransitionTo(BackedEnum $to): bool
    {
        return in_array($to, $this->allowedTransitions(), strict: true);
    }
}
