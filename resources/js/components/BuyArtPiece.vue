<script setup lang="ts">
import type { HttpResponse } from '@inertiajs/core';
import { router, useHttp } from '@inertiajs/vue3';
import { loadStripe } from '@stripe/stripe-js';
import type { Stripe, StripeElements } from '@stripe/stripe-js';
import { nextTick, ref } from 'vue';
import {
    complete,
    store,
} from '@/actions/App/Http/Controllers/CheckoutController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    artPieceId: number;
    priceCents: number;
    /** Stripe publishable key; null until Stripe is configured. */
    stripeKey: string | null;
    holdMinutes: number;
}>();

type CheckoutResponse = {
    subtotal_cents: number;
    shipping_cents: number;
    total_cents: number;
    shipping: { service_level: string; estimated_days: number };
    client_secret: string;
};

const step = ref<'start' | 'details' | 'payment'>('start');
const checkout = ref<CheckoutResponse | null>(null);
/** A problem with the whole request (piece taken, rate limit, declined card). */
const problem = ref<string | null>(null);
const paying = ref(false);
const paymentElement = ref<HTMLElement | null>(null);
let stripe: Stripe | null = null;
let elements: StripeElements | null = null;

const http = useHttp<
    {
        email: string;
        shipping_address: Record<string, string>;
    },
    CheckoutResponse
>({
    email: '',
    shipping_address: {
        name: '',
        line1: '',
        line2: '',
        city: '',
        state: '',
        postal_code: '',
        country: 'US',
    },
});

const dollars = (cents: number): string => `$${(cents / 100).toFixed(2)}`;

/** Validation errors arrive keyed like "shipping_address.city". */
const fieldError = (field: string): string | undefined =>
    (http.errors as Record<string, string | undefined>)[field];

function describeFailure(response: HttpResponse): string {
    if (response.status === 429) {
        return 'Too many attempts. Please wait a minute and try again.';
    }

    try {
        return (
            JSON.parse(response.data).message ??
            'Something went wrong. Please try again.'
        );
    } catch {
        return 'Something went wrong. Please try again.';
    }
}

async function continueToPayment(): Promise<void> {
    problem.value = null;

    let response: CheckoutResponse | undefined;
    try {
        response = await http.post(store.url(props.artPieceId), {
            onHttpException: (failed) => {
                problem.value = describeFailure(failed);
                // The piece may have been taken: refresh its status badge.
                router.reload({ only: ['artPiece'] });
            },
        });
    } catch {
        problem.value ??= "Couldn't reach the server. Please try again.";

        return;
    }

    // undefined means validation errors, now shown under the fields.
    if (!response) {
        return;
    }

    checkout.value = response;
    step.value = 'payment';

    if (props.stripeKey) {
        stripe = await loadStripe(props.stripeKey);
        elements =
            stripe?.elements({ clientSecret: response.client_secret }) ?? null;
        await nextTick();
        if (paymentElement.value) {
            elements?.create('payment').mount(paymentElement.value);
        }
    }
}

async function pay(): Promise<void> {
    if (!stripe || !elements) {
        return;
    }

    paying.value = true;
    problem.value = null;

    // On success Stripe redirects to the confirmation page; we only get here
    // if the card was declined or needs fixing, and the buyer can retry.
    const { error } = await stripe.confirmPayment({
        elements,
        confirmParams: {
            return_url: new URL(complete.url(), window.location.origin).href,
        },
    });

    problem.value = error.message ?? 'Your payment could not be completed.';
    paying.value = false;
}
</script>

<template>
    <div class="grid gap-3">
        <Button
            v-if="step === 'start'"
            class="w-full"
            size="lg"
            @click="step = 'details'"
        >
            Buy this piece · {{ dollars(priceCents) }}
        </Button>

        <form
            v-else-if="step === 'details'"
            class="grid gap-3 rounded-xl border p-4"
            @submit.prevent="continueToPayment"
        >
            <h2 class="font-medium">Shipping details</h2>
            <p class="text-muted-foreground text-sm">
                Ships within the United States.
            </p>

            <div class="grid gap-1.5">
                <Label for="buyer-email">Email</Label>
                <Input
                    id="buyer-email"
                    v-model="http.email"
                    type="email"
                    autocomplete="email"
                    required
                />
                <InputError :message="fieldError('email')" />
            </div>
            <div class="grid gap-1.5">
                <Label for="ship-name">Full name</Label>
                <Input
                    id="ship-name"
                    v-model="http.shipping_address.name"
                    autocomplete="shipping name"
                    required
                />
                <InputError :message="fieldError('shipping_address.name')" />
            </div>
            <div class="grid gap-1.5">
                <Label for="ship-line1">Address</Label>
                <Input
                    id="ship-line1"
                    v-model="http.shipping_address.line1"
                    autocomplete="shipping address-line1"
                    required
                />
                <InputError :message="fieldError('shipping_address.line1')" />
            </div>
            <div class="grid gap-1.5">
                <Label for="ship-line2">Apartment, suite (optional)</Label>
                <Input
                    id="ship-line2"
                    v-model="http.shipping_address.line2"
                    autocomplete="shipping address-line2"
                />
            </div>
            <div class="grid grid-cols-[2fr_1fr_1.5fr] gap-2">
                <div class="grid gap-1.5">
                    <Label for="ship-city">City</Label>
                    <Input
                        id="ship-city"
                        v-model="http.shipping_address.city"
                        autocomplete="shipping address-level2"
                        required
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="ship-state">State</Label>
                    <Input
                        id="ship-state"
                        v-model="http.shipping_address.state"
                        autocomplete="shipping address-level1"
                        maxlength="2"
                        required
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="ship-zip">ZIP</Label>
                    <Input
                        id="ship-zip"
                        v-model="http.shipping_address.postal_code"
                        autocomplete="shipping postal-code"
                        inputmode="numeric"
                        required
                    />
                </div>
            </div>
            <InputError
                :message="
                    fieldError('shipping_address.city') ??
                    fieldError('shipping_address.state') ??
                    fieldError('shipping_address.postal_code')
                "
            />

            <p v-if="problem" role="alert" class="text-destructive text-sm">
                {{ problem }}
            </p>

            <div class="flex gap-2">
                <Button
                    type="submit"
                    class="flex-1"
                    :disabled="http.processing"
                >
                    {{ http.processing ? 'Reserving…' : 'Continue to payment' }}
                </Button>
                <Button type="button" variant="ghost" @click="step = 'start'">
                    Cancel
                </Button>
            </div>
        </form>

        <div
            v-else-if="checkout"
            class="grid gap-3 rounded-xl border p-4"
            aria-label="Payment"
        >
            <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
                <dt>Piece</dt>
                <dd class="text-right">
                    {{ dollars(checkout.subtotal_cents) }}
                </dd>
                <dt>
                    Shipping
                    <span class="text-muted-foreground block text-xs">
                        {{ checkout.shipping.service_level }}, about
                        {{ checkout.shipping.estimated_days }} business days
                    </span>
                </dt>
                <dd class="text-right">
                    {{ dollars(checkout.shipping_cents) }}
                </dd>
                <dt class="border-t pt-1 font-medium">Total</dt>
                <dd class="border-t pt-1 text-right font-medium">
                    {{ dollars(checkout.total_cents) }}
                </dd>
            </dl>
            <p class="text-muted-foreground text-sm">
                This piece is reserved for you for {{ holdMinutes }} minutes.
            </p>

            <template v-if="stripeKey">
                <div ref="paymentElement" />
                <p v-if="problem" role="alert" class="text-destructive text-sm">
                    {{ problem }}
                </p>
                <Button size="lg" :disabled="paying" @click="pay">
                    {{
                        paying
                            ? 'Processing…'
                            : `Pay ${dollars(checkout.total_cents)}`
                    }}
                </Button>
            </template>
            <p
                v-else
                role="status"
                class="rounded-md bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
            >
                Card payments aren't set up yet (no Stripe key configured), so
                this order can't be paid here.
            </p>
        </div>
    </div>
</template>
