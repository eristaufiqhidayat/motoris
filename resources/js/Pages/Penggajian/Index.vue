<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Alert from '@/Components/ui/molecules/Alert.vue';
import Combobox from '@/Components/ui/molecules/Combobox.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import DatePicker from '@/Components/ui/atoms/DatePicker.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import Modal from '@/Components/ui/molecules/Modal.vue';
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
    bisaEkspor: { type: Boolean, default: false },
    bisaUbah: { type: Boolean, default: false },
});

const page = usePage();
const appName = page.props.appName;
const galat = computed(() => page.props.errors?.penggajian ?? null);
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
    dipilih.value = [];
    ambil(base, { ...form, tab: props.tab }, { preserveState: true });
}

// Pindah tab: filter dikosongkan karena periode gaji dan insentif dinamai berbeda.
const pindahTab = (kunci) => {
    dipilih.value = [];
    router.get(base, { tab: kunci });
};

// Pilih penggajian lewat checkbox (draf dan final; yang dibatalkan tidak). Dari pilihan itu: Ekspor Excel (hanya bila semuanya Final)
// atau Finalkan (draf yang dipilih; yang sudah Final dilewati). Satu pilihan Excel = satu sheet; beberapa = konsolidasi + per depo.
const dipilih = ref([]);
const bisaPilih = computed(() => (props.bisaEkspor || props.bisaUbah) && props.catatan === null);

// Status final tiap id terpilih, diingat selagi barisnya tampil (pilihan bertahan antar halaman tabel).
const finalPerId = {};
watch([dipilih, () => props.baris.data], () => props.baris.data.forEach((r) => { finalPerId[r.id] = r.final; }), { immediate: true });

const jumlahDraf = computed(() => dipilih.value.filter((id) => !finalPerId[id]).length);
const urlEkspor = computed(() => `${base}/ekspor?${dipilih.value.map((id) => `ids[]=${id}`).join('&')}`);

const konfirmasiFinalkan = ref(false);
const memproses = ref(false);

function finalkanPilihan() {
    memproses.value = true;
    router.post(`${base}/finalkan`, { ids: dipilih.value }, {
        preserveScroll: true,
        onSuccess: () => { dipilih.value = []; },
        onFinish: () => { memproses.value = false; konfirmasiFinalkan.value = false; },
    });
}

const buka = (baris) => router.visit(`${base}/${baris.id}`);
</script>

<template>
    <Head :title="`${judul} · ${appName}`" />
    <AppLayout :breadcrumb-slug="slug">
        <PageHeader :title="judul" :subtitle="`Gaji dan insentif ${orangLabel} per depo dan periode · klik baris untuk melihat rincian`">
            <template #actions>
                <template v-if="bisaPilih && tab !== 'riwayat' && dipilih.length">
                    <Button v-if="bisaEkspor && !jumlahDraf" tag="a" variant="outline-secondary" :href="urlEkspor"><Icon name="download" class="me-1 w-3.5 h-3.5" />Ekspor Excel ({{ dipilih.length }})</Button>
                    <Button v-else-if="bisaEkspor" variant="outline-secondary" disabled title="Ekspor Excel hanya untuk penggajian berstatus Final; ada yang masih Draf di pilihan."><Icon name="download" class="me-1 w-3.5 h-3.5" />Ekspor Excel ({{ dipilih.length }})</Button>
                    <Button v-if="bisaUbah" variant="success" :disabled="!jumlahDraf" :title="jumlahDraf ? null : 'Semua yang dipilih sudah Final.'" @click="konfirmasiFinalkan = true"><Icon name="check" class="me-1 w-3.5 h-3.5" />Finalkan ({{ jumlahDraf }})</Button>
                </template>
                <Button v-else-if="bisaPilih && tab !== 'riwayat'" variant="outline-secondary" disabled title="Centang dulu penggajian yang akan diekspor atau difinalkan."><Icon name="download" class="me-1 w-3.5 h-3.5" />Ekspor Excel</Button>
                <Button v-if="bisaTambah" :tag="Link" :href="`${base}/tambah?kategori=${tab}`" variant="success"><Icon name="plus" class="me-1 w-3.5 h-3.5" />Tambah</Button>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="pindahTab" />

        <RiwayatOrang v-if="tab === 'riwayat'" :baris="baris" :q="q" :base="base" :orang-label="orangLabel" />

        <template v-else>
            <Alert v-if="catatan" variant="warning" class="mb-3">{{ catatan }}</Alert>
            <Alert v-if="galat" variant="danger" role="alert" class="mb-3">{{ galat }}</Alert>

            <Card class="mb-3" body-class="card-body card-body-compact">
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="tampilkan">
                    <div>
                        <Label for="periode">Periode</Label>
                        <DatePicker id="periode" v-model="form.periode" mode="bulan" placeholder="Pilih periode" clearable class="w-40" />
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
                v-model="dipilih"
                tinggi-penuh
                :dapat-dipilih="bisaPilih"
                :bisa-dipilih="(r) => r.bisa_dipilih"
                alasan-tidak-bisa-dipilih="Penggajian yang dibatalkan tidak bisa dipilih."
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

        <Modal :show="konfirmasiFinalkan" title="Finalkan penggajian terpilih" @close="konfirmasiFinalkan = false">
            <p class="mb-2">{{ jumlahDraf }} draf akan difinalkan dan terkunci: tidak bisa diubah, dihitung ulang, atau dibatalkan. Yang sudah Final dilewati.</p>
            <p class="mb-0">Bila ada satu draf yang datanya belum lengkap, tidak ada yang difinalkan.</p>
            <template #footer>
                <Button variant="light" @click="konfirmasiFinalkan = false">Batal</Button>
                <Button variant="success" :loading="memproses" @click="finalkanPilihan">Finalkan</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
