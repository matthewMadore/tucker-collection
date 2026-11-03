<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { store } from '@/actions/App/Http/Controllers/ArtPieceController';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

const open = ref(false);

const form = useForm({
    name: '',
    price: '',
    description: '',
    images: [''],
});

function addImageField() {
    form.images.push('');
}

function removeImageField(index: number) {
    form.images.splice(index, 1);
}

/**
 * Laravel keys errors for array items as "images.0", "images.1", ...
 */
function imageError(index: number): string | undefined {
    return (form.errors as Record<string, string>)[`images.${index}`];
}

function handleSubmit() {
    form.post(store.url(), {
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <button>Add Art Piece</button>
        </DialogTrigger>

        <DialogContent>
            <DialogHeader>
                <DialogTitle>Add a new art piece</DialogTitle>
                <DialogDescription>
                    Enter the details for the new piece.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="handleSubmit">
                <div class="grid gap-2">
                    <label for="name">Name</label>
                    <input id="name" type="text" v-model="form.name" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <label for="price">Price</label>
                    <input
                        id="price"
                        type="text"
                        inputmode="decimal"
                        v-model="form.price"
                    />
                    <InputError :message="form.errors.price" />
                </div>

                <div class="grid gap-2">
                    <label for="description">Description</label>
                    <textarea
                        id="description"
                        v-model="form.description"
                    ></textarea>
                    <InputError :message="form.errors.description" />
                </div>

                <div class="grid gap-2">
                    <label>Image URLs</label>
                    <div
                        v-for="(_, index) in form.images"
                        :key="index"
                        class="grid gap-1"
                    >
                        <div class="flex gap-2">
                            <input
                                type="text"
                                v-model="form.images[index]"
                                placeholder="https://..."
                            />
                            <button
                                type="button"
                                @click="removeImageField(index)"
                            >
                                Remove
                            </button>
                        </div>
                        <InputError :message="imageError(index)" />
                    </div>
                    <InputError :message="form.errors.images" />
                    <button type="button" @click="addImageField">
                        Add another image
                    </button>
                </div>

                <DialogFooter>
                    <button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Saving...' : 'Submit' }}
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
