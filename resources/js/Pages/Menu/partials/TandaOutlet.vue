<script setup>
import { onMounted, ref, watch } from 'vue';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import Skeleton from '@/Components/ui/atoms/Skeleton.vue';
import { tanggalWaktuPendek } from '@/Support/format.js';
import PeriksaTanda from './PeriksaTanda.vue';

// Tanda outlet (TandaOutletController) satu outlet: diduga outlet fiktif, dibuat sistem (spec 062) per titik lokasi.
// Koordinator tidak lagi menandai manual; ia memeriksa tanda Perlu dicek (Terbukti / Wajar + catatan), dan hasilnya
// berlaku untuk semua outlet di titik yang sama. Tanda melekat ke OUTLET (bukan per kunjungan/tanggal); dipasang di
// RuteHarianPeta.vue per baris kunjungan dan di Menu/Show.vue untuk menu All Outlet.
const props = defineProps({
    url: { type: String, required: true },
    outletId: { type: Number, required: true },
});

// 'berubah': dikirim hanya setelah pemeriksaan berhasil (bukan saat muat awal), supaya komponen induk tahu kapan perlu
// menyegarkan tampilannya sendiri (mis. muat ulang tabel outlet_all).
const emit = defineEmits(['berubah']);

// Warna status tanda (config marketama.status_tanda).
const WARNA_STATUS = { perlu_dicek: 'warning', terbukti: 'danger', wajar: 'secondary' };

const tanda = ref([]);
// Pendaftar outlet { nama, dibuat_oleh, dibuat_pada } dari master outlet; null sebelum dimuat.
const outlet = ref(null);
const memuat = ref(true);
const pesan = ref(null);
// Id tanda yang form hasil ceknya sedang terbuka; null = tertutup.
const periksaId = ref(null);

// "22 Okt 2026 · 11:23" bila formatnya Y-m-d H:i; selain itu apa adanya dari sumber.
function waktuPendaftaran(nilai) {
    const waktu = tanggalWaktuPendek(nilai);

    return waktu ? `${waktu.tanggal} · ${waktu.jam}` : nilai;
}

async function muat() {
    const outletId = props.outletId;
    memuat.value = true;
    pesan.value = null;

    try {
        const respon = await fetch(`${props.url}?${new URLSearchParams({ outlet_id: outletId })}`, { headers: { Accept: 'application/json' } });
        if (!respon.ok) throw new Error(`HTTP ${respon.status}`);
        const data = await respon.json();

        // Respons lama (outlet sudah diganti) dibuang.
        if (outletId !== props.outletId) return;
        tanda.value = data.tanda;
        outlet.value = data.outlet ?? null;
    } catch {
        pesan.value = 'Gagal memuat tanda outlet.';
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
watch(() => props.outletId, () => {
    periksaId.value = null;
    outlet.value = null;
    tanda.value = [];
    muat();
});
</script>

<template>
    <section class="tanda-motoris" aria-label="Tanda outlet">
        <div class="flex flex-wrap items-center gap-2">
            <h6 class="font-semibold text-sm mb-0">Tanda outlet</h6>
            <Badge v-if="tanda.length" variant="danger">{{ tanda.length }}</Badge>
        </div>

        <!-- Kerangka dua baris (pendaftar outlet dan satu tanda) selama data awal diambil; muat ulang setelah periksa tidak mengosongkan isi. -->
        <div v-if="memuat && !outlet" class="animate-pulse space-y-2" role="status" aria-busy="true" aria-label="Memuat data outlet">
            <Skeleton class="h-3 w-3/4" />
            <Skeleton class="h-12 w-full" />
        </div>

        <p v-if="outlet" class="mb-0 text-xs text-gray-600">
            <span class="font-semibold">Didaftarkan oleh:</span> {{ outlet.dibuat_oleh || 'tidak diketahui' }}<template v-if="outlet.dibuat_pada"> · {{ waktuPendaftaran(outlet.dibuat_pada) }}</template>
        </p>

        <p v-if="pesan" class="mb-0 text-xs text-red-600">{{ pesan }}</p>

        <ul v-if="tanda.length" class="tanda-motoris-daftar">
            <li v-for="item in tanda" :key="item.id" class="tanda-motoris-item">
                <div class="tanda-item-kepala">
                    <Badge variant="danger">{{ item.kategori_label }}</Badge>
                    <Badge :variant="WARNA_STATUS[item.status] ?? 'secondary'">{{ item.status_label }}</Badge>
                    <span class="tanda-item-meta">{{ item.oleh }} · {{ item.waktu }}</span>
                    <div v-if="item.bisa_periksa && periksaId !== item.id" class="tanda-item-aksi">
                        <button type="button" class="tautan-depo text-xs" @click="periksaId = item.id"><Icon name="badge-check" class="me-1" />Periksa</button>
                    </div>
                </div>
                <p v-if="item.catatan" class="tanda-item-catatan">{{ item.catatan }}</p>
                <p v-if="item.hasil_catatan" class="tanda-item-catatan"><span class="font-semibold">Hasil cek ({{ item.dicek_oleh }} · {{ item.dicek_waktu }}):</span> {{ item.hasil_catatan }}</p>
                <PeriksaTanda
                    v-if="periksaId === item.id"
                    class="mt-1"
                    :url="`${url}/${item.id}/periksa`"
                    :keterangan="item.sekelompok ? `Berlaku juga untuk ${item.sekelompok} outlet lain di titik yang sama.` : null"
                    @selesai="selesaiPeriksa"
                    @batal="periksaId = null"
                />
            </li>
        </ul>
        <p v-else-if="!memuat && !pesan" class="mb-0 text-xs text-gray-500">Belum ada tanda untuk outlet ini.</p>
    </section>
</template>
