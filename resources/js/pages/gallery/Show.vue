<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ArtPieceImageGallery from '@/components/ArtPieceImageGallery.vue';
import BuyArtPiece from '@/components/BuyArtPiece.vue';
import { Badge } from '@/components/ui/badge';
import { home } from '@/routes';

defineProps<{
    artPiece: {
        id: number;
        name: string;
        description: string;
        /** null in gallery-only mode */
        price_cents: number | null;
        status: string;
        status_label: string;
        images: {
            id: number;
            url: string;
        }[];
    };
    stripeKey: string | null;
    holdMinutes: number;
}>();
</script>

<template>
    <Head :title="artPiece.name" />

    <div class="grid gap-4 p-4">
        <Link :href="home()" class="text-muted-foreground text-sm">
            ← Back to gallery
        </Link>

        <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
            <ArtPieceImageGallery
                :name="artPiece.name"
                :images="artPiece.images"
            />

            <section class="grid content-start gap-3" aria-label="Details">
                <div class="flex items-start justify-between gap-2">
                    <h1 class="text-xl font-semibold">{{ artPiece.name }}</h1>
                    <Badge v-if="artPiece.status !== 'published'">
                        {{ artPiece.status_label }}
                    </Badge>
                </div>
                <p v-if="artPiece.price_cents !== null" class="text-lg">
                    ${{ (artPiece.price_cents / 100).toFixed(2) }}
                </p>
                <p
                    v-if="artPiece.description"
                    class="text-muted-foreground whitespace-pre-line"
                >
                    {{ artPiece.description }}
                </p>

                <template v-if="artPiece.status === 'published'">
                    <BuyArtPiece
                        v-if="artPiece.price_cents !== null"
                        :key="artPiece.id"
                        :art-piece-id="artPiece.id"
                        :price-cents="artPiece.price_cents"
                        :stripe-key="stripeKey"
                        :hold-minutes="holdMinutes"
                    />
                </template>
                <p v-else class="text-muted-foreground text-sm">
                    {{
                        artPiece.status === 'sold'
                            ? 'This piece has been sold.'
                            : 'This piece is reserved by another buyer.'
                    }}
                </p>
            </section>
        </div>
    </div>
</template>
