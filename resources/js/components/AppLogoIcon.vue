<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- "TC" monogram, matching the home-screen icon (public/icon.svg). -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 40"
        role="img"
        aria-label="The Tucker Collection"
        :class="className"
        v-bind="$attrs"
    >
        <text
            x="20"
            y="20"
            dy="0.35em"
            text-anchor="middle"
            font-family="Georgia, 'Times New Roman', serif"
            font-size="22"
            letter-spacing="-0.5"
            fill="currentColor"
        >
            TC
        </text>
    </svg>
</template>
