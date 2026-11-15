<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { home } from '@/routes';

defineProps<{
    order: {
        status: string;
        piece_name: string | null;
        total_cents: number;
        buyer_email: string;
    };
}>();
</script>

<template>
    <Head title="Order received" />

    <div class="mx-auto grid max-w-md gap-4 p-4 py-10 text-center">
        <template v-if="order.status === 'paid'">
            <h1 class="text-2xl font-semibold">Thank you!</h1>
            <p>
                Your payment of ${{ (order.total_cents / 100).toFixed(2) }} for
                <strong>{{ order.piece_name }}</strong> is confirmed.
            </p>
        </template>
        <template v-else-if="order.status === 'pending'">
            <h1 class="text-2xl font-semibold">Payment processing</h1>
            <p>
                We're confirming your payment for
                <strong>{{ order.piece_name }}</strong
                >. This usually takes a few seconds; refresh to check.
            </p>
        </template>
        <template v-else>
            <h1 class="text-2xl font-semibold">This order wasn't completed</h1>
            <p>
                The reservation for
                <strong>{{ order.piece_name }}</strong> ended before payment
                went through. If you were charged, you'll be refunded.
            </p>
        </template>
        <p class="text-muted-foreground text-sm">
            A receipt is sent to {{ order.buyer_email }}.
        </p>
        <Link :href="home()" class="underline underline-offset-4">
            Back to the gallery
        </Link>
    </div>
</template>
