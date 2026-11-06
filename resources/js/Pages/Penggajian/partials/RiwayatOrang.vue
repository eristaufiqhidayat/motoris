<script setup>
import { computed, ref } from 'vue';
import Button from '@/Components/ui/atoms/Button.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import Input from '@/Components/ui/atoms/Input.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import Modal from '@/Components/ui/molecules/Modal.vue';
import DataTable from '@/Components/ui/organisms/DataTable.vue';
import RincianOrang from './RincianOrang.vue';
import { useAmbilData } from '@/Support/ambilData.js';

// Tab Riwayat per Orang: cari motoris menurut nama, lihat gaji dan insentifnya per periode dari penggajian yang sudah
// final. Klik baris membuka rincian komponen (drawer yang sama dengan halaman detail, hanya baca).
const props = defineProps({
    baris: { type: Object, required: true },
    q: { type: String, default: '' },
    // URL dasar halaman, mis. /penggajian_sales.
    base: { type: String, required: true },
    orangLabel: { type: String, default: 'motoris' },
});

const KOLOM = {
    nama: 'Nama',
    depo: 'Depo',
    periode: 'Periode',
    kategori: 'Kategori',
    no_penggajian: 'No. Penggajian',
    pendapatan: 'Pendapatan',
    potongan: 'Potongan',
    bersih: 'Bersih',
};

const cari = ref(props.q);

const { memuat, berubah, ambil } = useAmbilData({
    form: () => ({ q: cari.value.trim() }),
    diterapkan: () => ({ q: props.q }),
});

function kirimCari() {
    ambil(props.base, { tab: 'riwayat', q: cari.value.trim() }, { preserveState: true });
}

const dipilih = ref(null);
const orang = computed(() => dipilih.value?.orang ?? null);
</script>

<template>
    <Card class="mb-3" body-class="card-body card-body-compact">
        <form class="flex flex-wrap items-end gap-2" role="search" @submit.prevent="kirimCari">
            <div>
                <Label for="cari-nama">Nama {{ orangLabel }}</Label>
                <Input id="cari-nama" v-model="cari" placeholder="Cari..." />
            </div>
            <Button type="submit" variant="outline-secondary" :loading="memuat" :disabled="!berubah"><Icon name="search" class="me-1 w-3.5 h-3.5" />Cari</Button>
        </form>
    </Card>

    <DataTable
        :columns="KOLOM"
        :rows="baris.data"
        :total="baris.total"
        :links="baris.links"
        :memuat="memuat"
        :empty-text="q ? `Tidak ada data untuk “${q}”.` : 'Belum ada data.'"
        baris-bisa-diklik
        @klik-baris="dipilih = $event"
    />

    <Modal :show="orang !== null" :title="orang ? `${orang.nama} · ${dipilih.kategori}` : ''" placement="kanan" size="xl" @close="dipilih = null">
        <RincianOrang v-if="orang" :key="orang.id" :orang="orang" :kategori="orang.kategori" :base="`${base}/${dipilih.id}`" />
    </Modal>
</template>
