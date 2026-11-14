<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import {
    show,
    updateStatus,
} from '@/actions/App/Http/Controllers/ArtPieceController';
import { show as publicShow } from '@/actions/App/Http/Controllers/GalleryController';
import ArtPieceImageGallery from '@/components/ArtPieceImageGallery.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

const props = defineProps<{
    artPiece: {
        id: number;
        name: string;
        description: string;
        price_cents: number;
        status: string;
        images: {
            id: number;
            url: string;
        }[];
    };
    statuses: {
        value: string;
        label: string;
    }[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: props.artPiece.name, href: show(props.artPiece.id) },
    ],
});

const statusForm = useForm({
    status: props.artPiece.status,
});

function changeStatus(status: string): void {
    statusForm.status = status;
    statusForm.patch(updateStatus.url(props.artPiece.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="artPiece.name" />

    <div class="grid gap-6 p-4 lg:grid-cols-[2fr_1fr]">
        <ArtPieceImageGallery :name="artPiece.name" :images="artPiece.images" />

        <section class="grid content-start gap-3" aria-label="Details">
            <h1 class="text-xl font-semibold">{{ artPiece.name }}</h1>
            <p class="text-lg">
                ${{ (artPiece.price_cents / 100).toFixed(2) }}
            </p>
            <p
                v-if="artPiece.description"
                class="text-muted-foreground whitespace-pre-line"
            >
                {{ artPiece.description }}
            </p>
            <p class="text-muted-foreground text-sm">
                {{ artPiece.images.length }}
                {{ artPiece.images.length === 1 ? 'image' : 'images' }}
            </p>

            <div class="grid gap-2 border-t pt-3">
                <p id="status-label" class="text-sm font-medium">Status</p>
                <div
                    role="group"
                    aria-labelledby="status-label"
                    class="grid grid-cols-2 gap-2"
                >
                    <Button
                        v-for="status in statuses"
                        :key="status.value"
                        type="button"
                        :variant="
                            artPiece.status === status.value
                                ? 'default'
                                : 'outline'
                        "
                        :aria-pressed="artPiece.status === status.value"
                        :disabled="statusForm.processing"
                        @click="changeStatus(status.value)"
                    >
                        {{ status.label }}
                    </Button>
                </div>
                <InputError :message="statusForm.errors.status" />
                <p class="text-muted-foreground text-sm">
                    <template v-if="artPiece.status === 'draft'">
                        Drafts are only visible to you. Publish to show it in
                        the public gallery.
                    </template>
                    <template v-else>
                        Visible in the public gallery.
                        <Link
                            :href="publicShow(artPiece.id)"
                            class="text-foreground underline underline-offset-4"
                        >
                            View public page
                        </Link>
                    </template>
                </p>
            </div>
        </section>
    </div>
</template>
