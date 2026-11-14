<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { store } from '@/actions/App/Http/Controllers/ArtPieceController';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { ImageUploadLimits } from '@/types';

const props = defineProps<{
    /**
     * From StoreArtPieceRequest, so these checks match server validation.
     * They are a convenience only; the server still validates every file.
     */
    limits: ImageUploadLimits;
}>();

/**
 * Display labels for extensions; anything else falls back to upper case.
 */
const extensionLabels: Record<string, string> = {
    jpg: 'JPEG',
    jpeg: 'JPEG',
    png: 'PNG',
    webp: 'WebP',
};

const open = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

/**
 * dragenter/dragleave fire for every child element the cursor crosses,
 * so a counter (not a boolean) tracks whether a drag is over the zone.
 */
const dragDepth = ref(0);

/**
 * Messages about files that were not added on the most recent drop/pick.
 */
const rejectionNotices = ref<string[]>([]);

/**
 * Object URLs for the thumbnails, kept in the same order as form.images.
 */
const previewUrls = ref<string[]>([]);

const form = useForm({
    name: '',
    price: '',
    description: '',
    images: [] as File[],
});

/**
 * "JPEG, PNG, WebP": the accepted types from the server, without duplicates.
 */
const acceptedTypesLabel = computed(() =>
    [...new Set(props.limits.accepted_extensions.map(extensionLabel))].join(
        ', ',
    ),
);

const fileInputAccept = computed(() =>
    props.limits.accepted_extensions
        .map((extension) => `.${extension}`)
        .join(','),
);

const totalBytes = computed(() =>
    form.images.reduce((sum, image) => sum + image.size, 0),
);

const remainingBytes = computed(() =>
    Math.max(0, props.limits.max_total_bytes - totalBytes.value),
);

const isFull = computed(
    () =>
        form.images.length >= props.limits.max_images ||
        remainingBytes.value === 0,
);

/**
 * Two limits apply (image count and total size); the upload is as full
 * as whichever one is closer to its limit.
 */
const capacityUsedPercent = computed(() => {
    const countPercent = form.images.length / props.limits.max_images;
    const sizePercent = totalBytes.value / props.limits.max_total_bytes;

    return Math.min(100, Math.round(Math.max(countPercent, sizePercent) * 100));
});

/**
 * Binary units, matching how Laravel's File rule measures "10mb".
 */
function formatBytes(bytes: number): string {
    if (bytes === 0) {
        return '0 KB';
    }

    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Checked by file name rather than file.type: browsers sometimes report an
 * empty MIME type for real images, and the server accepts by extension too.
 */
function fileExtension(file: File): string {
    const dotIndex = file.name.lastIndexOf('.');

    return dotIndex === -1 ? '' : file.name.slice(dotIndex + 1).toLowerCase();
}

function extensionLabel(extension: string): string {
    return extensionLabels[extension] ?? extension.toUpperCase();
}

function pluralize(count: number, singular: string, plural: string): string {
    return count === 1 ? singular : plural;
}

function addFiles(fileList: FileList | null) {
    if (!fileList) {
        return;
    }

    const notices: string[] = [];
    let wrongTypeCount = 0;
    let overCountLimit = 0;

    for (const file of Array.from(fileList)) {
        if (!props.limits.accepted_extensions.includes(fileExtension(file))) {
            wrongTypeCount++;
        } else if (file.size > props.limits.max_image_bytes) {
            notices.push(
                `${file.name} is ${formatBytes(file.size)}. The limit is ${formatBytes(props.limits.max_image_bytes)} per image.`,
            );
        } else if (form.images.length >= props.limits.max_images) {
            overCountLimit++;
        } else if (file.size > remainingBytes.value) {
            notices.push(
                `${file.name} (${formatBytes(file.size)}) would exceed the ${formatBytes(props.limits.max_total_bytes)} total. ${formatBytes(remainingBytes.value)} left.`,
            );
        } else {
            form.images.push(file);
            previewUrls.value.push(URL.createObjectURL(file));
        }
    }

    if (wrongTypeCount > 0) {
        notices.push(
            `Skipped ${wrongTypeCount} ${pluralize(wrongTypeCount, "file that isn't", "files that aren't")} an accepted image type (${acceptedTypesLabel.value}).`,
        );
    }

    if (overCountLimit > 0) {
        notices.push(
            `Only ${props.limits.max_images} images per piece. ${overCountLimit} ${pluralize(overCountLimit, "wasn't", "weren't")} added.`,
        );
    }

    rejectionNotices.value = notices;
    form.clearErrors('images');
}

function openFilePicker() {
    if (!isFull.value) {
        fileInput.value?.click();
    }
}

function handleDrop(event: DragEvent) {
    dragDepth.value = 0;
    addFiles(event.dataTransfer?.files ?? null);
}

function handleFileInputChange(event: Event) {
    const input = event.target as HTMLInputElement;
    addFiles(input.files);

    // Clear it so picking the same file again still fires "change".
    input.value = '';
}

function removeImage(index: number) {
    URL.revokeObjectURL(previewUrls.value[index]);
    previewUrls.value.splice(index, 1);
    form.images.splice(index, 1);
    rejectionNotices.value = [];

    // Errors are keyed by index ("images.2"), which shift after a removal.
    form.clearErrors();
}

function clearImages() {
    previewUrls.value.forEach((url) => URL.revokeObjectURL(url));
    previewUrls.value = [];
    rejectionNotices.value = [];
}

/**
 * Laravel keys errors for array items as "images.0", "images.1", ...
 */
function imageError(index: number): string | undefined {
    return (form.errors as Record<string, string>)[`images.${index}`];
}

/**
 * Keep the selected images when the dialog is closed (a draft), but drop
 * notices about the previous drop so reopening starts clean.
 */
watch(open, (isOpen) => {
    if (!isOpen) {
        rejectionNotices.value = [];
    }
});

function handleSubmit() {
    form.post(store.url(), {
        onSuccess: () => {
            clearImages();
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <button>Add Art Piece</button>
        </DialogTrigger>

        <DialogContent class="max-h-[90vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Add a new art piece</DialogTitle>
                <DialogDescription>
                    Enter the details for the new piece.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="handleSubmit">
                <div class="grid gap-2">
                    <label for="name">Name</label>
                    <input id="name" type="text" v-model="form.name" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <label for="price">Price</label>
                    <input
                        id="price"
                        type="text"
                        inputmode="decimal"
                        v-model="form.price"
                    />
                    <InputError :message="form.errors.price" />
                </div>

                <div class="grid gap-2">
                    <label for="description">Description</label>
                    <textarea
                        id="description"
                        v-model="form.description"
                    ></textarea>
                    <InputError :message="form.errors.description" />
                </div>

                <div class="grid gap-2">
                    <label for="images">Images</label>
                    <div
                        role="button"
                        tabindex="0"
                        :aria-disabled="isFull"
                        class="text-muted-foreground flex flex-col items-center justify-center gap-1 rounded-md border-2 border-dashed p-6 text-center text-sm transition-colors"
                        :class="
                            isFull
                                ? 'border-border cursor-not-allowed opacity-60'
                                : dragDepth > 0
                                  ? 'border-primary bg-accent cursor-pointer'
                                  : 'border-border hover:bg-accent/50 cursor-pointer'
                        "
                        @click="openFilePicker"
                        @keydown.enter.prevent="openFilePicker"
                        @keydown.space.prevent="openFilePicker"
                        @dragenter.prevent="dragDepth++"
                        @dragover.prevent
                        @dragleave.prevent="dragDepth--"
                        @drop.prevent="handleDrop"
                    >
                        <template v-if="isFull">
                            <span class="text-foreground font-medium">
                                Upload is full
                            </span>
                            <span> Remove an image to add another. </span>
                        </template>
                        <template v-else>
                            <span class="text-foreground font-medium">
                                Drag and drop images here
                            </span>
                            <span>
                                or click to choose files ({{
                                    acceptedTypesLabel
                                }}, up to
                                {{ formatBytes(limits.max_image_bytes) }} each)
                            </span>
                        </template>
                    </div>
                    <input
                        id="images"
                        ref="fileInput"
                        type="file"
                        class="hidden"
                        multiple
                        :accept="fileInputAccept"
                        @change="handleFileInputChange"
                    />

                    <div class="grid gap-1">
                        <div
                            class="text-muted-foreground flex justify-between text-sm"
                        >
                            <span>
                                {{ form.images.length }} of
                                {{ limits.max_images }} images ·
                                {{ formatBytes(totalBytes) }} of
                                {{ formatBytes(limits.max_total_bytes) }}
                            </span>
                            <span>{{ capacityUsedPercent }}% full</span>
                        </div>
                        <div
                            class="bg-muted h-1.5 w-full overflow-hidden rounded-full"
                            role="meter"
                            aria-label="Upload capacity used"
                            :aria-valuenow="capacityUsedPercent"
                            aria-valuemin="0"
                            aria-valuemax="100"
                        >
                            <div
                                class="bg-primary h-full rounded-full transition-all"
                                :style="{ width: `${capacityUsedPercent}%` }"
                            ></div>
                        </div>
                    </div>

                    <ul
                        v-if="rejectionNotices.length > 0"
                        class="grid gap-1 text-sm text-amber-700 dark:text-amber-400"
                        role="status"
                    >
                        <li v-for="notice in rejectionNotices" :key="notice">
                            {{ notice }}
                        </li>
                    </ul>

                    <ul
                        v-if="form.images.length > 0"
                        class="grid grid-cols-3 gap-2"
                    >
                        <li
                            v-for="(image, index) in form.images"
                            :key="previewUrls[index]"
                            class="grid gap-1"
                        >
                            <div class="relative">
                                <img
                                    :src="previewUrls[index]"
                                    :alt="image.name"
                                    class="aspect-square w-full rounded-md object-cover"
                                />
                                <button
                                    type="button"
                                    class="bg-background/80 absolute top-1 right-1 rounded px-1.5 text-xs"
                                    :aria-label="`Remove ${image.name}`"
                                    @click="removeImage(index)"
                                >
                                    ✕
                                </button>
                            </div>
                            <p
                                class="text-muted-foreground truncate text-xs"
                                :title="image.name"
                            >
                                {{ image.name }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{ extensionLabel(fileExtension(image)) }} ·
                                {{ formatBytes(image.size) }}
                            </p>
                            <InputError :message="imageError(index)" />
                        </li>
                    </ul>
                    <InputError :message="form.errors.images" />
                </div>

                <progress
                    v-if="form.progress"
                    :value="form.progress.percentage"
                    max="100"
                    class="w-full"
                ></progress>

                <DialogFooter
                    class="bg-background sticky -bottom-6 -mb-6 pt-2 pb-6"
                >
                    <button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Saving...' : 'Submit' }}
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
