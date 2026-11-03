<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Button from '@/Components/ui/atoms/Button.vue';
import DatePicker from '@/Components/ui/atoms/DatePicker.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import Modal from '@/Components/ui/molecules/Modal.vue';
import Tabs from '@/Components/ui/molecules/Tabs.vue';
import DataTable from '@/Components/ui/organisms/DataTable.vue';
import PeriksaTanda from '@/Pages/Menu/partials/PeriksaTanda.vue';
import PetaOutletBertanda from '@/Pages/Menu/partials/PetaOutletBertanda.vue';
import RuteHarianPeta from '@/Pages/Menu/partials/RuteHarianPeta.vue';
import { angka, tanggalSingkat } from '@/Support/format.js';
import { urlStreetView } from '@/Support/peta.js';
import { infoRiwayat } from '@/Support/riwayat.js';

// Kartu "Temuan Sistem" di Beranda Koordinator (spec 062): antrean tanda Perlu dicek yang dibuat sistem — pengganti
// penandaan manual. Tombol Sinkronisasi Data menambahkan tanda baru untuk tim (HomeController::sinkronTemuan):
// diduga lokasi palsu per motoris-tanggal pada rentang terpilih, dan titik outlet diduga fiktif sampai antrean penuh.
// Koordinator mengecek (lapangan / Street View) lalu menutupnya dengan Terbukti / Wajar; baris yang sudah diperiksa
// keluar dari antrean.
const props = defineProps({
    // { motoris: [{ id, karyawan_id, motoris, tanggal, kategori_label, catatan }],
    //   titik: [{ tanda_id, kelompok, skor, catatan, pendaftar, jumlah, outlet: [nama], acuan: { id, nama, latitude, longitude } }],
    //   antrean_titik, pemeriksaan: { diperiksa, total, terakhir } }
    temuan: { type: Object, required: true },
    // Rentang sinkronisasi { dari, sampai, maks_hari }.
    filter: { type: Object, required: true },
});

const KOLOM_MOTORIS = { tanggal: 'Tanggal', motoris: 'Motoris', kategori_label: 'Dugaan', catatan: 'Alasan' };
const KOLOM_TITIK = { pendaftar: 'Pendaftar', jumlah: 'Outlet', outlet: 'Nama Outlet', catatan: 'Alasan', skor: 'Skor' };

const page = usePage();
const dari = ref(props.filter.dari);
const sampai = ref(props.filter.sampai);
const menyinkron = ref(false);
const galat = ref(null);
const tab = ref(props.temuan.motoris.length || !props.temuan.titik.length ? 'motoris' : 'titik');
const tabs = computed(() => [
    { key: 'motoris', label: `Motoris (${props.temuan.motoris.length})` },
    { key: 'titik', label: `Titik outlet (${props.temuan.titik.length})` },
]);
const riwayatTerpilih = ref(null);
const petaTerpilih = ref(null);
// { url, judul, keterangan } tanda yang sedang diperiksa; null = tertutup.
const periksa = ref(null);

function sinkronkan() {
    galat.value = null;

    router.post('/home/sinkronisasi-temuan', { dari: dari.value, sampai: sampai.value }, {
        // Kartu Motoris/Outlet Ditandai ikut dimuat ulang karena tanda baru ikut terhitung di sana.
        only: ['temuan', 'temuanFilter', 'tandaMotoris', 'tandaOutlet', 'flash'],
        preserveScroll: true,
        onStart: () => { menyinkron.value = true; },
        onFinish: () => { menyinkron.value = false; },
        onError: (e) => { galat.value = Object.values(e)[0] ?? 'Sinkronisasi gagal.'; },
    });
}

// Hak & bentuk data riwayat sama dengan rekap Motoris Ditandai (karyawan_id, motoris, tanggal).
const riwayat = (b) => infoRiwayat('tanda_motoris_rekap', b, page.props.slugRiwayat);

function periksaMotoris(b) {
    periksa.value = { url: `/tracking_sales/tanda/${b.id}/periksa`, judul: `Periksa · ${b.motoris} (${tanggalSingkat(b.tanggal)})`, keterangan: b.catatan };
}

function periksaTitik(t) {
    periksa.value = {
        url: `/outlet_all/tanda/${t.tanda_id}/periksa`,
        judul: `Periksa titik · ${t.acuan.nama}`,
        keterangan: `Hasil cek berlaku untuk ${angka(t.jumlah)} outlet di titik ini. ${t.catatan}`,
    };
}

function selesaiPeriksa() {
    periksa.value = null;
    router.reload({ only: ['temuan', 'tandaMotoris', 'tandaOutlet'], preserveScroll: true });
}
</script>

<template>
    <section class="mb-4" aria-labelledby="judul-temuan-sistem">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h6 id="judul-temuan-sistem" class="judul-bagian mb-0">Temuan Sistem · Perlu Dicek</h6>
            <form class="flex flex-wrap items-center gap-2" aria-label="Rentang sinkronisasi" @submit.prevent="sinkronkan">
                <DatePicker v-model="dari" :max="sampai" aria-label="Dari tanggal" class="w-32" />
                <span class="text-gray-400" aria-hidden="true">–</span>
                <DatePicker v-model="sampai" :min="dari" aria-label="Sampai tanggal" class="w-32" />
                <Button type="submit" :loading="menyinkron">
                    <Icon name="database" class="me-1 w-3.5 h-3.5" />Sinkronisasi Data
                </Button>
            </form>
        </div>
        <p class="mb-2 text-xs text-gray-500">
            {{ angka(temuan.pemeriksaan.diperiksa) }} / {{ angka(temuan.pemeriksaan.total) }} hari motoris pada rentang ini sudah diperiksa
            <template v-if="temuan.pemeriksaan.terakhir"> · terakhir {{ temuan.pemeriksaan.terakhir }}</template>
            · maks. {{ filter.maks_hari }} hari per sinkronisasi, antrean titik outlet maks. {{ temuan.antrean_titik }}.
            Tanda adalah dugaan dari pola data untuk dicek, bukan bukti kecurangan.
        </p>
        <p v-if="galat" class="mb-2 text-xs text-red-600" role="alert">{{ galat }}</p>

        <Tabs v-model="tab" :tabs="tabs" rapat />

        <DataTable
            v-if="tab === 'motoris'"
            class="shadow-sm"
            :columns="KOLOM_MOTORIS"
            :rows="temuan.motoris"
            :total="temuan.motoris.length"
            :memuat="menyinkron"
            empty-text="Tidak ada motoris yang perlu dicek. Tekan Sinkronisasi Data untuk memeriksa rentang tanggal di atas."
        >
            <template #cell-tanggal="{ row }"><span class="whitespace-nowrap">{{ tanggalSingkat(row.tanggal) }}</span></template>
            <template #cell-kategori_label="{ row }"><span class="temuan-tingkat temuan-tingkat-bahaya">{{ row.kategori_label }}</span></template>
            <template #cell-catatan="{ row }"><div class="min-w-56 max-w-80 whitespace-normal">{{ row.catatan }}</div></template>
            <template #aksi="{ row }">
                <span class="flex flex-wrap gap-3 whitespace-nowrap">
                    <button v-if="riwayat(row)" type="button" class="tautan-depo" @click="riwayatTerpilih = riwayat(row)">Riwayat</button>
                    <button type="button" class="tautan-depo" @click="periksaMotoris(row)">Periksa</button>
                </span>
            </template>
        </DataTable>

        <DataTable
            v-else
            class="shadow-sm"
            :columns="KOLOM_TITIK"
            :rows="temuan.titik"
            :total="temuan.titik.length"
            :memuat="menyinkron"
            empty-text="Tidak ada titik outlet yang perlu dicek. Tekan Sinkronisasi Data untuk mengisi antrean."
        >
            <template #cell-outlet="{ row }">
                <div class="min-w-48 max-w-64 whitespace-normal">
                    {{ row.outlet.join(', ') }}<template v-if="row.jumlah > row.outlet.length">, +{{ angka(row.jumlah - row.outlet.length) }} lainnya</template>
                </div>
            </template>
            <template #cell-catatan="{ row }"><div class="min-w-56 max-w-80 whitespace-normal">{{ row.catatan }}</div></template>
            <template #aksi="{ row }">
                <span class="flex flex-wrap gap-3 whitespace-nowrap">
                    <a class="tautan-depo" :href="urlStreetView(row.acuan.latitude, row.acuan.longitude)" target="_blank" rel="noopener">Street View</a>
                    <button type="button" class="tautan-depo" @click="petaTerpilih = row.acuan">Peta</button>
                    <button type="button" class="tautan-depo" @click="periksaTitik(row)">Periksa</button>
                </span>
            </template>
        </DataTable>
    </section>

    <Modal :show="periksa !== null" :title="periksa?.judul ?? ''" @close="periksa = null">
        <PeriksaTanda v-if="periksa" :url="periksa.url" :keterangan="periksa.keterangan" @selesai="selesaiPeriksa" @batal="periksa = null" />
    </Modal>

    <!-- Outlet bertanda lain di sekitar titik (outlet sekelompok berkoordinat sama, jarak 0 m). -->
    <Modal :show="petaTerpilih !== null" :title="petaTerpilih ? `Peta ${petaTerpilih.nama}` : ''" placement="kanan" @close="petaTerpilih = null">
        <PetaOutletBertanda v-if="petaTerpilih" url="/tracking_sales/tanda/outlet-terdekat" :outlet="petaTerpilih" />
    </Modal>

    <!-- Modal Riwayat yang sama dengan Lacak Motoris; tanda motoris tanggal itu bisa diperiksa dari sana juga. -->
    <Modal :show="riwayatTerpilih !== null" :title="riwayatTerpilih ? `Riwayat ${riwayatTerpilih.orang.nama}` : ''" size="full" @close="riwayatTerpilih = null">
        <RuteHarianPeta
            v-if="riwayatTerpilih"
            :orang="riwayatTerpilih.orang"
            :id="riwayatTerpilih.orang.id"
            :id-param="riwayatTerpilih.idParam"
            :tanggal="riwayatTerpilih.tanggal"
            :locations-url="`/${riwayatTerpilih.slug}/locations`"
            tanda-url="/tracking_sales/tanda"
        />
    </Modal>
</template>
