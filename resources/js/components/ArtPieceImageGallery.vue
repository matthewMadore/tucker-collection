<script setup lang="ts">
import { computed, ref } from 'vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';

const props = defineProps<{
    name: string;
    images: {
        id: number;
        url: string;
    }[];
}>();

const imageIndex = ref(0);

const selectedImage = computed(() => props.images[imageIndex.value]);
</script>

<template>
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
                :alt="`${name}, image ${imageIndex + 1} of ${images.length}`"
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

        <ul v-if="images.length > 1" class="flex flex-wrap gap-2">
            <li v-for="(image, index) in images" :key="image.id">
                <button
                    type="button"
                    class="block size-16 overflow-hidden rounded-md border-2 sm:size-20"
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
</template>
