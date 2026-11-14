<?php

namespace App\Http\Requests;

use App\Enums\ArtPieceStatus;
use App\Models\ArtPiece;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateArtPieceStatusRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ArtPieceStatus::class)],
        ];
    }

    /**
     * Reject moves the status state machine doesn't allow (e.g. Sold → Draft).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $artPiece = $this->route('artPiece');
                $to = ArtPieceStatus::tryFrom((string) $this->input('status'));

                if ($artPiece instanceof ArtPiece && $to !== null && ! $artPiece->status->canTransitionTo($to)) {
                    $validator->errors()->add('status', __("Can't change status from :from to :to.", [
                        'from' => $artPiece->status->label(),
                        'to' => $to->label(),
                    ]));
                }
            },
        ];
    }
}
