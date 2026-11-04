<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import Button from '@/Components/ui/atoms/Button.vue';
import DatePicker from '@/Components/ui/atoms/DatePicker.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import DataTable from '@/Components/ui/organisms/DataTable.vue';
import { angka, angkaRingkas } from '@/Support/format.js';
import { bersihkanQuery } from '@/Support/query.js';
import { warnaCapai } from '@/Support/summaryDepo.js';
import { useAmbilData } from '@/Support/ambilData.js';

// Kartu Kinerja per Depo di Beranda admin (spec 066): AVG Call / EC / IPT setiap depo, angkanya dari SummaryDepo::kartu()
// (sama dengan Summary All Depo). Rentang sendiri (bawaan 30 hari), terpisah dari filter tanggal halaman. Pengurutan di sisi
// klien karena semua depo sudah ada di `kinerja`.
const props = defineProps({
    // Baris SummaryDepo::kartu(): id, nama, total_motoris, hari_kerja, avg_call, call_achievement, avg_ec, ec_achievement, avg_ipt, ipt_achievement.
    kinerja: { type: Array, required: true },
    // Rentang yang sedang tampil { dari, sampai }.
    filter: { type: Object, required: true },
    // Nama depo bertaut ke detailnya di Summary All Depo bila akun punya hak Lihat menu itu.
    tautan: { type: Boolean, default: false },
});

const KOLOM = {
    nama: 'Depo',
    total_motoris: 'Motoris Aktif',
    hari_kerja: 'Hari Kerja',
    avg_call: 'AVG Call',
    call_achievement: 'Call Ach.',
    avg_ec: 'AVG EC',
    ec_achievement: 'EC Ach.',
    avg_ipt: 'AVG IPT',
    ipt_achievement: 'IPT Ach.',
};
const ACHIEVEMENT = ['call_achievement', 'ec_achievement', 'ipt_achievement'];

const dari = ref(props.filter.dari);
const sampai = ref(props.filter.sampai);

const { memuat, berubah, ambil } = useAmbilData({
    form: () => ({ kinerja_dari: dari.value, kinerja_sampai: sampai.value }),
    diterapkan: () => ({ kinerja_dari: props.filter.dari, kinerja_sampai: props.filter.sampai }),
});

function tampilkan() {
    ambil('/home', bersihkanQuery({ kinerja_dari: dari.value, kinerja_sampai: sampai.value }), {
        only: ['kinerja', 'kinerjaFilter'],
        preserveState: true,
        preserveScroll: true,
    });
}

// Urutan bawaan = urutan dari server (EC Achievement tertinggi), yaitu saat sort null.
const sort = ref(null);
const baris = computed(() => {
    if (!sort.value) return props.kinerja;
    const { kolom, arah } = sort.value;
    const faktor = arah === 'asc' ? 1 : -1;

    return [...props.kinerja].sort((a, b) => (kolom === 'nama' ? a.nama.localeCompare(b.nama) : a[kolom] - b[kolom]) * faktor);
});

const urlDepo = (d) => `/summary_all_depo/${d.id}?${new URLSearchParams({ date_from: props.filter.dari, date_to: props.filter.sampai })}`;
</script>

<template>
    <DataTable
        class="shadow-sm"
        :columns="KOLOM"
        :rows="baris"
        :total="baris.length"
        :sortable="Object.keys(KOLOM)"
        :sort="sort"
        :memuat="memuat"
        empty-text="Belum ada depo dalam cakupan."
        @sort="sort = $event.arah ? $event : null"
    >
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span>Kinerja per Depo</span>
                <form class="flex flex-wrap items-center gap-2" role="search" @submit.prevent="tampilkan">
                    <DatePicker id="kinerja_dari" v-model="dari" aria-label="Kinerja dari tanggal" class="w-36" />
                    <DatePicker id="kinerja_sampai" v-model="sampai" aria-label="Kinerja sampai tanggal" class="w-36" />
                    <Button type="submit" size="sm" :loading="memuat" :disabled="!berubah"><Icon name="search" class="me-1 w-3.5 h-3.5" />Tampilkan</Button>
                </form>
            </div>
        </template>

        <template #cell-nama="{ row }">
            <Link v-if="tautan" :href="urlDepo(row)" class="font-semibold">{{ row.nama }}</Link>
            <span v-else class="font-semibold">{{ row.nama }}</span>
        </template>
        <template #cell-total_motoris="{ row }">{{ angka(row.total_motoris) }}</template>
        <template #cell-hari_kerja="{ row }">{{ angka(row.hari_kerja) }}</template>
        <template #cell-avg_call="{ row }">{{ angkaRingkas(row.avg_call, 1) }}</template>
        <template #cell-avg_ec="{ row }">{{ angkaRingkas(row.avg_ec, 1) }}</template>
        <template #cell-avg_ipt="{ row }">{{ angkaRingkas(row.avg_ipt, 1) }}</template>
        <template v-for="k in ACHIEVEMENT" :key="k" #[`cell-${k}`]="{ row }">
            <span :class="warnaCapai(row[k])">{{ angkaRingkas(row[k], 1) }}%</span>
        </template>
    </DataTable>
</template>
