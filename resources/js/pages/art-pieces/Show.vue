<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { show } from '@/actions/App/Http/Controllers/ArtPieceController';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Badge } from '@/components/ui/badge';
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
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: props.artPiece.name, href: show(props.artPiece.id) },
    ],
});

const imageIndex = ref(0);

const selectedImage = computed(() => props.artPiece.images[imageIndex.value]);
</script>

<template>
    <Head :title="artPiece.name" />

    <div class="grid gap-6 p-4 lg:grid-cols-[2fr_1fr]">
        <section class="grid content-start gap-3" aria-label="Images">
            <a
                v-if="selectedImage"
                :href="selectedImage.url"
                target="_blank"
                rel="noopener"
                class="bg-muted block overflow-hidden rounded-xl border"
                title="Open full size in a new tab"
            >
                <img
                    :src="selectedImage.url"
                    :alt="`${artPiece.name}, image ${imageIndex + 1} of ${artPiece.images.length}`"
                    decoding="async"
                    class="max-h-[70vh] w-full object-contain"
                />
            </a>
            <div
                v-else
                class="relative aspect-video overflow-hidden rounded-xl border"
            >
                <PlaceholderPattern />
            </div>

            <ul v-if="artPiece.images.length > 1" class="flex flex-wrap gap-2">
                <li v-for="(image, index) in artPiece.images" :key="image.id">
                    <button
                        type="button"
                        class="block size-20 overflow-hidden rounded-md border-2"
                        :class="
                            imageIndex === index
                                ? 'border-primary'
                                : 'border-transparent opacity-70 hover:opacity-100'
                        "
                        :aria-current="imageIndex === index"
                        @click="imageIndex = index"
                    >
                        <img
                            :src="image.url"
                            :alt="`Show image ${index + 1}`"
                            decoding="async"
                            class="size-full object-cover"
                        />
                    </button>
                </li>
            </ul>
        </section>

        <section class="grid content-start gap-3" aria-label="Details">
            <div class="flex items-start justify-between gap-2">
                <h1 class="text-xl font-semibold">{{ artPiece.name }}</h1>
                <Badge variant="secondary">{{ artPiece.status }}</Badge>
            </div>
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
        </section>
    </div>
</template>
