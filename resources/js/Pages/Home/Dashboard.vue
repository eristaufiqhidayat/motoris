<script setup>
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InfoPopover from '@/Components/ui/molecules/InfoPopover.vue';
import PageHeader from '@/Components/ui/molecules/PageHeader.vue';
import Tabs from '@/Components/ui/molecules/Tabs.vue';
import { lencanaTab } from './partials/metrikBeranda.js';
import TabKoordinator from './partials/TabKoordinator.vue';
import TabOutlet from './partials/TabOutlet.vue';
import TabRingkasan from './partials/TabRingkasan.vue';

// Beranda admin (spec 066/067): tujuan tim motoris adalah penyebaran produk ke sebanyak mungkin outlet. Isi dibagi dalam tab agar
// tidak membanjiri: Ringkasan (tab utama, satu kartu kunci per aspek) lalu rincian Outlet & Motoris serta Koordinator & Depo.
// Semua angka 30 hari terakhir dibanding 30 hari sebelumnya. Data semua tab dimuat sekali (tertunda, di-cache); pindah tab
// dilakukan di browser tanpa permintaan baru, dan ?tab= di URL menjaga tab yang terbuka saat halaman dimuat ulang.
const props = defineProps({
    slug: { type: String, required: true },
    judul: { type: String, required: true },
    // Periode tetap 30 hari terakhir; tidak ada filter tanggal di Beranda admin.
    periode: { type: Object, required: true },
    // Tab yang terbuka saat halaman dimuat (dari ?tab=, sudah disaring controller).
    tab: { type: String, default: 'ringkasan' },
    target: { type: Object, required: true },
    // Kehadiran hari ini: { hadir, total, tanggal } motoris aktif yang sudah absen datang.
    absenHariIni: { type: Object, default: () => ({ hadir: 0, total: 0, tanggal: null }) },
    // Checkin outlet pertama hari ini: { sudah, belum, total, batas, lewat }.
    checkinPagi: { type: Object, default: () => ({ sudah: 0, belum: 0, total: 0, batas: '11:00', lewat: false }) },
    // Ringkasan Laporan Kendala (spec 064); null bila akun tanpa hak Lihat.
    kendala: { type: Object, default: null },
    // Tautan kartu ({ slug: url | null }); null = akun tidak boleh membuka menu itu.
    tautan: { type: Object, required: true },
    // Ditunda (grup "ringkasan"): kpi + depoTertinggal, funnel outlet, tren harian, outlet per depo, tanda motoris.
    ringkasanDepo: { type: Object, default: null },
    funnel: { type: Array, default: null },
    tren: { type: Array, default: null },
    outletDepo: { type: Array, default: null },
    tandaMotoris: { type: Object, default: null },
    // Ditunda (grup "perbandingan"): { sekarang, sebelumnya, outlet_terdaftar, menunggu_total, menunggu_umur }.
    perbandingan: { type: Object, default: null },
    // Ditunda (grup "koordinator", spec 067): { nasional, sebelumnya, perhatian }.
    koordinator: { type: Object, default: null },
});

const appName = usePage().props.appName;
const subtitle = computed(() => `${props.periode.dari} – ${props.periode.sampai} (30 hari terakhir) dibanding 30 hari sebelumnya · data aplikasi motoris`);

// Semua prop data dikirim ke tab sebagai satu objek.
const data = computed(() => ({
    target: props.target, absenHariIni: props.absenHariIni, checkinPagi: props.checkinPagi,kendala: props.kendala, tautan: props.tautan, ringkasanDepo: props.ringkasanDepo, funnel: props.funnel,
    tren: props.tren, outletDepo: props.outletDepo, tandaMotoris: props.tandaMotoris, perbandingan: props.perbandingan, koordinator: props.koordinator,
}));

const aktif = ref(props.tab);
const lencana = computed(() => lencanaTab(data.value));
const tabs = computed(() => [
    { key: 'ringkasan', label: 'Ringkasan' },
    { key: 'outlet', label: 'Outlet & Motoris', badge: lencana.value.outlet },
    { key: 'koordinator', label: 'Koordinator & Depo', badge: lencana.value.koordinator },
]);

function pilih(kunci) {
    aktif.value = kunci;
    // Hanya mengganti URL (state riwayat Inertia dipertahankan), supaya muat ulang dan tautan bersama membuka tab yang sama.
    const url = new URL(window.location.href);
    if (kunci === 'ringkasan') url.searchParams.delete('tab'); else url.searchParams.set('tab', kunci);
    window.history.replaceState(window.history.state, '', url);
}
</script>

<template>
    <Head :title="`${judul} · ${appName}`" />
    <AppLayout :breadcrumb-slug="slug">
        <PageHeader :title="judul" :subtitle="subtitle">
            <template #info>
                <InfoPopover title="Tentang Beranda" class="ms-1 align-middle">
                    <ul class="list-disc ps-4 space-y-1">
                        <li>Semua angka memakai periode tetap <b>30 hari terakhir</b>, dibanding 30 hari sebelumnya (▲ naik, ▼ turun). Untuk rentang lain, buka Summary All Depo.</li>
                        <li><b>Ringkasan</b> memuat satu kartu kunci per aspek; klik kartu untuk membuka rinciannya. Lencana merah pada nama tab = jumlah hal yang perlu perhatian di tab itu.</li>
                        <li><b>Outlet Terjangkau:</b> outlet berbeda yang membeli (effective call, tidak dibatalkan) dalam 30 hari, tidak termasuk outlet yang sudah terbukti fiktif; persen terhadap seluruh outlet terdaftar.</li>
                        <li><b>Repeat Order:</b> persen outlet terjangkau yang membeli di ≥2 tanggal berbeda dalam 30 hari. Selisihnya dalam poin persen.</li>
                        <li><b>Outlet Baru:</b> pendaftaran outlet lewat aplikasi HP motoris yang sudah <b>disetujui</b> (belum termasuk riwayat outlet dari Marketama); yang menunggu verifikasi dihitung terpisah.</li>
                        <li><b>IPT</b> = SKU ÷ EC; <b>Call</b> dan <b>Effective Call</b> = rata-rata per hari kerja motoris, dihitung dari total (rata-rata berbobot) dengan rumus yang sama seperti Summary All Depo. Batang = capaian terhadap target nasional.</li>
                        <li><b>Kualitas Data Outlet:</b> <i>Outlet Terbukti Fiktif</i> = tanda outlet yang ditutup Terbukti dalam 30 hari; <i>Rasio Fiktif</i> = Terbukti ÷ (Terbukti + Wajar), indikator yang lebih adil daripada jumlah karena ikut naik saat koordinator makin rajin memeriksa. <i>NOO Ditolak</i> = ditolak ÷ pendaftaran yang diverifikasi dalam 30 hari. Untuk metrik ini <b>turun berarti membaik</b> (hijau).</li>
                        <li><b>Kinerja Koordinator:</b> membandingkan temuan sistem (lokasi palsu, outlet fiktif; dibuat otomatis tiap malam) dengan tindak lanjut koordinator: persen temuan yang sudah diperiksa, median waktu, dan yang menunggu lebih dari 7 hari. Rincian cara hitung ada pada popover di judul tabel.</li>
                        <li><b>Outlet Stok Kosong:</b> outlet yang sudah dikunjungi tapi belum pernah membeli, dan kunjungan terakhirnya tidak mencatat stok produk kita — celah penyebaran.</li>
                        <li><b>Depo Tertinggal:</b> depo dengan EC Achievement di bawah 75%. <b>Depo · Outlet per Motoris</b> (tab Koordinator &amp; Depo) menampilkan 10 depo dengan jangkauan per motoris terendah.</li>
                        <li><b>Funnel Outlet:</b> NOO (outlet baru 30 hari ini) → AC (dikunjungi, belum pernah EC) → EC → RO, seumur hidup outlet, sama dengan definisi Lap. Outlet.</li>
                    </ul>
                </InfoPopover>
            </template>
        </PageHeader>

        <Tabs :tabs="tabs" :model-value="aktif" @update:model-value="pilih" />

        <div role="tabpanel" :aria-label="tabs.find((t) => t.key === aktif)?.label">
            <TabRingkasan v-if="aktif === 'ringkasan'" :data="data" @buka="pilih" />
            <TabOutlet v-else-if="aktif === 'outlet'" :data="data" />
            <TabKoordinator v-else :data="data" />
        </div>
    </AppLayout>
</template>
