<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';
import { dashboard, home, login } from '@/routes';
</script>

<template>
    <div class="bg-background flex min-h-svh flex-col">
        <header class="border-sidebar-border/80 border-b">
            <div
                class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4"
            >
                <Link :href="home()" class="flex items-center gap-x-2">
                    <AppLogo />
                </Link>
                <Button
                    v-if="$page.props.auth.user"
                    as-child
                    variant="outline"
                    size="sm"
                >
                    <Link :href="dashboard()">Dashboard</Link>
                </Button>
                <Button v-else as-child variant="ghost" size="sm">
                    <Link :href="login()">Log in</Link>
                </Button>
            </div>
        </header>
        <p
            v-if="!$page.props.shopEnabled"
            role="note"
            class="bg-muted text-muted-foreground px-4 py-2 text-center text-sm"
        >
            Gallery preview: pieces aren't for sale yet.
        </p>
        <main class="mx-auto w-full max-w-6xl flex-1">
            <slot />
        </main>
    </div>
</template>
