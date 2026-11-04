<script setup>
import { ref } from 'vue';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import Skeleton from '@/Components/ui/atoms/Skeleton.vue';
import { angka, angkaRingkas, rupiah, tanggalPendek, tanggalWaktuPendek } from '@/Support/format.js';

// Satu baris outlet di daftar "Outlet di titik ini" (PetaOutletBertanda): nama + ringkasan transaksi di kiri, pendaftar
// dan chevron di kanan. Mengklik baris memuat transaksi outlet itu beserta item produknya
// (TandaMotorisController::outletTransaksi). Dimuat saat dibuka pertama kali saja, karena satu titik bisa berisi
// puluhan outlet.
const props = defineProps({
    url: { type: String, required: true },
    outletId: { type: Number, required: true },
    nama: { type: String, required: true },
    // { transaksi, hari, total } atau null bila outlet belum bertransaksi.
    ringkasan: { type: Object, default: null },
    utama: { type: Boolean, default: false },
    // Nama pendaftar outlet (mk_outlet.dibuat_oleh); kosong untuk data lama tanpa pendaftar.
    pendaftar: { type: String, default: null },
    // Waktu outlet didaftarkan: "Y-m-d H:i" (dari HP) atau teks Marketama apa adanya; kosong bila tidak tercatat.
    didaftarkan: { type: String, default: null },
});

// Teks Marketama lama tidak selalu bisa diurai, jadi ditampilkan apa adanya bila tanggalWaktuPendek gagal.
function waktuDaftar(nilai) {
    const waktu = nilai ? tanggalWaktuPendek(nilai) : null;

    return waktu ? `${waktu.tanggal} · ${waktu.jam}` : nilai;
}

const terbuka = ref(false);
const memuat = ref(false);
const pesan = ref(null);
const data = ref(null);

async function muat() {
    memuat.value = true;
    pesan.value = null;

    try {
        const respon = await fetch(`${props.url}?${new URLSearchParams({ outlet_id: props.outletId })}`, { headers: { Accept: 'application/json' } });
        if (!respon.ok) throw new Error(`HTTP ${respon.status}`);
        data.value = await respon.json();
    } catch {
        pesan.value = 'Gagal memuat transaksi outlet ini.';
    } finally {
        memuat.value = false;
    }
}

function alihkan() {
    terbuka.value = !terbuka.value;
    if (terbuka.value && !data.value && !memuat.value) muat();
}
</script>

<template>
    <li class="list-group-item transaksi-outlet-item">
        <!-- Seluruh baris dapat diklik (membuka transaksi) bila outlet punya transaksi; chevron sebagai penanda. -->
        <component
            :is="ringkasan ? 'button' : 'div'"
            :type="ringkasan ? 'button' : undefined"
            class="transaksi-outlet-kepala"
            :class="{ 'transaksi-outlet-klik': ringkasan }"
            :aria-expanded="ringkasan ? terbuka : undefined"
            @click="ringkasan && alihkan()"
        >
            <div class="transaksi-outlet-info">
                <span class="transaksi-outlet-nama" :class="utama ? 'font-semibold' : 'font-medium'">{{ nama }}</span>
                <span v-if="ringkasan" class="tanda-item-meta">
                    {{ angka(ringkasan.transaksi) }} transaksi · {{ angka(ringkasan.hari) }} hari · {{ rupiah(ringkasan.total) }}
                </span>
                <Badge v-else class="self-start">Belum bertransaksi</Badge>
            </div>
            <div class="transaksi-outlet-samping">
                <div class="transaksi-outlet-pendaftar">
                    <span class="tanda-item-meta">Didaftarkan oleh {{ pendaftar || 'tidak diketahui' }}</span>
                    <span v-if="didaftarkan" class="tanda-item-meta">{{ waktuDaftar(didaftarkan) }}</span>
                </div>
                <Icon v-if="ringkasan" :name="terbuka ? 'chevron-down' : 'chevron-right'" class="transaksi-outlet-chevron" />
            </div>
        </component>

        <div v-if="terbuka" class="transaksi-outlet-isi">
            <Skeleton v-if="memuat" class="h-16 w-full" />
            <p v-else-if="pesan" class="tanda-kosong text-red-600">{{ pesan }}</p>
            <template v-else-if="data">
                <p v-if="data.jumlah > data.transaksi.length" class="tanda-item-meta mb-1">
                    Menampilkan {{ data.transaksi.length }} transaksi terbaru dari {{ angka(data.jumlah) }}.
                </p>
                <p v-if="!data.transaksi.length" class="tanda-kosong">Tidak ada transaksi pada cakupan akun ini.</p>
                <article v-for="t in data.transaksi" :key="t.id" class="transaksi-outlet-kartu">
                    <div class="tanda-item-kepala">
                        <span class="tanda-item-judul">{{ tanggalPendek(t.tanggal) }} {{ t.jam }}</span>
                        <Badge v-if="t.effective_call" variant="success">EC</Badge>
                        <span class="tanda-item-meta">{{ t.motoris }} · {{ t.kode }}</span>
                        <span class="ml-auto font-semibold">{{ rupiah(t.total) }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr><th>Produk</th><th>Qty</th><th>Harga</th><th>Diskon</th><th>Total</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="(i, n) in t.item" :key="n">
                                    <td>{{ i.produk }}<span v-if="i.sku" class="tanda-item-meta"> · {{ i.sku }}</span></td>
                                    <td>{{ angkaRingkas(i.qty) }}</td>
                                    <td>{{ rupiah(i.harga) }}</td>
                                    <td>{{ rupiah(i.diskon) }}</td>
                                    <td>{{ rupiah(i.total) }}</td>
                                </tr>
                                <tr v-if="!t.item.length"><td colspan="5" class="tanda-item-meta">Tidak ada item tercatat.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </article>
            </template>
        </div>
    </li>
</template>
