<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import Alert from '@/Components/ui/molecules/Alert.vue';
import Modal from '@/Components/ui/molecules/Modal.vue';
import PageHeader from '@/Components/ui/molecules/PageHeader.vue';
import DataTable from '@/Components/ui/organisms/DataTable.vue';
import FormSkeleton from '@/Components/ui/molecules/FormSkeleton.vue';
import ReviewKaryawan from './partials/ReviewKaryawan.vue';
import { angka } from '@/Support/format.js';
import { filterTabelTeks, urutkanTabel } from '@/Support/sortFilterTabel.js';

// Daftar karyawan per kategori (Sales, Koordinator, Supervisor, Lainnya) — satu halaman untuk 4 menu.
// Pencarian dan saringan lewat sort & filter per kolom DataTable. Klik baris membuka drawer detail (JSON dari
// /{menu}/{id}/edit); tambah dan edit di halaman Form.
const props = defineProps({
    slug: { type: String, required: true },
    kategori: { type: String, required: true },
    judul: { type: String, required: true },
    nama: { type: String, required: true },
    kolom: { type: Object, required: true },
    baris: { type: Object, required: true },
    status: { type: String, default: 'aktif' },
    jumlah: { type: Object, required: true },
    bisa: { type: Object, required: true },
    sortableColumns: { type: Array, default: () => [] },
    sort: { type: Object, default: null },
    filterableColumns: { type: Array, default: () => [] },
    filterValues: { type: Object, default: () => ({}) },
});

const appName = usePage().props.appName;
const base = `/${props.slug}`;
const STATUS = { aktif: 'Aktif', tidak_aktif: 'Tidak aktif' };
const adaFilter = computed(() => Object.keys(props.filterValues).length > 0);

// Ganti status mempertahankan sort & filter kolom yang sedang aktif.
function tautanStatus(kode) {
    const query = new URLSearchParams();

    if (kode !== 'aktif') query.set('status', kode);
    if (props.sort) {
        query.set('sort', props.sort.kolom);
        query.set('arah', props.sort.arah);
    }

    Object.entries(props.filterValues).forEach(([kolom, nilai]) => query.set(`filter[${kolom}]`, nilai));

    const teks = query.toString();

    return teks ? `${base}?${teks}` : base;
}

const detailUrl = ref(null);
const detail = ref(null);
const detailGagal = ref(false);

// Drawer hanya detail baca saja (klik baris). Tambah dan edit ada di halaman khusus (Form.vue).
async function bukaDetail(url) {
    detailUrl.value = url;
    detail.value = null;
    detailGagal.value = false;

    try {
        const respons = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!respons.ok) throw new Error(`HTTP ${respons.status}`);
        detail.value = await respons.json();
    } catch {
        detailGagal.value = true;
    }
}

function tutupDetail() {
    detailUrl.value = null;
    detail.value = null;
    detailGagal.value = false;
}
</script>

<template>
    <Head :title="`${judul} · ${appName}`" />
    <AppLayout :breadcrumb-slug="slug">
        <PageHeader :title="judul" :subtitle="`${angka(jumlah.semua)} ${nama} · ${angka(jumlah.aktif)} aktif · dipakai aplikasi HP, akun login, dan laporan motoris`">
            <template #actions>
                <Button v-if="bisa.ekspor" tag="a" variant="outline-secondary" :href="`${base}/ekspor`"><Icon name="download" class="me-1" /> Ekspor CSV</Button>
                <Button v-if="bisa.tambah" :tag="Link" variant="success" :href="`${base}/tambah`"><Icon name="plus" class="me-1" /> Tambah {{ nama }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-wrap gap-2 mb-3" role="group" aria-label="Saring status">
            <Button
                v-for="(label, kode) in STATUS"
                :key="kode"
                :tag="Link"
                :variant="status === kode ? 'primary' : 'outline-secondary'"
                :href="tautanStatus(kode)"
                :aria-current="status === kode ? 'true' : null"
            >
                {{ label }} <span class="ms-1 opacity-75">{{ angka(jumlah[kode]) }}</span>
            </Button>
        </div>

        <DataTable
            :columns="kolom"
            :sticky-columns="['foto', 'nama']"
            tinggi-penuh
            :rows="baris.data"
            :total="baris.total"
            :links="baris.links"
            :empty-text="adaFilter ? `Tidak ada ${nama} yang cocok dengan filter.` : `Belum ada ${nama}.`"
            :sortable="sortableColumns"
            :sort="sort"
            @sort="urutkanTabel"
            :filterable="filterableColumns"
            :filter-values="filterValues"
            @filter="filterTabelTeks"
            baris-bisa-diklik
            @klik-baris="bukaDetail(`${base}/${$event.id}/edit`)"
        >
            <template #cell-foto="{ row }">
                <span class="foto-produk-kecil">
                    <img v-if="row.foto" :src="row.foto" :alt="`Foto ${row.nama}`" loading="lazy">
                    <Icon v-else name="camera" />
                </span>
            </template>
            <template #cell-aktif="{ row }">
                <Badge :variant="row.aktif === 'Aktif' ? 'success' : 'secondary'">{{ row.aktif }}</Badge>
            </template>
            <template #cell-total_area="{ row }">{{ angka(row.total_area) }}</template>
            <template #cell-jumlah_sales="{ row }">{{ angka(row.jumlah_sales) }}</template>
            <template #cell-diubah="{ row }"><span class="text-gray-500">{{ row.diubah ?? '-' }}</span></template>
        </DataTable>

        <Modal
            :show="detailUrl !== null"
            :title="detail ? `Detail ${nama}: ${detail.item.nama}` : judul"
            placement="kanan"
            @close="tutupDetail"
        >
            <Alert v-if="detailGagal" variant="danger" role="alert">Detail {{ nama }} tidak bisa dimuat. Coba lagi.</Alert>
            <FormSkeleton v-else-if="!detail" :harga="true" :satu-kolom="true" :jumlah-field="6" />
            <template v-else>
                <p class="mb-3 text-gray-500">
                    ID {{ detail.item.id }}<template v-if="detail.item.akun.length"> · akun login: {{ detail.item.akun.join(', ') }}</template>
                </p>
                <ReviewKaryawan
                    :kategori="kategori"
                    :nama="nama"
                    :item="detail.item"
                    :nilai="detail.nilai"
                    :opsi="detail.opsi"
                />
            </template>

            <template v-if="detail" #footer>
                <Button variant="light" @click="tutupDetail">Tutup</Button>
                <Button v-if="bisa.ubah" :tag="Link" :href="`${base}/${detail.item.id}/edit`">{{ kategori === 'sales' ? 'Update Motoris' : `Ubah ${nama}` }}</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
