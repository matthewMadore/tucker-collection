/**
 * Mirrors StoreArtPieceRequest::imageUploadLimits().
 */
export type ImageUploadLimits = {
    max_images: number;
    max_image_bytes: number;
    max_total_bytes: number;
    accepted_extensions: string[];
};
