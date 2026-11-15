<?php

namespace App\Concerns;

use BackedEnum;
use DomainException;
use Illuminate\Support\Str;

/**
 * For models whose `status` is cast to an enum using HasStatusTransitions.
 * The only way status should change: illegal moves throw instead of saving.
 */
trait TransitionsStatus
{
    /**
     * @param  array<string, mixed>  $attributes  other columns saved with the move
     *
     * @throws DomainException
     */
    public function transitionTo(BackedEnum $to, array $attributes = []): void
    {
        if (! $this->status->canTransitionTo($to)) {
            $model = Str::snake(class_basename($this), ' ');

            throw new DomainException("Cannot change {$model} from {$this->status->value} to {$to->value}.");
        }

        $this->update(['status' => $to, ...$attributes]);
    }
}
