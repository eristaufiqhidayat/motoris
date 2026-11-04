<script setup>
import { computed, onMounted, ref } from 'vue';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import FieldError from '@/Components/ui/atoms/FieldError.vue';
import Input from '@/Components/ui/atoms/Input.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Skeleton from '@/Components/ui/atoms/Skeleton.vue';
import Tabs from '@/Components/ui/molecules/Tabs.vue';
import { BATAS_DETEKSI, bacaDeteksi, simpanDeteksi } from '@/Support/deteksiKunjungan.js';
import { jarakSingkat, tanggalSingkat } from '@/Support/format.js';
import DetailDepoPeta from './DetailDepoPeta.vue';
import TransaksiOutlet from './TransaksiOutlet.vue';

// Peta satu outlet bertanda beserta outlet bertanda lain di sekitarnya (TandaMotorisController::outletTerdekat):
// konteks bagi koordinator, mis. beberapa outlet fiktif berdekatan. Titik utama oranye, outlet sekitarnya merah.
// Radius = ambang "jarak dekat" yang sama dengan modal Riwayat (localStorage browser ini); bisa diubah dari sini.
// Outlet yang bagian titik temuan sistem (spec 062) ikut menampilkan seluruh outlet lain di titik itu (`titik`):
// koordinatnya identik, jadi di peta digambar sebagai satu penanda berangka.
const props = defineProps({
    // Url endpoint terdekat dan outlet acuan: { id, nama, latitude, longitude }.
    url: { type: String, required: true },
    outlet: { type: Object, required: true },
    // Endpoint rincian transaksi per outlet (dimuat saat tombol "Lihat transaksi" diklik).
    urlTransaksi: { type: String, default: '/tracking_sales/tanda/outlet-transaksi' },
});

const sekitar = ref([]);
// Outlet lain di titik yang sama dengan outlet ini: [{ outlet_id, outlet, ringkasan }].
const titik = ref([]);
// Ringkasan transaksi outlet acuan: { transaksi, hari, total } atau null.
const ringkasan = ref(null);
// Pendaftar outlet acuan (mk_outlet.dibuat_oleh).
const pendaftar = ref(null);
// Waktu outlet acuan didaftarkan (lihat TandaMotorisController::didaftarkan).
const didaftarkan = ref(null);
const radius = ref(bacaDeteksi().jarak_dekat_meter);
const form = ref(radius.value);
const memuat = ref(true);
const pesan = ref(null);
const info = ref(null);
// true selama pengambilan ulang akibat Terapkan (spinner di tombol); muat awal tidak ikut.
const menerapkan = ref(false);

const { min, maks } = BATAS_DETEKSI.jarak_dekat_meter;
const tab = ref('titik');
const daftarTab = computed(() => [
    { key: 'titik', label: `Outlet di titik ini (${titik.value.length + 1})` },
    { key: 'sekitar', label: `Outlet bertanda di sekitar (${sekitar.value.length})` },
]);
// Tanpa outlet sekelompok tab "titik" tidak ada, jadi daftar sekitar langsung tampil.
const tabAktif = computed(() => (titik.value.length ? tab.value : 'sekitar'));
const sah = computed(() => Number.isInteger(form.value) && form.value >= min && form.value <= maks);
const berubah = computed(() => form.value !== radius.value);

async function muat() {
    memuat.value = true;
    pesan.value = null;

    try {
        const respon = await fetch(`${props.url}?${new URLSearchParams({ outlet_id: props.outlet.id, radius_m: radius.value })}`, { headers: { Accept: 'application/json' } });
        if (!respon.ok) throw new Error(`HTTP ${respon.status}`);
        const data = await respon.json();
        sekitar.value = data.sekitar ?? [];
        titik.value = data.titik ?? [];
        ringkasan.value = data.ringkasan ?? null;
        pendaftar.value = data.pendaftar ?? null;
        didaftarkan.value = data.didaftarkan ?? null;
    } catch {
        pesan.value = 'Gagal memuat outlet bertanda di sekitar.';
    } finally {
        memuat.value = false;
    }
}

async function terapkan() {
    if (!sah.value) return;

    const { nilai, tersimpan } = simpanDeteksi({ ...bacaDeteksi(), jarak_dekat_meter: form.value });
    radius.value = nilai.jarak_dekat_meter;
    info.value = tersimpan ? null : 'Browser ini menolak penyimpanan lokal, jadi radius hanya berlaku sampai halaman dimuat ulang.';
    menerapkan.value = true;
    await muat();
    menerapkan.value = false;
}

onMounted(muat);
</script>

<template>
    <div class="peta-outlet-drawer">
        <Skeleton v-if="memuat" class="h-64 w-full" />
        <DetailDepoPeta v-else :key="radius" :depo="outlet" ikon="outlet" :sekitar="sekitar" :jumlah="titik.length + 1" google-map />

        <form class="tanda-motoris-form" aria-label="Radius outlet bertanda di sekitar" @submit.prevent="terapkan">
            <Label for="radius_sekitar">Radius pencarian (meter)</Label>
            <div class="flex flex-wrap items-center gap-2">
                <Input id="radius_sekitar" v-model.number="form" type="number" :min="min" :max="maks" class="w-28" />
                <Button type="submit" :loading="menerapkan" :disabled="!sah || !berubah || memuat">Terapkan</Button>
            </div>
            <FieldError v-if="!sah">Isi bilangan bulat {{ min }}–{{ maks }} meter.</FieldError>
            <p class="tanda-item-meta mb-0">Sama dengan ambang jarak dekat di modal Riwayat. Disimpan di browser ini saja.</p>
            <p v-if="info" class="mb-0 text-xs text-amber-700" role="status">{{ info }}</p>
        </form>

        <p v-if="pesan" class="mb-0 text-xs text-red-600">{{ pesan }}</p>

        <!-- Dua daftar berbagi satu area lewat tab; tanpa outlet sekelompok (titik kosong) hanya "di sekitar" yang ada. -->
        <Tabs v-if="!memuat && !pesan && titik.length" v-model="tab" :tabs="daftarTab" rapat />

        <section v-if="!memuat && !pesan && tabAktif === 'titik'" class="peta-outlet-daftar" aria-label="Outlet di titik ini">
            <p class="tanda-item-meta mb-0">{{ titik.length + 1 }} outlet berkoordinat identik.</p>
            <ul class="list-group tanda-titik-daftar mt-2">
                <TransaksiOutlet :url="urlTransaksi" :outlet-id="outlet.id" :nama="outlet.nama" :ringkasan="ringkasan" :pendaftar="pendaftar" :didaftarkan="didaftarkan" utama />
                <TransaksiOutlet v-for="o in titik" :key="o.outlet_id" :url="urlTransaksi" :outlet-id="o.outlet_id" :nama="o.outlet" :ringkasan="o.ringkasan" :pendaftar="o.pendaftar" :didaftarkan="o.didaftarkan" />
            </ul>
        </section>

        <section v-if="!memuat && !pesan && tabAktif === 'sekitar'" class="peta-outlet-daftar" aria-label="Outlet bertanda di sekitar">
            <p class="tanda-item-meta mb-0">Dalam radius {{ jarakSingkat(radius) }} dari outlet ini.</p>
            <ul v-if="sekitar.length" class="tanda-motoris-daftar mt-2">
                <li v-for="o in sekitar" :key="o.outlet_id" class="tanda-motoris-item">
                    <div class="tanda-item-kepala">
                        <Badge variant="danger">{{ o.kategori_label }}</Badge>
                        <span class="tanda-item-judul">{{ o.outlet }}</span>
                        <span class="tanda-item-meta">{{ jarakSingkat(o.jarak_m) }} dari outlet ini</span>
                    </div>
                    <p class="tanda-item-meta mb-0 mt-1">
                        <template v-if="o.jumlah_tanda > 1">{{ o.jumlah_tanda }} tanda · </template>Ditandai {{ tanggalSingkat(o.ditandai_pada) }}
                    </p>
                </li>
            </ul>
            <p v-else class="tanda-kosong mt-2">Tidak ada outlet bertanda lain di sekitar.</p>
        </section>
    </div>
</template>
