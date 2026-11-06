<script setup>
import { reactive } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Alert from '@/Components/ui/molecules/Alert.vue';
import Combobox from '@/Components/ui/molecules/Combobox.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import DatePicker from '@/Components/ui/atoms/DatePicker.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import Tabs from '@/Components/ui/molecules/Tabs.vue';
import PageHeader from '@/Components/ui/molecules/PageHeader.vue';
import DataTable from '@/Components/ui/organisms/DataTable.vue';
import RiwayatOrang from './partials/RiwayatOrang.vue';
import { useAmbilData } from '@/Support/ambilData.js';

const props = defineProps({
    slug: { type: String, required: true },
    judul: { type: String, required: true },
    baris: { type: Object, required: true },
    tab: { type: String, required: true },
    tabs: { type: Array, required: true },
    // Sebutan orang yang digaji (motoris atau koordinator) dan catatan bila kategori ini belum tersedia.
    orangLabel: { type: String, default: 'motoris' },
    catatan: { type: String, default: null },
    // Kata kunci pencarian nama di tab Riwayat per Orang.
    q: { type: String, default: '' },
    filter: { type: Object, required: true },
    opsi: { type: Object, required: true },
    bisaTambah: { type: Boolean, default: false },
});

const appName = usePage().props.appName;
const base = `/${props.slug}`;

const KOLOM = {
    no_penggajian: 'No. Penggajian',
    periode: 'Periode',
    depo: 'Depo',
    orang: 'Jumlah Orang',
    total: 'Total Bersih',
    tanggal_bayar: 'Tanggal Bayar',
    status: 'Status',
};
const WARNA_STATUS = { status: { Draf: 'warning', Final: 'success', Batal: 'secondary' } };

const form = reactive({ periode: props.filter.periode, depo_id: String(props.filter.depo_id ?? ''), status: props.filter.status });

const { memuat, berubah, ambil } = useAmbilData({
    form: () => ({ ...form }),
    diterapkan: () => ({ periode: props.filter.periode, depo_id: String(props.filter.depo_id ?? ''), status: props.filter.status }),
});

function tampilkan() {
    ambil(base, { ...form, tab: props.tab }, { preserveState: true });
}

// Pindah tab: filter dikosongkan karena periode gaji dan insentif dinamai berbeda.
const pindahTab = (kunci) => router.get(base, { tab: kunci });

const buka = (baris) => router.visit(`${base}/${baris.id}`);
</script>

<template>
    <Head :title="`${judul} · ${appName}`" />
    <AppLayout :breadcrumb-slug="slug">
        <PageHeader :title="judul" :subtitle="`Gaji dan insentif ${orangLabel} per depo dan periode · klik baris untuk melihat rincian`">
            <template v-if="bisaTambah" #actions>
                <Button :tag="Link" :href="`${base}/tambah?kategori=${tab}`" variant="success"><Icon name="plus" class="me-1 w-3.5 h-3.5" />Tambah</Button>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="pindahTab" />

        <RiwayatOrang v-if="tab === 'riwayat'" :baris="baris" :q="q" :base="base" :orang-label="orangLabel" />

        <template v-else>
            <Alert v-if="catatan" variant="warning">{{ catatan }}</Alert>

            <Card class="mb-3" body-class="card-body card-body-compact">
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="tampilkan">
                    <div>
                        <Label for="periode">Periode (bulan label)</Label>
                        <DatePicker id="periode" v-model="form.periode" mode="bulan" clearable class="w-40" />
                    </div>
                    <div>
                        <Label for="depo">Depo</Label>
                        <Combobox id="depo" v-model="form.depo_id" :options="opsi.depo.map((d) => ({ value: d.id, label: d.nama }))" placeholder="Semua depo" class="w-52" />
                    </div>
                    <div>
                        <Label for="status">Status</Label>
                        <Combobox id="status" v-model="form.status" :options="opsi.status" placeholder="Semua status" class="w-40" />
                    </div>
                    <Button type="submit" variant="outline-secondary" :loading="memuat" :disabled="!berubah">
                        <Icon name="search" class="me-1 w-3.5 h-3.5" />Tampilkan
                    </Button>
                </form>
            </Card>

            <DataTable
                :columns="KOLOM"
                :rows="baris.data"
                :total="baris.total"
                :links="baris.links"
                :memuat="memuat"
                :badge-columns="['status']"
                :badge-variants="WARNA_STATUS"
                empty-text="Belum ada data."
                baris-bisa-diklik
                @klik-baris="buka"
            />
        </template>
    </AppLayout>
</template>
