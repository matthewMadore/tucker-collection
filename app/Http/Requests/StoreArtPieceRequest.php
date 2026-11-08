<?php

namespace App\Http\Requests;

use App\Rules\MaxTotalFileSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreArtPieceRequest extends FormRequest
{
    /**
     * Shared with the upload dialog (via the dashboard props) so the
     * client-side checks always match these rules.
     *
     * All images go up in one request, so:
     * - MAX_IMAGES must stay below PHP's max_file_uploads (20). PHP silently drops
     *   files past that limit, so with MAX_IMAGES = 20 an oversized request would be
     *   truncated to 20 and pass validation. At 19, a truncated request still fails.
     * - MAX_TOTAL_KILOBYTES must stay below PHP's post_max_size (100M).
     * See docs/project/image-upload.md.
     */
    public const int MAX_IMAGES = 19;

    public const int MAX_IMAGE_KILOBYTES = 10 * 1024;

    public const int MAX_TOTAL_KILOBYTES = 80 * 1024;

    /**
     * Accepted image file extensions. The server checks the type detected from
     * the file's contents; the dialog checks the file name's extension.
     *
     * @var list<string>
     */
    public const array ACCEPTED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Get the validation rules that apply to the request.
     *
     * Price arrives in dollars (e.g. "49.99"); the controller converts it to cents.
     * Images arrive as uploaded files, in display order.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'decimal:0,2', 'min:0.01', 'max:1000000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'images' => ['nullable', 'array', 'max:'.self::MAX_IMAGES, new MaxTotalFileSize(self::MAX_TOTAL_KILOBYTES)],
            'images.*' => [
                'required',
                File::types(self::ACCEPTED_IMAGE_EXTENSIONS)->max(self::MAX_IMAGE_KILOBYTES),
            ],
        ];
    }

    /**
     * The upload limits in the shape the upload dialog expects.
     *
     * @return array{max_images: int, max_image_bytes: int, max_total_bytes: int, accepted_extensions: list<string>}
     */
    public static function imageUploadLimits(): array
    {
        return [
            'max_images' => self::MAX_IMAGES,
            'max_image_bytes' => self::MAX_IMAGE_KILOBYTES * 1024,
            'max_total_bytes' => self::MAX_TOTAL_KILOBYTES * 1024,
            'accepted_extensions' => self::ACCEPTED_IMAGE_EXTENSIONS,
        ];
    }
}
