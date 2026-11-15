<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { home } from '@/routes';

defineProps<{
    order: {
        status: string;
        piece_name: string | null;
        subtotal_cents: number;
        shipping_cents: number;
        total_cents: number;
        buyer_email: string;
        shipping_address: Record<string, string | null>;
    };
}>();

const headings: Record<string, string> = {
    pending: 'Payment processing',
    paid: 'Thank you!',
    fulfilled: 'Your order has shipped',
    delivered: 'Your order was delivered',
    refunded: 'This order was refunded',
    cancelled: "This order wasn't completed",
};

const dollars = (cents: number): string => `$${(cents / 100).toFixed(2)}`;
</script>

<template>
    <Head title="Your order" />

    <div class="mx-auto grid max-w-md gap-4 p-4 py-10">
        <div class="grid gap-2 text-center">
            <h1 class="text-2xl font-semibold">
                {{ headings[order.status] ?? 'Your order' }}
            </h1>
            <p v-if="order.status === 'pending'">
                We're confirming your payment. This usually takes a few seconds;
                refresh to check.
            </p>
            <p v-else-if="order.status === 'cancelled'">
                The reservation ended before payment went through. If you were
                charged, you'll be refunded.
            </p>
            <p v-else-if="order.status === 'paid'">
                Your payment is confirmed. We'll email {{ order.buyer_email }}
                the tracking number once it ships.
            </p>
        </div>

        <dl
            class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 rounded-xl border p-4 text-sm"
        >
            <dt>{{ order.piece_name }}</dt>
            <dd class="text-right">{{ dollars(order.subtotal_cents) }}</dd>
            <dt>Shipping</dt>
            <dd class="text-right">{{ dollars(order.shipping_cents) }}</dd>
            <dt class="border-t pt-1 font-medium">Total</dt>
            <dd class="border-t pt-1 text-right font-medium">
                {{ dollars(order.total_cents) }}
            </dd>
        </dl>

        <address class="rounded-xl border p-4 text-sm not-italic">
            <span class="text-muted-foreground block text-xs">Ships to</span>
            {{ order.shipping_address.name }}<br />
            {{ order.shipping_address.line1 }}<br />
            <template v-if="order.shipping_address.line2">
                {{ order.shipping_address.line2 }}<br />
            </template>
            {{ order.shipping_address.city }},
            {{ order.shipping_address.state }}
            {{ order.shipping_address.postal_code }}
        </address>

        <Link :href="home()" class="text-center underline underline-offset-4">
            Back to the gallery
        </Link>
    </div>
</template>
