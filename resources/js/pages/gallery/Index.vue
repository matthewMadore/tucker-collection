<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { show } from '@/actions/App/Http/Controllers/GalleryController';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Badge } from '@/components/ui/badge';

defineProps<{
    artPieces: {
        data: {
            id: number;
            name: string;
            /** null in gallery-only mode */
            price_cents: number | null;
            status: string;
            status_label: string;
            cover_image_url: string | null;
        }[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
</script>

<template>
    <Head title="Gallery" />

    <div class="grid gap-4 p-4">
        <p v-if="artPieces.data.length === 0" class="text-muted-foreground">
            No art to show yet. Check back soon.
        </p>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3">
            <Link
                v-for="artPiece in artPieces.data"
                :key="artPiece.id"
                :href="show(artPiece.id)"
                class="hover:border-primary focus-visible:ring-ring block overflow-hidden rounded-xl border transition-colors focus-visible:ring-2 focus-visible:outline-none"
            >
                <div class="bg-muted relative aspect-square">
                    <img
                        v-if="artPiece.cover_image_url"
                        :src="artPiece.cover_image_url"
                        :alt="artPiece.name"
                        loading="lazy"
                        decoding="async"
                        class="size-full object-cover"
                    />
                    <PlaceholderPattern v-else />
                    <Badge
                        v-if="artPiece.status !== 'published'"
                        class="absolute top-2 left-2"
                    >
                        {{ artPiece.status_label }}
                    </Badge>
                </div>
                <div class="p-2 sm:p-3">
                    <p class="truncate text-sm font-medium sm:text-base">
                        {{ artPiece.name }}
                    </p>
                    <p
                        v-if="artPiece.price_cents !== null"
                        class="text-muted-foreground text-sm"
                    >
                        ${{ (artPiece.price_cents / 100).toFixed(2) }}
                    </p>
                </div>
            </Link>
        </div>

        <div
            v-if="artPieces.last_page > 1"
            class="flex items-center justify-between"
        >
            <Link
                v-if="artPieces.prev_page_url"
                :href="artPieces.prev_page_url"
            >
                Previous
            </Link>
            <span v-else></span>
            <span>
                Page {{ artPieces.current_page }} of {{ artPieces.last_page }}
            </span>
            <Link
                v-if="artPieces.next_page_url"
                :href="artPieces.next_page_url"
            >
                Next
            </Link>
            <span v-else></span>
        </div>
    </div>
</template>
