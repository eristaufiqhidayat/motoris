<script setup>
import { computed, ref } from 'vue';
import { Deferred, Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import DatePicker from '@/Components/ui/atoms/DatePicker.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import InfoPopover from '@/Components/ui/molecules/InfoPopover.vue';
import Modal from '@/Components/ui/molecules/Modal.vue';
import PageHeader from '@/Components/ui/molecules/PageHeader.vue';
import StatCard from '@/Components/ui/molecules/StatCard.vue';
import DashboardSkeletonKoordinator from './partials/DashboardSkeletonKoordinator.vue';
import DrawerRingkasan from './partials/DrawerRingkasan.vue';
import FunnelOutletChart from './partials/FunnelOutletChart.vue';
import TrenHarianChart from './partials/TrenHarianChart.vue';
import DetailDepoTim from '@/Pages/Motoris/partials/DetailDepoTim.vue';
import { angka } from '@/Support/format.js';
import { bersihkanQuery } from '@/Support/query.js';
import { useAmbilData } from '@/Support/ambilData.js';

const props = defineProps({
    slug: { type: String, required: true },
    judul: { type: String, required: true },
    // Selalu hari ini (lihat HomeController::koordinator); hanya Tren Harian yang punya rentang sendiri (trenFilter).
    periode: { type: Object, required: true },
    depo: { type: Object, default: null },
    // Ringan; dikirim langsung (bukan Deferred) supaya status hari ini langsung terlihat.
    absenHariIni: { type: Object, required: true },
    // { sudah, belum, total, batas, lewat } — lihat HomeController::checkinOutletHariIni.
    checkinOutlet: { type: Object, required: true },
    // slug => URL halaman rincian, atau null bila akun tidak boleh membukanya.
    tautan: { type: Object, required: true },
    // Rentang Tim Saya (bawaan 7 hari terakhir) — lihat HomeController::rentangFilter.
    timFilter: { type: Object, required: true },
    // Rentang Tren Harian (bawaan 7 hari terakhir) — lihat HomeController::rentangFilter.
    trenFilter: { type: Object, required: true },
    // Kosong saat masih ditunda (lihat <Deferred> di bawah); semuanya tiba bersamaan (satu grup "ringkasan").
    detail: { type: Object, default: null },
    funnel: { type: Array, default: null },
    tren: { type: Array, default: null },
    tandaMotoris: { type: Object, default: null },
    tandaOutlet: { type: Object, default: null },
});

const appName = usePage().props.appName;
const subtitle = computed(() => ['Ringkasan tim Anda hari ini', props.periode.dari, props.depo?.nama].filter(Boolean).join(' · '));

const persen = (bagian, total) => (total > 0 ? (bagian / total) * 100 : 0);

// Nada kartu (warna ikon & progres, lihat .stat-card-ikon-* di app.css): hijau bila semua sudah, merah bila sudah
// tidak bisa dikejar lagi (lewat batas checkin), kuning bila masih berjalan.
const nadaAbsen = computed(() => {
    const { hadir, total } = props.absenHariIni;

    if (total === 0) return 'netral';

    return hadir >= total ? 'sukses' : 'peringatan';
});

const nadaCheckin = computed(() => {
    const { belum, total, lewat } = props.checkinOutlet;

    if (total === 0) return 'netral';
    if (belum === 0) return 'sukses';

    return lewat ? 'bahaya' : 'peringatan';
});

const nadaTanda = (jumlah) => ((jumlah ?? 0) > 0 ? 'bahaya' : 'sukses');

// Drawer kartu ringkas (Absen Datang, Checkin Outlet, Motoris/Outlet Ditandai): satu Modal dipakai ulang untuk
// keempatnya, diisi lewat fetch JSON ke rute home/* (lihat HomeController) — bukan Inertia::render, supaya
// tidak pindah halaman. Pola fetch sama dengan "Lihat Detail" di Motoris/SummaryDepo.vue.
const DRAWER = {
    absen: { judul: 'Absen Datang Hari Ini', url: '/home/absen-hari-ini' },
    checkin: { judul: 'Checkin Outlet Hari Ini', url: '/home/checkin-outlet' },
    motoris_ditandai: { judul: 'Motoris Ditandai', url: '/home/motoris-ditandai' },
    outlet_ditandai: { judul: 'Outlet Ditandai', url: '/home/outlet-ditandai' },
};

const drawerJenis = ref(null);
const drawerBaris = ref(null);
const drawerGalat = ref(null);

async function bukaDrawer(jenis) {
    drawerJenis.value = jenis;
    drawerBaris.value = null;
    drawerGalat.value = null;

    try {
        const res = await fetch(DRAWER[jenis].url, { headers: { Accept: 'application/json' } });

        if (!res.ok) {
            throw new Error(`Gagal memuat data (${res.status}).`);
        }

        drawerBaris.value = (await res.json()).baris;
    } catch {
        drawerGalat.value = 'Gagal memuat data. Coba lagi.';
    }
}

function tutupDrawer() {
    drawerJenis.value = null;
}

const timDari = ref(props.timFilter.dari);
const timSampai = ref(props.timFilter.sampai);

// Rentang Tim Saya dimuat terpisah dari sisa halaman saat tombol Tampilkan diklik, sama dengan pola Home/Dashboard.vue.
const { memuat: memuatTim, berubah: berubahTim, ambil: ambilTim } = useAmbilData({
    form: () => ({ tim_dari: timDari.value, tim_sampai: timSampai.value }),
    diterapkan: () => ({ tim_dari: props.timFilter.dari, tim_sampai: props.timFilter.sampai }),
});

function tampilkanTim() {
    ambilTim('/home', bersihkanQuery({ tim_dari: timDari.value, tim_sampai: timSampai.value }), {
        only: ['detail', 'timFilter'],
        preserveScroll: true,
    });
}

const trenDari = ref(props.trenFilter.dari);
const trenSampai = ref(props.trenFilter.sampai);

// Rentang Tren Harian dimuat terpisah dari sisa halaman saat tombol Tampilkan diklik, sama dengan pola Home/Dashboard.vue.
const { memuat: memuatTren, berubah: berubahTren, ambil: ambilTren } = useAmbilData({
    form: () => ({ tren_dari: trenDari.value, tren_sampai: trenSampai.value }),
    diterapkan: () => ({ tren_dari: props.trenFilter.dari, tren_sampai: props.trenFilter.sampai }),
});

function tampilkanTren() {
    ambilTren('/home', bersihkanQuery({ tren_dari: trenDari.value, tren_sampai: trenSampai.value }), {
        only: ['tren', 'trenFilter'],
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`${judul} · ${appName}`" />
    <AppLayout :breadcrumb-slug="slug">
        <PageHeader :title="judul" :subtitle="subtitle">
            <template #info>
                <InfoPopover title="Tentang Beranda" class="ms-1 align-middle">
                    <ul class="list-disc ps-4 space-y-1">
                        <li>Semua angka hanya mencakup tim (sales) di bawah Anda — cakupan "Per downline" role Koordinator.</li>
                        <li><b>Absen Datang</b> dan <b>Checkin Outlet</b> untuk hari ini. Checkin outlet paling lambat jam 11.00 waktu setempat; lewat jam itu motoris tidak bisa checkin lagi, jadi yang masih "belum" tidak akan checkin hari ini.</li>
                        <li><b>Motoris Ditandai</b> dan <b>Outlet Ditandai:</b> tanda yang masih aktif (belum dicabut), lepas dari tanggal tanda dibuat — sama dengan menu Rekap Motoris Ditandai/Rekap Outlet Ditandai.</li>
                        <li>Klik kartu Absen Datang, Checkin Outlet, Motoris Ditandai, atau Outlet Ditandai untuk melihat rinciannya di panel samping.</li>
                        <li><b>Tim Saya</b> dan <b>Tren Harian</b> bisa difilter ke rentang tanggal lain (bawaan 7 hari terakhir, maks. 62 hari) lewat kolom tanggal di kartunya; detail per sales sama dengan "Lihat Detail" di Summary All Depo.</li>
                        <li><b>Funnel Outlet</b> untuk hari ini saja, tidak bisa difilter.</li>
                        <li><b>Funnel Outlet:</b> NOO (outlet baru hari ini) → AC (dikunjungi, belum pernah EC) → EC (effective call) → RO (EC di ≥2 tanggal berbeda). NOO hanya menghitung outlet yang didaftarkan lewat aplikasi HP motoris (belum termasuk riwayat outlet dari Marketama).</li>
                    </ul>
                </InfoPopover>
            </template>
        </PageHeader>

        <div class="grid gap-3 lg:grid-cols-2 mb-4">
            <section aria-labelledby="judul-hari-ini">
                <h6 id="judul-hari-ini" class="judul-bagian">Kehadiran Tim Hari Ini</h6>
                <div class="grid gap-3 sm:grid-cols-2">
                    <StatCard
                        label="Absen Datang"
                        icon="user-check"
                        :nada="nadaAbsen"
                        clickable
                        :progres="absenHariIni.total ? persen(absenHariIni.hadir, absenHariIni.total) : null"
                        @click="bukaDrawer('absen')"
                    >
                        {{ angka(absenHariIni.hadir) }} <span class="stat-card-satuan">/ {{ angka(absenHariIni.total) }} motoris</span>
                        <template #keterangan>
                            {{ absenHariIni.total ? `${angka(absenHariIni.total - absenHariIni.hadir)} belum absen` : 'Belum ada motoris di tim Anda.' }}
                        </template>
                    </StatCard>
                    <StatCard
                        label="Checkin Outlet"
                        icon="store"
                        :nada="nadaCheckin"
                        clickable
                        :progres="checkinOutlet.total ? persen(checkinOutlet.sudah, checkinOutlet.total) : null"
                        @click="bukaDrawer('checkin')"
                    >
                        {{ angka(checkinOutlet.sudah) }} <span class="stat-card-satuan">/ {{ angka(checkinOutlet.total) }} motoris</span>
                        <template #keterangan>
                            <Icon name="clock" class="inline h-3 w-3 -mt-0.5" />
                            {{ angka(checkinOutlet.belum) }} belum ·
                            {{ checkinOutlet.lewat ? `batas ${checkinOutlet.batas} terlewat` : `batas jam ${checkinOutlet.batas}` }}
                        </template>
                    </StatCard>
                </div>
            </section>

            <section aria-labelledby="judul-perhatian">
                <h6 id="judul-perhatian" class="judul-bagian">Perlu Perhatian</h6>
                <div class="grid gap-3 sm:grid-cols-2">
                    <StatCard
                        label="Motoris Ditandai"
                        icon="flag"
                        :nada="nadaTanda(tandaMotoris?.motoris)"
                        :clickable="!!tautan.motoris_ditandai"
                        :memuat="!tandaMotoris"
                        @click="bukaDrawer('motoris_ditandai')"
                    >
                        {{ angka(tandaMotoris?.motoris) }} <span class="stat-card-satuan">motoris</span>
                        <template #keterangan>
                            {{ tandaMotoris?.total ? `${angka(tandaMotoris.total)} tanda aktif` : 'Tidak ada tanda aktif.' }}
                        </template>
                    </StatCard>
                    <StatCard
                        label="Outlet Ditandai"
                        icon="flag"
                        :nada="nadaTanda(tandaOutlet?.outlet)"
                        :clickable="!!tautan.outlet_ditandai"
                        :memuat="!tandaOutlet"
                        @click="bukaDrawer('outlet_ditandai')"
                    >
                        {{ angka(tandaOutlet?.outlet) }} <span class="stat-card-satuan">outlet</span>
                        <template #keterangan>
                            {{ tandaOutlet?.total ? `${angka(tandaOutlet.total)} tanda aktif` : 'Tidak ada tanda aktif.' }}
                        </template>
                    </StatCard>
                </div>
            </section>
        </div>

        <Deferred :data="['detail', 'funnel', 'tren', 'tandaMotoris', 'tandaOutlet']">
            <template #fallback>
                <DashboardSkeletonKoordinator />
            </template>

            <Card class="shadow-sm mb-4" body-class="card-body overflow-x-auto">
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h6 class="mb-0 font-semibold">Tim Saya</h6>
                        <form class="flex flex-wrap items-center gap-2" role="search" aria-label="Rentang Tim Saya" @submit.prevent="tampilkanTim">
                            <DatePicker v-model="timDari" :max="timSampai" aria-label="Dari tanggal" class="w-32" />
                            <span class="text-gray-400" aria-hidden="true">–</span>
                            <DatePicker v-model="timSampai" :min="timDari" aria-label="Sampai tanggal" class="w-32" />
                            <Button type="submit" :loading="memuatTim" :disabled="!berubahTim">
                                <Icon name="search" class="me-1 w-3.5 h-3.5" />Tampilkan
                            </Button>
                        </form>
                    </div>
                </template>
                <DetailDepoTim v-if="detail" :total="detail.total" :tim="detail.tim" tanpa-total-depo />
                <p v-else class="text-center text-gray-500 py-4">Akun Anda belum terhubung ke data karyawan/depo, jadi ringkasan tim belum bisa ditampilkan.</p>
            </Card>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-3">
                <Card class="shadow-sm lg:col-span-7">
                    <template #header>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h6 class="mb-0 font-semibold">Tren Harian</h6>
                            <form class="flex flex-wrap items-center gap-2" role="search" aria-label="Rentang Tren Harian" @submit.prevent="tampilkanTren">
                                <DatePicker v-model="trenDari" :max="trenSampai" aria-label="Dari tanggal" class="w-32" />
                                <span class="text-gray-400" aria-hidden="true">–</span>
                                <DatePicker v-model="trenSampai" :min="trenDari" aria-label="Sampai tanggal" class="w-32" />
                                <Button type="submit" :loading="memuatTren" :disabled="!berubahTren">
                                    <Icon name="search" class="me-1 w-3.5 h-3.5" />Tampilkan
                                </Button>
                            </form>
                        </div>
                    </template>
                    <TrenHarianChart :data="tren" />
                </Card>
                <Card class="shadow-sm lg:col-span-5">
                    <template #header>
                        <h6 class="mb-0 font-semibold">Funnel Outlet · Hari Ini</h6>
                    </template>
                    <FunnelOutletChart :funnel="funnel" />
                </Card>
            </div>
        </Deferred>

        <Modal :show="!!drawerJenis" :title="drawerJenis ? DRAWER[drawerJenis].judul : ''" placement="kanan" @close="tutupDrawer">
            <DrawerRingkasan v-if="drawerJenis" :jenis="drawerJenis" :baris="drawerBaris" :galat="drawerGalat" />
        </Modal>
    </AppLayout>
</template>
