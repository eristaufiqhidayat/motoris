<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import Skeleton from '@/Components/ui/atoms/Skeleton.vue';
import Tabs from '@/Components/ui/molecules/Tabs.vue';
import { tanggalSingkat, tanggalWaktuPendek } from '@/Support/format.js';
import PeriksaTanda from './PeriksaTanda.vue';

// Tanda motoris pada tanggal yang sedang dilihat. Sejak spec 062 tanda dibuat sistem (Sinkronisasi Data di Beranda
// Koordinator) dengan status Perlu dicek; koordinator tidak lagi menandai manual, tetapi memeriksa tanda itu
// (Terbukti / Wajar + catatan) bila akun punya hak Tambah.
// Dua tampilan: bawaan (dipasang di header RuteHarianPeta, daftar sebagai panel melayang supaya tidak mendorong konten
// di bawahnya, dipisah "tanggal ini" / "tanggal lain") dan `drawer` (dipasang sendirian di drawer dari kolom Ditandai:
// semua tanda aktif dalam jendela hari terakhir satu daftar, plus tab tanda outlet yang didaftarkan motoris ini).
const props = defineProps({
    url: { type: String, required: true },
    salesId: { type: Number, required: true },
    tanggal: { type: String, required: true },
    drawer: { type: Boolean, default: false },
});

const emit = defineEmits(['pilih-tanggal', 'berubah', 'lihat-peta']);

// Warna status tanda (config marketama.status_tanda).
const WARNA_STATUS = { perlu_dicek: 'warning', terbukti: 'danger', wajar: 'secondary' };

const tanda = ref([]);
// Tanda aktif motoris yang sama pada tanggal lain (jendela hari terakhir), dan apakah daftarnya sedang dibuka.
const lainnya = ref([]);
// Tanda outlet pada outlet yang didaftarkan motoris ini; hanya untuk ditinjau di drawer (diperiksa di menu All Outlet).
const outletTanda = ref([]);
const lainBuka = ref(false);
const memuat = ref(true);
const pesan = ref(null);
// Daftar tanda ditampilkan sebagai panel melayang (absolute) saat counter diklik, supaya tidak mendorong konten di bawahnya.
const daftarBuka = ref(false);
const wadah = ref(null);
// Id tanda yang form hasil ceknya sedang terbuka; null = tertutup.
const periksaId = ref(null);

// Drawer: semua tanda aktif dalam jendela (tanggal yang dilihat + tanggal lain) satu daftar, terbaru di atas.
const semuaTanda = computed(() => [
    ...tanda.value.map((t) => ({ ...t, tanggal: props.tanggal })),
    ...lainnya.value,
].sort((a, b) => (a.tanggal === b.tanggal ? b.id - a.id : b.tanggal.localeCompare(a.tanggal))));

// Drawer: tanda outlet (pada outlet yang didaftarkan motoris ini) dipisah dari tanda motoris; terbaru di atas.
const daftarTandaOutlet = computed(() => [...outletTanda.value].sort((a, b) => (a.tanggal === b.tanggal ? b.id - a.id : b.tanggal.localeCompare(a.tanggal))));

// Drawer: tab 'motoris' | 'outlet'. Tab outlet dibuka otomatis bila hanya itu yang punya isi (lihat muat()).
const tabAktif = ref('motoris');
const tabDrawer = computed(() => [
    { key: 'motoris', label: `Tanda motoris (${semuaTanda.value.length})` },
    { key: 'outlet', label: `Tanda outlet (${daftarTandaOutlet.value.length})` },
]);

// "25 Okt 2026 · 09:00" dari "Y-m-d H:i" (waktu tanda itu DIBUAT, beda dari tanggal kejadian/tanggal baris).
function waktuDibuat(nilai) {
    const waktu = tanggalWaktuPendek(nilai);

    return waktu ? `${waktu.tanggal} · ${waktu.jam}` : nilai;
}

// Klik di luar menyembunyikan panel melayang. Didengar di fase capture: klik di peta (Leaflet) atau elemen lain yang
// menghentikan propagasi tetap terhitung.
function tutupDiLuar(e) {
    if (wadah.value && !wadah.value.contains(e.target)) {
        daftarBuka.value = false;
        lainBuka.value = false;
    }
}

async function muat() {
    const tanggal = props.tanggal;
    memuat.value = true;
    pesan.value = null;

    try {
        const respon = await fetch(`${props.url}?${new URLSearchParams({ sales_id: props.salesId, tanggal })}`, { headers: { Accept: 'application/json' } });
        if (!respon.ok) throw new Error(`HTTP ${respon.status}`);
        const data = await respon.json();

        // Respons lama (tanggal sudah diganti) dibuang.
        if (tanggal !== props.tanggal) return;
        tanda.value = data.tanda;
        lainnya.value = data.lainnya ?? [];
        outletTanda.value = data.outlet ?? [];
        if (props.drawer && !tanda.value.length && !lainnya.value.length && outletTanda.value.length) tabAktif.value = 'outlet';
    } catch {
        pesan.value = 'Gagal memuat tanda motoris.';
    } finally {
        memuat.value = false;
    }
}

async function selesaiPeriksa() {
    periksaId.value = null;
    await muat();
    emit('berubah', tanda.value.length);
}

onMounted(muat);
onMounted(() => document.addEventListener('click', tutupDiLuar, true));
onBeforeUnmount(() => document.removeEventListener('click', tutupDiLuar, true));
// Tanggal drawer diganti (mode bawaan): muat tanda tanggal itu, tutup daftar & form yang terbuka.
watch(() => props.tanggal, () => {
    daftarBuka.value = false;
    lainBuka.value = false;
    periksaId.value = null;
    muat();
});
</script>

<template>
    <section v-if="drawer" class="tanda-motoris" aria-label="Tanda motoris">
        <Tabs v-model="tabAktif" :tabs="tabDrawer" rapat />

        <div v-if="memuat && !semuaTanda.length && !daftarTandaOutlet.length" class="animate-pulse space-y-2" role="status" aria-busy="true" aria-label="Memuat tanda motoris">
            <Skeleton class="h-12 w-full" />
        </div>

        <p v-if="pesan" class="mb-0 text-xs text-red-600">{{ pesan }}</p>

        <template v-if="tabAktif === 'motoris'">
            <ul v-if="semuaTanda.length" class="tanda-motoris-daftar">
                <li v-for="item in semuaTanda" :key="item.id" class="tanda-motoris-item">
                    <div class="tanda-item-kepala">
                        <Badge variant="danger">{{ item.kategori_label }}</Badge>
                        <Badge :variant="WARNA_STATUS[item.status] ?? 'secondary'">{{ item.status_label }}</Badge>
                        <span class="tanda-item-judul">{{ tanggalSingkat(item.tanggal) }}</span>
                        <span class="tanda-item-meta">Dibuat {{ waktuDibuat(item.waktu) }} oleh {{ item.oleh }}</span>
                        <div v-if="item.bisa_periksa && periksaId !== item.id" class="tanda-item-aksi">
                            <button type="button" class="tautan-depo text-xs" @click="periksaId = item.id"><Icon name="badge-check" class="me-1" />Periksa</button>
                        </div>
                    </div>
                    <p v-if="item.catatan" class="tanda-item-catatan">{{ item.catatan }}</p>
                    <p v-if="item.hasil_catatan" class="tanda-item-catatan"><span class="font-semibold">Hasil cek ({{ item.dicek_oleh }} · {{ item.dicek_waktu }}):</span> {{ item.hasil_catatan }}</p>
                    <PeriksaTanda v-if="periksaId === item.id" class="mt-1" :url="`${url}/${item.id}/periksa`" @selesai="selesaiPeriksa" @batal="periksaId = null" />
                </li>
            </ul>
            <p v-else-if="!memuat && !pesan" class="tanda-kosong">Belum ada data.</p>
        </template>

        <!-- Tanda outlet yang didaftarkan motoris ini: diperiksa di menu All Outlet, di sini hanya untuk ditinjau. -->
        <template v-else>
            <ul v-if="daftarTandaOutlet.length" class="tanda-motoris-daftar">
                <li v-for="item in daftarTandaOutlet" :key="item.id" class="tanda-motoris-item">
                    <div class="tanda-item-kepala">
                        <Badge variant="danger">{{ item.kategori_label }}</Badge>
                        <span class="tanda-item-judul">{{ item.outlet }}</span>
                        <div v-if="item.latitude != null && item.longitude != null" class="tanda-item-aksi">
                            <button type="button" class="tautan-depo text-xs" @click="$emit('lihat-peta', { id: item.outlet_id, nama: item.outlet, latitude: item.latitude, longitude: item.longitude })"><Icon name="map-pin" class="me-1" />Lihat peta</button>
                        </div>
                    </div>
                    <p class="tanda-item-meta mb-0 mt-1">
                        Ditandai {{ tanggalSingkat(item.tanggal) }} oleh {{ item.oleh }}
                        <template v-if="item.didaftarkan"> · Didaftarkan (NOO) {{ waktuDibuat(item.didaftarkan) }}</template>
                    </p>
                    <p v-if="item.catatan" class="tanda-item-catatan">{{ item.catatan }}</p>
                </li>
            </ul>
            <p v-else-if="!memuat && !pesan" class="tanda-kosong">Belum ada data.</p>
        </template>
    </section>

    <section v-else ref="wadah" class="tanda-motoris tanda-motoris-datar" aria-label="Tanda motoris">
        <div class="flex flex-wrap items-center gap-2">
            <!-- Tombol yang menjelaskan dirinya sendiri, jadi tidak perlu judul "Tanda motoris" di atasnya. -->
            <button v-if="tanda.length" type="button" class="tanda-chip" :aria-expanded="daftarBuka" @click="lainBuka = false; daftarBuka = !daftarBuka">
                <Badge variant="danger">{{ tanda.length }}</Badge>Tanda tanggal ini
            </button>
            <button v-if="lainnya.length" type="button" class="tanda-chip" :aria-expanded="lainBuka" @click="daftarBuka = false; lainBuka = !lainBuka">
                <Badge variant="warning">{{ lainnya.length }}</Badge>Tanda tanggal lain
            </button>
        </div>

        <p v-if="pesan" class="mb-0 text-xs text-red-600">{{ pesan }}</p>

        <Transition name="flyover">
            <div v-if="daftarBuka" class="tanda-motoris-panel">
                <ul v-if="tanda.length" class="tanda-motoris-daftar">
                    <li v-for="item in tanda" :key="item.id" class="tanda-motoris-item">
                        <div class="tanda-item-kepala">
                            <Badge variant="danger">{{ item.kategori_label }}</Badge>
                            <Badge :variant="WARNA_STATUS[item.status] ?? 'secondary'">{{ item.status_label }}</Badge>
                            <button v-if="item.bisa_periksa && periksaId !== item.id" type="button" class="tautan-depo ml-auto text-xs" @click="periksaId = item.id">Periksa</button>
                        </div>
                        <p v-if="item.catatan" class="tanda-item-catatan">{{ item.catatan }}</p>
                        <p v-if="item.hasil_catatan" class="tanda-item-catatan"><span class="font-semibold">Hasil cek ({{ item.dicek_oleh }}):</span> {{ item.hasil_catatan }}</p>
                        <PeriksaTanda v-if="periksaId === item.id" class="mt-1" :url="`${url}/${item.id}/periksa`" @selesai="selesaiPeriksa" @batal="periksaId = null" />
                    </li>
                </ul>
                <p v-else-if="!memuat && !pesan" class="mb-0 text-xs text-gray-500">Belum ada tanda pada tanggal ini.</p>
            </div>
        </Transition>

        <Transition name="flyover">
            <div v-if="lainBuka && lainnya.length" class="tanda-motoris-panel">
                <ul class="tanda-motoris-daftar">
                    <li v-for="item in lainnya" :key="item.id">
                        <button type="button" class="tanda-motoris-lain" @click="$emit('pilih-tanggal', item.tanggal)">
                            <span class="font-semibold">{{ tanggalSingkat(item.tanggal) }}</span> · {{ item.kategori_label }} · {{ item.status_label }}
                        </button>
                    </li>
                </ul>
            </div>
        </Transition>
    </section>
</template>
