<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import StoreArtPiece from '@/components/StoreArtPiece.vue';
import type { ImageUploadLimits } from '@/types';
import { Badge } from '@/components/ui/badge';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

defineProps<{
    artPieces: {
        data: {
            id: number;
            name: string;
            price_cents: number;
            status: string;
            images: string[];
        }[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    imageUploadLimits: ImageUploadLimits;
}>();
</script>

<template>
    <Head title="Dashboard" />

    <StoreArtPiece :limits="imageUploadLimits" />

    <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
        <p v-if="artPieces.data.length === 0">No art pieces yet.</p>

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div
                v-for="artPiece in artPieces.data"
                :key="artPiece.id"
                class="border-sidebar-border/70 dark:border-sidebar-border overflow-hidden rounded-xl border"
            >
                <div class="relative aspect-video">
                    <img
                        v-if="artPiece.images.length > 0"
                        :src="artPiece.images[0]"
                        :alt="artPiece.name"
                        class="size-full object-cover"
                    />
                    <PlaceholderPattern v-else />
                </div>
                <div class="p-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium">{{ artPiece.name }}</p>
                        <Badge variant="secondary">{{ artPiece.status }}</Badge>
                    </div>
                    <p>${{ (artPiece.price_cents / 100).toFixed(2) }}</p>
                    <p>
                        {{ artPiece.images.length }}
                        {{ artPiece.images.length === 1 ? 'image' : 'images' }}
                    </p>
                </div>
            </div>
        </div>

        <div
            v-if="artPieces.last_page > 1"
            class="flex items-center justify-between"
        >
            <Link
                v-if="artPieces.prev_page_url"
                :href="artPieces.prev_page_url"
                preserve-scroll
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
                preserve-scroll
            >
                Next
            </Link>
            <span v-else></span>
        </div>
    </div>
</template>
