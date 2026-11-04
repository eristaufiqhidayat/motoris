<script setup>
import { onMounted, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import Checkbox from '@/Components/ui/atoms/Checkbox.vue';
import DatePicker from '@/Components/ui/atoms/DatePicker.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import Input from '@/Components/ui/atoms/Input.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Select from '@/Components/ui/atoms/Select.vue';
import PageHeader from '@/Components/ui/molecules/PageHeader.vue';
import DataTable from '@/Components/ui/organisms/DataTable.vue';
import DetailLaporan from './partials/DetailLaporan.vue';
import FormLaporan from './partials/FormLaporan.vue';
import { useAmbilData } from '@/Support/ambilData.js';
import { tanggalSingkat } from '@/Support/format.js';

// Daftar Laporan Kendala (spec 064). Koordinator melihat laporan timnya, Supervisor/Admin sesuai cakupan role.
const props = defineProps({
    slug: { type: String, required: true },
    judul: { type: String, required: true },
    baris: { type: Object, required: true },
    filter: { type: Object, required: true },
    pilihanStatus: { type: Object, required: true },
    pilihanKategori: { type: Object, required: true },
    daftarDepo: { type: Object, default: () => ({}) },
    bisa: { type: Object, required: true },
    hariIni: { type: String, required: true },
});

const appName = usePage().props.appName;
const KOLOM = {
    tanggal_kejadian: 'Tanggal kejadian',
    kategori: 'Kategori',
    judul: 'Judul',
    pelapor: 'Pelapor',
    depo: 'Depo',
    status: 'Status',
    jumlah_pembaca: 'Dibaca',
    diperbarui: 'Diperbarui',
    aksi: '',
};
const VARIAN_STATUS = { terbuka: 'warning', selesai: 'success' };

// Form Buat / Ubah: terbuka bila `formTerbuka`; `laporanDiubah` null = laporan baru.
const formTerbuka = ref(false);
const laporanDiubah = ref(null);

function bukaForm(laporan = null) {
    laporanDiubah.value = laporan;
    formTerbuka.value = true;
}

// Tombol Buat Laporan di kartu Beranda menautkan ke ?buat=1: langsung buka formnya.
onMounted(() => {
    if (props.bisa.tambah && new URLSearchParams(window.location.search).get('buat') === '1') bukaForm();
});

// Laporan yang detailnya dibuka dari klik baris; null = tertutup.
const laporanDetail = ref(null);
// Daftar pembaca (hanya terisi untuk pelapor); null = belum dimuat.
const pembaca = ref(null);

// Membuka detail mencatat akun ini sebagai pembaca (kecuali pelapor), lalu daftar dimuat ulang agar lencana ikut hilang.
async function bukaDetail(laporan) {
    laporanDetail.value = laporan;
    pembaca.value = null;

    try {
        const respons = await fetch(`/laporan_kendala/${laporan.id}/dibaca`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
        });
        if (!respons.ok) return;

        pembaca.value = (await respons.json()).pembaca;
        if (laporan.belum_dibaca || laporan.diperbarui_setelah_dibaca) router.reload({ only: ['baris'], preserveScroll: true });
    } catch {
        // Gagal mencatat dibaca tidak menghalangi membaca detail.
    }
}

function ubahDariDetail(laporan) {
    laporanDetail.value = null;
    bukaForm(laporan);
}

const cari = ref(formDari(props.filter));

function formDari(f) {
    return { belum_dibaca: Boolean(f.belum_dibaca), status: f.status, kategori: f.kategori ?? '', depo: f.depo ? String(f.depo) : '', dari: f.dari ?? '', sampai: f.sampai ?? '', q: f.q ?? '' };
}

const { memuat, berubah, ambil } = useAmbilData({
    form: () => ({ ...cari.value, q: cari.value.q.trim() }),
    diterapkan: () => formDari(props.filter),
});

function kirimCari() {
    ambil('/laporan_kendala', { ...cari.value, q: cari.value.q.trim() }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="`${judul} · ${appName}`" />
    <AppLayout :breadcrumb-slug="slug">
        <PageHeader :title="judul" subtitle="Kendala lapangan yang menghambat operasional, dilaporkan koordinator">
            <template #actions>
                <Button v-if="bisa.tambah" variant="success" @click="bukaForm()"><Icon name="plus" class="me-1" /> Buat Laporan</Button>
            </template>
        </PageHeader>

        <DataTable
            :columns="KOLOM"
            tinggi-penuh
            :rows="baris.data"
            :memuat="memuat"
            baris-bisa-diklik
            @klik-baris="bukaDetail"
            :total="baris.total"
            :links="baris.links"
            :empty-text="filter.status === 'terbuka' ? 'Tidak ada laporan kendala yang Terbuka.' : 'Tidak ada laporan kendala yang cocok.'"
        >
            <template #header>
                <form class="flex flex-wrap items-end gap-2" role="search" @submit.prevent="kirimCari">
                    <div class="min-w-36">
                        <Label for="filter_status" class="sr-only">Status</Label>
                        <Select id="filter_status" v-model="cari.status" :options="{ semua: 'Semua status', ...pilihanStatus }" />
                    </div>
                    <div class="min-w-48">
                        <Label for="filter_kategori" class="sr-only">Kategori</Label>
                        <Select id="filter_kategori" v-model="cari.kategori" :options="pilihanKategori" placeholder="Semua kategori" />
                    </div>
                    <div v-if="Object.keys(daftarDepo).length" class="min-w-40">
                        <Label for="filter_depo" class="sr-only">Depo</Label>
                        <Select id="filter_depo" v-model="cari.depo" :options="daftarDepo" placeholder="Semua depo" />
                    </div>
                    <div>
                        <Label for="filter_dari" class="sr-only">Dari tanggal</Label>
                        <DatePicker id="filter_dari" v-model="cari.dari" placeholder="Dari tanggal" clearable />
                    </div>
                    <div>
                        <Label for="filter_sampai" class="sr-only">Sampai tanggal</Label>
                        <DatePicker id="filter_sampai" v-model="cari.sampai" placeholder="Sampai tanggal" clearable />
                    </div>
                    <div class="grow min-w-48">
                        <Label for="filter_q" class="sr-only">Cari laporan</Label>
                        <Input id="filter_q" v-model="cari.q" type="search" placeholder="Cari judul, uraian, pelapor…" />
                    </div>
                    <label class="flex items-center gap-1 whitespace-nowrap">
                        <Checkbox v-model="cari.belum_dibaca" /> Belum dibaca
                    </label>
                    <Button type="submit" :loading="memuat" :disabled="!berubah">Cari</Button>
                </form>
            </template>

            <template #cell-tanggal_kejadian="{ row }">{{ tanggalSingkat(row.tanggal_kejadian) }}</template>
            <template #cell-judul="{ row }">
                {{ row.judul }}
                <Badge v-if="row.belum_dibaca" variant="danger" class="ms-1">Belum dibaca</Badge>
                <Badge v-else-if="row.diperbarui_setelah_dibaca" variant="primary" class="ms-1">Diperbarui</Badge>
            </template>
            <template #cell-kategori="{ row }"><Badge variant="info">{{ row.kategori_label }}</Badge></template>
            <template #cell-status="{ row }"><Badge :variant="VARIAN_STATUS[row.status] ?? 'secondary'">{{ row.status_label }}</Badge></template>
            <template #cell-aksi="{ row }">
                <Button v-if="row.bisa_ubah" variant="outline-secondary" size="sm" @click="bukaForm(row)">Ubah</Button>
            </template>
            <template #cell-jumlah_pembaca="{ row }">{{ row.jumlah_pembaca }}</template>
            <template #cell-diperbarui="{ row }"><span class="text-gray-500">{{ row.diperbarui ?? '-' }}</span></template>
        </DataTable>

        <DetailLaporan :laporan="laporanDetail" :pembaca="pembaca" @close="laporanDetail = null" @ubah="ubahDariDetail" />

        <FormLaporan
            :show="formTerbuka"
            :laporan="laporanDiubah"
            :pilihan-kategori="pilihanKategori"
            :hari-ini="hariIni"
            @close="formTerbuka = false"
        />
    </AppLayout>
</template>
