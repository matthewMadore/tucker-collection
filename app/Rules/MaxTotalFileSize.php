<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Fails when the uploaded files in an array together exceed a size budget.
 * Per-file size and type are left to the field's own rules (e.g. File::types()->max()).
 */
class MaxTotalFileSize implements ValidationRule
{
    public function __construct(public int $maxKilobytes) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $totalBytes = array_sum(array_map(
            fn (mixed $file): int => $file instanceof UploadedFile ? (int) $file->getSize() : 0,
            $value,
        ));

        if ($totalBytes > $this->maxKilobytes * 1024) {
            $fail(__('The :field may not total more than :megabytes MB.', [
                'field' => $attribute,
                'megabytes' => $this->maxKilobytes / 1024,
            ]));
        }
    }
}
