<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { store } from '@/actions/App/Http/Controllers/ArtPieceController';
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
const name = ref('');
const price = ref('');
const description = ref('');
const imageUrls = ref(['']);

function addImageField() {
    imageUrls.value.push('');
}

function removeImageField(index: number) {
    imageUrls.value.splice(index, 1);
}

function handleSubmit() {
    router.post(store.url(), {
        name: name.value,
        price: price.value,
        description: description.value,
        images: imageUrls.value,
    }, {
        onSuccess: () => {
            name.value = '';
            price.value = '';
            description.value = '';
            imageUrls.value = [''];
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

            <div class="grid gap-2">
                <label for="name">Name</label>
                <input id="name" type="text" v-model="name">
            </div>

            <div class="grid gap-2">
                <label for="price">Price</label>
                <input id="price" type="text" v-model="price">
            </div>

            <div class="grid gap-2">
                <label for="description">Description</label>
                <textarea id="description" v-model="description"></textarea>
            </div>

            <div class="grid gap-2">
                <label>Image URLs</label>
                <div v-for="(_, index) in imageUrls" :key="index" class="flex gap-2">
                    <input type="text" v-model="imageUrls[index]" placeholder="Image URL">
                    <button type="button" @click="removeImageField(index)">Remove</button>
                </div>
                <button type="button" @click="addImageField">Add another image</button>
            </div>

            <DialogFooter>
                <button @click="handleSubmit">Submit</button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
