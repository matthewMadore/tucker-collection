<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    index,
    refund,
    ship,
} from '@/actions/App/Http/Controllers/SalesController';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Sales', href: index() }],
    },
});

type Sale = {
    id: number;
    status: string;
    placed_at: string | null;
    piece_name: string | null;
    total_cents: number;
    shipping_cents: number;
    buyer_email: string;
    shipping_address: Record<string, string | null>;
    service_level: string | null;
    tracking_number: string | null;
    disputed: boolean;
    can_ship: boolean;
    can_refund: boolean;
};

defineProps<{
    orders: {
        data: Sale[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

const labels: Record<string, string> = {
    paid: 'To ship',
    fulfilled: 'Shipped',
    delivered: 'Delivered',
    refunded: 'Refunded',
};

const dollars = (cents: number): string => `$${(cents / 100).toFixed(2)}`;

function refundOrder(sale: Sale): void {
    const warning =
        sale.status === 'paid'
            ? 'The piece goes back on sale.'
            : 'It has shipped, so the piece stays marked sold.';

    if (
        confirm(
            `Refund ${dollars(sale.total_cents)} to ${sale.buyer_email}? ${warning}`,
        )
    ) {
        router.post(refund.url(sale.id), {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Sales" />

    <div class="grid gap-4 p-4">
        <p v-if="orders.data.length === 0">No sales yet.</p>

        <article
            v-for="sale in orders.data"
            :key="sale.id"
            class="grid gap-3 rounded-xl border p-4 md:grid-cols-[1fr_1fr_minmax(0,18rem)]"
            :aria-label="`Order ${sale.id}`"
        >
            <div class="grid content-start gap-1">
                <div class="flex items-center gap-2">
                    <h2 class="font-medium">{{ sale.piece_name }}</h2>
                    <Badge
                        :variant="
                            sale.status === 'paid' ? 'default' : 'secondary'
                        "
                    >
                        {{ labels[sale.status] ?? sale.status }}
                    </Badge>
                    <Badge v-if="sale.disputed" variant="destructive">
                        Disputed
                    </Badge>
                </div>
                <p class="text-sm">
                    {{ dollars(sale.total_cents) }}
                    <span class="text-muted-foreground">
                        (incl. {{ dollars(sale.shipping_cents) }} shipping)
                    </span>
                </p>
                <p v-if="sale.placed_at" class="text-muted-foreground text-sm">
                    Order #{{ sale.id }} ·
                    {{ new Date(sale.placed_at).toLocaleString() }}
                </p>
            </div>

            <address class="text-sm not-italic">
                {{ sale.shipping_address.name }}<br />
                {{ sale.shipping_address.line1 }}<br />
                <template v-if="sale.shipping_address.line2">
                    {{ sale.shipping_address.line2 }}<br />
                </template>
                {{ sale.shipping_address.city }},
                {{ sale.shipping_address.state }}
                {{ sale.shipping_address.postal_code }}<br />
                <a :href="`mailto:${sale.buyer_email}`" class="underline">
                    {{ sale.buyer_email }}
                </a>
                <span
                    v-if="sale.service_level"
                    class="text-muted-foreground block"
                >
                    {{ sale.service_level }}
                </span>
            </address>

            <div class="grid content-start gap-2">
                <p v-if="sale.disputed" class="text-sm">
                    The buyer disputed this payment with their bank. Respond in
                    <a
                        href="https://dashboard.stripe.com/disputes"
                        class="underline"
                        target="_blank"
                        rel="noopener"
                        >Stripe</a
                    >; shipping and refunds wait until it's settled.
                </p>
                <p v-if="sale.tracking_number" class="text-sm">
                    Tracking: <strong>{{ sale.tracking_number }}</strong>
                </p>

                <Form
                    v-if="sale.can_ship"
                    v-bind="ship.form(sale.id)"
                    :options="{ preserveScroll: true }"
                    class="grid gap-1.5"
                    v-slot="{ errors, processing }"
                >
                    <Label :for="`tracking-${sale.id}`">
                        UPS tracking number
                    </Label>
                    <div class="flex gap-2">
                        <Input
                            :id="`tracking-${sale.id}`"
                            name="tracking_number"
                            autocapitalize="characters"
                            autocomplete="off"
                            required
                        />
                        <Button type="submit" :disabled="processing">
                            Mark shipped
                        </Button>
                    </div>
                    <InputError :message="errors.tracking_number" />
                </Form>

                <Button
                    v-if="sale.can_refund"
                    variant="outline"
                    @click="refundOrder(sale)"
                >
                    Refund {{ dollars(sale.total_cents) }}
                </Button>
            </div>
        </article>

        <div
            v-if="orders.last_page > 1"
            class="flex items-center justify-between"
        >
            <Link
                v-if="orders.prev_page_url"
                :href="orders.prev_page_url"
                preserve-scroll
            >
                Previous
            </Link>
            <span v-else></span>
            <span
                >Page {{ orders.current_page }} of {{ orders.last_page }}</span
            >
            <Link
                v-if="orders.next_page_url"
                :href="orders.next_page_url"
                preserve-scroll
            >
                Next
            </Link>
            <span v-else></span>
        </div>
    </div>
</template>
