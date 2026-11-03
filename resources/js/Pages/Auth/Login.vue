<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import Checkbox from '@/Components/ui/atoms/Checkbox.vue';
import FieldError from '@/Components/ui/atoms/FieldError.vue';
import Input from '@/Components/ui/atoms/Input.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Select from '@/Components/ui/atoms/Select.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import PasswordInput from '@/Components/ui/molecules/PasswordInput.vue';

// demoPeran: kode peran => label, hanya terisi bila fitur demo masuk cepat aktif.
// koordinatorImpersonasi: daftar akun koordinator asli, hanya terisi bila fitur impersonate aktif.
defineProps({
    demoPeran: { type: [Object, Array], default: null },
    koordinatorImpersonasi: { type: Array, default: null },
});

const form = useForm({ username: '', password: '', ingat: false });
const koordinatorDipilih = ref('');

// Error dari redirect GET (mis. akun tanpa hak web yang dikeluarkan middleware) tidak lewat useForm.
const errorUsername = computed(() => form.errors.username ?? usePage().props.errors?.username);

function masuk() {
    form.post('/login', { onFinish: () => form.reset('password') });
}

function masukDemo(peran) {
    router.post(`/login/demo/${peran}`);
}

function masukImpersonate() {
    if (!koordinatorDipilih.value) return;
    router.post(`/login/impersonate/${koordinatorDipilih.value}`);
}
</script>

<template>
    <AuthLayout title="Masuk">
        <Card>
            <template #header>Masuk</template>

            <form class="space-y-3" @submit.prevent="masuk">
                <div>
                    <Label for="username">Username</Label>
                    <Input id="username" v-model="form.username" autocomplete="username" autofocus required />
                    <FieldError v-if="errorUsername">{{ errorUsername }}</FieldError>
                </div>

                <div>
                    <Label for="password">Password</Label>
                    <PasswordInput id="password" v-model="form.password" autocomplete="current-password" required />
                    <FieldError v-if="form.errors.password">{{ form.errors.password }}</FieldError>
                </div>

                <label class="form-check">
                    <Checkbox v-model="form.ingat" />
                    <span>Ingat saya</span>
                </label>

                <Button type="submit" class="w-full" :disabled="form.processing">Masuk</Button>

                <p class="text-center text-xs text-gray-500">Lupa password? Hubungi admin.</p>
            </form>

            <div v-if="demoPeran" class="demo-login">
                <div class="demo-login-title"><strong>Demo masuk cepat</strong> · mode uji coba, tanpa password</div>
                <div class="demo-login-buttons">
                    <Button v-for="(label, peran) in demoPeran" :key="peran" variant="outline-secondary" @click="masukDemo(peran)">{{ label }}</Button>
                </div>
            </div>

            <div v-if="koordinatorImpersonasi && koordinatorImpersonasi.length" class="demo-login">
                <div class="demo-login-title"><strong>Impersonate koordinator</strong> · masuk sebagai akun koordinator asli, tanpa password</div>
                <div class="demo-login-buttons">
                    <Select
                        v-model="koordinatorDipilih"
                        placeholder="Pilih koordinator..."
                        :options="koordinatorImpersonasi.map((k) => ({ value: k.id, label: `${k.name} (${k.depo_nama ?? k.username})` }))"
                    />
                    <Button variant="outline-secondary" :disabled="!koordinatorDipilih" @click="masukImpersonate">Masuk sebagai akun ini</Button>
                </div>
            </div>
        </Card>
    </AuthLayout>
</template>
