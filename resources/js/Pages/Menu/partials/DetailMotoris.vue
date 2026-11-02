<script setup>
import { computed, onMounted, ref } from 'vue';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import DatePicker from '@/Components/ui/atoms/DatePicker.vue';
import Skeleton from '@/Components/ui/atoms/Skeleton.vue';
import StatLabel from '@/Components/ui/atoms/StatLabel.vue';
import Alert from '@/Components/ui/molecules/Alert.vue';
import BarChart from '@/Components/ui/molecules/BarChart.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import StatRow from '@/Components/ui/molecules/StatRow.vue';
import Tabs from '@/Components/ui/molecules/Tabs.vue';
import DataTable from '@/Components/ui/organisms/DataTable.vue';
import { angka, rupiah, tanggalSingkat, tanggalWaktuPendek } from '@/Support/format.js';

// Isi modal Detail Motoris di Lacak Motoris (spec 060): profil dan historis transaksi pada periode yang dipilih,
// untuk analisa kinerja oleh koordinator. Data dari GET /tracking_sales/{id}/detail (DetailMotorisController);
// tanpa periode, server memakai awal bulan berjalan s.d. hari ini dan mengembalikan periode itu.
const props = defineProps({
    salesId: { type: Number, required: true },
});

const TAB = [
    { key: 'harian', label: 'Harian' },
    { key: 'transaksi', label: 'Transaksi' },
    { key: 'produk', label: 'Produk Terlaris' },
];
const KOLOM_HARIAN = { tanggal: 'Tanggal', masuk: 'Jam Masuk', kunjungan: 'Kunjungan', ec: 'EC', transaksi: 'Transaksi', penjualan: 'Penjualan' };
const KOLOM_TRANSAKSI = { kode: 'Kode', waktu: 'Waktu', outlet: 'Outlet', sku: 'SKU', qty: 'Qty', diskon: 'Diskon', total: 'Total', status: 'Status' };
const KOLOM_PRODUK = { nama: 'Produk', qty: 'Qty', nilai: 'Nilai' };

const memuat = ref(true);
const galat = ref(null);
const data = ref(null);
const dari = ref('');
const sampai = ref('');
const tab = ref('harian');

const berubah = computed(() => data.value && (dari.value !== data.value.periode.dari || sampai.value !== data.value.periode.sampai));
const grafik = computed(() => (data.value?.harian ?? []).map((h) => ({ label: `${h.tanggal.slice(8, 10)}/${h.tanggal.slice(5, 7)}`, value: h.penjualan })));

async function ambil() {
    memuat.value = true;
    galat.value = null;

    try {
        const query = new URLSearchParams(dari.value && sampai.value ? { dari: dari.value, sampai: sampai.value } : {});
        const respon = await fetch(`/tracking_sales/${props.salesId}/detail${query.size ? `?${query}` : ''}`, { headers: { Accept: 'application/json' } });
        const hasil = await respon.json().catch(() => ({}));

        if (!respon.ok) {
            throw new Error(respon.status === 422 ? Object.values(hasil.errors ?? {})[0]?.[0] : null);
        }

        data.value = hasil;
        dari.value = hasil.periode.dari;
        sampai.value = hasil.periode.sampai;
    } catch (e) {
        galat.value = e.message || 'Detail motoris belum bisa dimuat. Coba lagi.';
    } finally {
        memuat.value = false;
    }
}

onMounted(ambil);
</script>

<template>
    <div class="flex flex-col gap-3 pb-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-gray-600">Periode</span>
            <DatePicker v-model="dari" :max="sampai || null" aria-label="Tanggal dari" class="w-36" />
            <span class="text-gray-400">–</span>
            <DatePicker v-model="sampai" :min="dari || null" aria-label="Tanggal sampai" class="w-36" />
            <Button :disabled="memuat || !berubah" @click="ambil">Tampilkan</Button>
        </div>

        <Alert v-if="galat" variant="warning">{{ galat }}</Alert>

        <div v-if="!data && memuat" class="flex flex-col gap-2" aria-label="Memuat detail motoris">
            <Skeleton class="h-16" />
            <Skeleton class="h-64" />
        </div>

        <div v-else-if="data" class="grid grid-cols-1 gap-3 lg:grid-cols-[18rem_minmax(0,1fr)]" :class="{ 'opacity-60': memuat }">
            <Card>
                <div class="flex items-center gap-3">
                    <img v-if="data.profil.foto" :src="data.profil.foto" alt="" class="h-14 w-14 rounded-full object-cover" />
                    <div>
                        <div class="font-semibold">{{ data.profil.nama }}</div>
                        <Badge :variant="data.profil.status === 'Aktif' ? 'success' : 'secondary'">{{ data.profil.status }}</Badge>
                    </div>
                </div>
                <StatLabel>Profil</StatLabel>
                <StatRow label="No HP">{{ data.profil.no_hp || '-' }}</StatRow>
                <StatRow label="Depo">{{ data.profil.depo || '-' }}</StatRow>
                <StatRow label="Koordinator">{{ data.profil.koordinator || '-' }}</StatRow>
                <StatRow label="Area">{{ data.profil.area.length ? data.profil.area.join(', ') : '-' }}</StatRow>
                <StatRow label="Tanggal masuk">{{ data.profil.tanggal_masuk ? tanggalSingkat(data.profil.tanggal_masuk) : '-' }}</StatRow>
                <StatRow label="Masa kerja">{{ data.profil.masa_kerja || '-' }}</StatRow>
            </Card>

            <div class="flex min-w-0 flex-col gap-3">
                <dl class="kpi-hari" aria-label="Ringkasan periode">
                    <div class="kpi-item" data-kpi="hadir">
                        <dt class="kpi-label">Hadir</dt>
                        <dd class="kpi-nilai">{{ data.ringkasan.hadir }}<span class="kpi-catatan">/ {{ data.ringkasan.hari_kerja }} HK</span></dd>
                    </div>
                    <div class="kpi-item" data-kpi="terlambat">
                        <dt class="kpi-label">Terlambat</dt>
                        <dd class="kpi-nilai" :class="{ 'kpi-nilai-waspada': data.ringkasan.terlambat > 0 }">{{ data.ringkasan.terlambat }}</dd>
                    </div>
                    <div class="kpi-item" data-kpi="kunjungan">
                        <dt class="kpi-label">Call (Kunjungan)</dt>
                        <dd class="kpi-nilai">{{ angka(data.ringkasan.kunjungan) }}</dd>
                    </div>
                    <div class="kpi-item" data-kpi="ec">
                        <dt class="kpi-label">Effective Call</dt>
                        <dd class="kpi-nilai">{{ angka(data.ringkasan.ec) }}<span class="kpi-catatan">{{ angka(data.ringkasan.rasio_ec) }}%</span></dd>
                    </div>
                    <div class="kpi-item" data-kpi="penjualan">
                        <dt class="kpi-label">Penjualan</dt>
                        <dd class="kpi-nilai">{{ rupiah(data.ringkasan.penjualan) }}<span class="kpi-catatan">{{ angka(data.ringkasan.transaksi) }} trx · {{ angka(data.ringkasan.qty) }} qty</span></dd>
                    </div>
                    <div class="kpi-item" data-kpi="rata">
                        <dt class="kpi-label">Rata-rata / hari hadir</dt>
                        <dd class="kpi-nilai">{{ rupiah(data.ringkasan.rata_penjualan) }}</dd>
                    </div>
                </dl>

                <Tabs v-model="tab" :tabs="TAB" rapat />

                <template v-if="tab === 'harian'">
                    <Card v-if="grafik.length" body-class="card-body overflow-x-auto">
                        <BarChart :items="grafik" :height="160" />
                    </Card>
                    <DataTable :columns="KOLOM_HARIAN" :rows="data.harian" :total="data.harian.length" :memuat="memuat" empty-text="Belum ada aktivitas pada periode ini.">
                        <template #cell-tanggal="{ row }">{{ tanggalSingkat(row.tanggal) }}</template>
                        <template #cell-masuk="{ row }">
                            <Badge v-if="row.masuk" :variant="row.terlambat ? 'warning' : 'light'" :title="row.terlambat ? 'Terlambat' : null">{{ row.masuk }}</Badge>
                            <template v-else>-</template>
                        </template>
                        <template #cell-penjualan="{ row }">{{ rupiah(row.penjualan) }}</template>
                    </DataTable>
                </template>

                <template v-else-if="tab === 'transaksi'">
                    <Alert v-if="data.transaksi_total > data.transaksi.length" variant="info">
                        Menampilkan {{ angka(data.transaksi.length) }} transaksi terbaru dari {{ angka(data.transaksi_total) }} transaksi.
                    </Alert>
                    <DataTable
                        :columns="KOLOM_TRANSAKSI"
                        :rows="data.transaksi"
                        :total="data.transaksi_total"
                        :memuat="memuat"
                        :badge-columns="['status']"
                        :badge-variants="{ status: { Sah: 'success', Batal: 'danger' } }"
                        empty-text="Belum ada transaksi pada periode ini."
                    >
                        <template #cell-waktu="{ row }">
                            <template v-if="tanggalWaktuPendek(row.waktu)">
                                {{ tanggalWaktuPendek(row.waktu).tanggal }}
                                <Badge variant="light">{{ tanggalWaktuPendek(row.waktu).jam }}</Badge>
                            </template>
                            <template v-else>{{ row.waktu }}</template>
                        </template>
                        <template #cell-outlet="{ row }">{{ row.outlet || '-' }}</template>
                        <template #cell-qty="{ row }">{{ angka(row.qty) }}</template>
                        <template #cell-diskon="{ row }">{{ rupiah(row.diskon) }}</template>
                        <template #cell-total="{ row }">{{ rupiah(row.total) }}</template>
                    </DataTable>
                </template>

                <DataTable
                    v-else
                    :columns="KOLOM_PRODUK"
                    :rows="data.produk"
                    :total="data.produk.length"
                    :memuat="memuat"
                    empty-text="Belum ada produk terjual pada periode ini."
                >
                    <template #cell-qty="{ row }">{{ angka(row.qty) }}</template>
                    <template #cell-nilai="{ row }">{{ rupiah(row.nilai) }}</template>
                </DataTable>
            </div>
        </div>
    </div>
</template>
