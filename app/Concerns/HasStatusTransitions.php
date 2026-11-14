<?php

namespace App\Concerns;

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

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), strict: true);
    }
}
