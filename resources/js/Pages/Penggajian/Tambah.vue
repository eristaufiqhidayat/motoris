<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Alert from '@/Components/ui/molecules/Alert.vue';
import Combobox from '@/Components/ui/molecules/Combobox.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import FieldError from '@/Components/ui/atoms/FieldError.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import PageHeader from '@/Components/ui/molecules/PageHeader.vue';
import { tanggalSingkat } from '@/Support/format.js';

const props = defineProps({
    slug: { type: String, required: true },
    judul: { type: String, required: true },
    kategori: { type: String, required: true },
    orangLabel: { type: String, default: 'motoris' },
    // Terisi bila kategori ini belum tersedia (insentif koordinator); form dinonaktifkan.
    catatan: { type: String, default: null },
    periode: { type: Array, required: true },
    depo: { type: Array, required: true },
});

const appName = usePage().props.appName;
const base = `/${props.slug}`;

const insentif = props.kategori === 'insentif';
const form = useForm({ kategori: props.kategori, depo_id: '', periode_mulai: props.periode[0]?.mulai ?? '' });

const opsiPeriode = computed(() => props.periode.map((p) => ({
    value: p.mulai,
    label: `${p.label} (${tanggalSingkat(p.mulai)} – ${tanggalSingkat(p.selesai)})`,
})));
const opsiDepo = computed(() => props.depo.map((d) => ({ value: d.id, label: d.nama })));
// Depo kosong = semua depo dalam cakupan akun (satu draf per depo).
const semuaDepo = computed(() => form.depo_id === '' || form.depo_id === null);
const terpilih = computed(() => props.periode.find((p) => p.mulai === form.periode_mulai));

function simpan() {
    form.post(base);
}
</script>

<template>
    <Head :title="`${judul} · ${appName}`" />
    <AppLayout :breadcrumb-slug="slug">
        <PageHeader :title="judul" :subtitle="insentif ? `Draf insentif ${orangLabel} aktif per depo, dihitung dari penjualan bulan itu` : `Draf gaji ${orangLabel} aktif per depo, dihitung dari data karyawan dan absensi periode itu`" />

        <Alert v-if="catatan" variant="warning">{{ catatan }}</Alert>

        <Card class="shadow-sm">
            <form class="grid gap-3 md:grid-cols-2" @submit.prevent="simpan">
                <div>
                    <Label for="depo_id">Depo</Label>
                    <Combobox id="depo_id" v-model="form.depo_id" :options="opsiDepo" placeholder="Semua depo" />
                    <FieldError v-if="form.errors.depo_id">{{ form.errors.depo_id }}</FieldError>
                    <p v-else class="mt-1 mb-0 text-xs text-gray-500">
                        {{ semuaDepo ? `Kosong = semua depo: satu draf per depo untuk semua ${orangLabel} aktif.` : 'Draf hanya untuk depo ini.' }}
                    </p>
                </div>
                <div>
                    <Label for="periode_mulai">Periode <span class="text-red-600">*</span></Label>
                    <Combobox id="periode_mulai" v-model="form.periode_mulai" :options="opsiPeriode" placeholder="Pilih periode..." :clearable="false" />
                    <FieldError v-if="form.errors.periode_mulai">{{ form.errors.periode_mulai }}</FieldError>
                    <p v-if="terpilih" class="mt-1 mb-0 text-xs text-gray-500">Dibayar {{ tanggalSingkat(terpilih.tanggal_bayar) }}.</p>
                </div>
                <div class="flex gap-2 md:col-span-2">
                    <Button type="submit" variant="success" :loading="form.processing" :disabled="!!catatan">
                        <Icon name="plus" class="me-1 w-3.5 h-3.5" />{{ semuaDepo ? 'Buat draf semua depo' : 'Buat draf' }}
                    </Button>
                    <Button :tag="Link" :href="`${base}?tab=${kategori}`" variant="outline-secondary">Batal</Button>
                </div>
            </form>
        </Card>
    </AppLayout>
</template>
