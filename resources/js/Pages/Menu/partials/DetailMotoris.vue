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
import Modal from '@/Components/ui/molecules/Modal.vue';
import StatRow from '@/Components/ui/molecules/StatRow.vue';
import Tabs from '@/Components/ui/molecules/Tabs.vue';
import DataTable from '@/Components/ui/organisms/DataTable.vue';
import { angka, rupiah, tanggalSingkat, tanggalWaktuPendek } from '@/Support/format.js';
import { persenTarget } from '@/Support/pusatKendali.js';

// Isi modal Detail Motoris di Lacak Motoris (spec 060): profil dan historis transaksi pada periode yang dipilih,
// untuk analisa kinerja oleh koordinator. Data dari GET /tracking_sales/{id}/detail (DetailMotorisController);
// tanpa periode, server memakai awal bulan berjalan s.d. hari ini dan mengembalikan periode itu.
const props = defineProps({
    salesId: { type: Number, required: true },
});

const KOLOM_HARIAN = { tanggal: 'Tanggal', masuk: 'Jam Masuk', pulang: 'Jam Pulang', kunjungan: 'Kunjungan', ec: 'EC', transaksi: 'Transaksi', penjualan: 'Penjualan' };
const KOLOM_TRANSAKSI = { kode: 'Kode', waktu: 'Waktu', outlet: 'Outlet', sku: 'SKU', qty: 'Qty', diskon: 'Diskon', total: 'Total', status: 'Status' };
const KOLOM_PRODUK = { nama: 'Produk', qty: 'Qty', nilai: 'Nilai' };
const KOLOM_TANDA_MOTORIS = { tanggal: 'Tanggal', kategori_label: 'Kategori', catatan: 'Catatan', oleh: 'Ditandai Oleh', waktu: 'Ditandai Pada', dicabut: 'Status' };
const KOLOM_TANDA_OUTLET = { outlet: 'Outlet', kategori_label: 'Kategori', catatan: 'Catatan', oleh: 'Ditandai Oleh', waktu: 'Ditandai Pada' };

const memuat = ref(true);
const galat = ref(null);
const data = ref(null);
const dari = ref('');
const sampai = ref('');
const tab = ref('harian');

const berubah = computed(() => data.value && (dari.value !== data.value.periode.dari || sampai.value !== data.value.periode.sampai));
const TAB = computed(() => [
    { key: 'harian', label: 'Harian' },
    { key: 'produk', label: 'Produk Terlaris' },
    { key: 'tanda', label: `Ditandai (${(data.value?.tanda_motoris.filter((t) => !t.dicabut).length ?? 0) + (data.value?.tanda_outlet.length ?? 0)})` },
]);

// Persen realisasi terhadap target (null bila target tidak ada) dan warnanya: tercapai = hijau, belum = kuning.
const persen = (nilai, target) => persenTarget(nilai, target);
const warnaPersen = (p) => (p >= 100 ? 'success' : 'warning');

// Transaksi satu tanggal, dimuat hanya saat baris tabel Harian diklik (tidak ikut payload utama) dan tampil di modal dialog.
const tanggalTerpilih = ref(null);
const memuatTransaksi = ref(false);
const galatTransaksi = ref(null);
const transaksiHari = ref([]);

async function bukaTransaksi(row) {
    tanggalTerpilih.value = row.tanggal;
    memuatTransaksi.value = true;
    galatTransaksi.value = null;
    transaksiHari.value = [];

    try {
        const respon = await fetch(`/tracking_sales/${props.salesId}/detail/transaksi?tanggal=${row.tanggal}`, { headers: { Accept: 'application/json' } });

        if (!respon.ok) {
            throw new Error(String(respon.status));
        }

        const hasil = await respon.json();

        // Abaikan balasan lambat bila koordinator sudah memilih tanggal lain.
        if (tanggalTerpilih.value === hasil.tanggal) {
            transaksiHari.value = hasil.transaksi;
        }
    } catch {
        galatTransaksi.value = 'Transaksi belum bisa dimuat. Coba lagi.';
    } finally {
        if (tanggalTerpilih.value === row.tanggal) {
            memuatTransaksi.value = false;
        }
    }
}

const grafik = computed(() => (data.value?.harian ?? []).map((h) => ({ label: `${h.tanggal.slice(8, 10)}/${h.tanggal.slice(5, 7)}`, value: h.penjualan })));

async function ambil() {
    memuat.value = true;
    galat.value = null;
    tanggalTerpilih.value = null;

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
                        <dd class="kpi-nilai">
                            {{ angka(data.ringkasan.kunjungan) }}<span class="kpi-catatan">/ {{ angka(data.ringkasan.target_call) }}</span>
                            <span v-if="persen(data.ringkasan.kunjungan, data.ringkasan.target_call) !== null" class="kpi-persen" :class="{ 'kpi-persen-capai': persen(data.ringkasan.kunjungan, data.ringkasan.target_call) >= 100 }">{{ persen(data.ringkasan.kunjungan, data.ringkasan.target_call) }}%</span>
                        </dd>
                    </div>
                    <div class="kpi-item" data-kpi="ec">
                        <dt class="kpi-label">Effective Call</dt>
                        <dd class="kpi-nilai">
                            {{ angka(data.ringkasan.ec) }}<span class="kpi-catatan">/ {{ angka(data.ringkasan.target_ec) }}</span>
                            <span v-if="persen(data.ringkasan.ec, data.ringkasan.target_ec) !== null" class="kpi-persen" :class="{ 'kpi-persen-capai': persen(data.ringkasan.ec, data.ringkasan.target_ec) >= 100 }">{{ persen(data.ringkasan.ec, data.ringkasan.target_ec) }}%</span>
                            <span class="kpi-catatan" title="Effective call dibanding kunjungan">{{ angka(data.ringkasan.rasio_ec) }}% dari call</span>
                        </dd>
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
                    <DataTable :columns="KOLOM_HARIAN" :rows="data.harian" :total="data.harian.length" :memuat="memuat" baris-bisa-diklik :baris-aktif="(row) => row.tanggal === tanggalTerpilih" empty-text="Belum ada aktivitas pada periode ini." @klik-baris="bukaTransaksi">
                        <template #cell-tanggal="{ row }">{{ tanggalSingkat(row.tanggal) }}</template>
                        <template #cell-masuk="{ row }">
                            <Badge v-if="row.masuk" :variant="row.terlambat ? 'warning' : 'light'" :title="row.terlambat ? 'Terlambat' : null">{{ row.masuk }}</Badge>
                            <template v-else>-</template>
                        </template>
                        <template #cell-pulang="{ row }">
                            <Badge v-if="row.pulang" variant="light">{{ row.pulang }}</Badge>
                            <template v-else>-</template>
                        </template>
                        <template #cell-kunjungan="{ row }">
                            {{ angka(row.kunjungan) }}
                            <Badge v-if="persen(row.kunjungan, data.target.call) !== null" :variant="warnaPersen(persen(row.kunjungan, data.target.call))" :title="`Target ${data.target.call} call per hari`">{{ persen(row.kunjungan, data.target.call) }}%</Badge>
                        </template>
                        <template #cell-ec="{ row }">
                            {{ angka(row.ec) }}
                            <Badge v-if="persen(row.ec, data.target.ec) !== null" :variant="warnaPersen(persen(row.ec, data.target.ec))" :title="`Target ${data.target.ec} EC per hari`">{{ persen(row.ec, data.target.ec) }}%</Badge>
                        </template>
                        <template #cell-penjualan="{ row }">{{ rupiah(row.penjualan) }}</template>
                    </DataTable>
                    <p class="m-0 text-xs text-gray-500">
                        Persentase dibanding target depo per hari: {{ data.target.call }} call dan {{ data.target.ec }} EC.
                        Klik satu tanggal untuk melihat transaksinya dalam dialog.
                    </p>
                </template>

                <template v-else-if="tab === 'tanda'">
                    <DataTable
                        title="Tanda motoris pada periode ini"
                        :columns="KOLOM_TANDA_MOTORIS"
                        :rows="data.tanda_motoris"
                        :total="data.tanda_motoris.length"
                        :memuat="memuat"
                        empty-text="Tidak ada tanda motoris pada periode ini."
                    >
                        <template #cell-tanggal="{ row }">{{ tanggalSingkat(row.tanggal) }}</template>
                        <template #cell-catatan="{ row }">{{ row.catatan || '-' }}</template>
                        <template #cell-dicabut="{ row }"><Badge :variant="row.dicabut ? 'secondary' : 'danger'">{{ row.dicabut ? 'Dicabut' : 'Aktif' }}</Badge></template>
                    </DataTable>
                    <DataTable
                        title="Tanda outlet yang didaftarkan motoris ini"
                        :columns="KOLOM_TANDA_OUTLET"
                        :rows="data.tanda_outlet"
                        :total="data.tanda_outlet.length"
                        :memuat="memuat"
                        empty-text="Tidak ada outlet yang didaftarkan motoris ini sedang ditandai."
                    >
                        <template #cell-catatan="{ row }">{{ row.catatan || '-' }}</template>
                    </DataTable>
                    <p class="m-0 text-xs text-gray-500">Tanda outlet: hanya yang aktif dalam jendela waktu yang sama dengan lencana Ditandai di Lacak Motoris, tidak mengikuti periode.</p>
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

        <!-- Dialog transaksi satu tanggal, dibuka dari klik baris tabel Harian; di-teleport ke body agar menumpuk di atas modal Detail Motoris. -->
        <Teleport to="body">
            <Modal :show="tanggalTerpilih !== null" :title="`Transaksi ${tanggalSingkat(tanggalTerpilih ?? '')}`" size="xl" @close="tanggalTerpilih = null">
                <div data-panel="transaksi-hari">
                    <Alert v-if="galatTransaksi" variant="warning">{{ galatTransaksi }}</Alert>
                    <DataTable
                        v-else
                        :columns="KOLOM_TRANSAKSI"
                        :rows="transaksiHari"
                        :total="transaksiHari.length"
                        :memuat="memuatTransaksi"
                        :badge-columns="['status']"
                        :badge-variants="{ status: { Sah: 'success', Batal: 'danger' } }"
                        empty-text="Tidak ada transaksi pada tanggal ini."
                    >
                        <template #cell-waktu="{ row }">
                            <template v-if="tanggalWaktuPendek(row.waktu)">
                                <Badge variant="light">{{ tanggalWaktuPendek(row.waktu).jam }}</Badge>
                            </template>
                            <template v-else>{{ row.waktu }}</template>
                        </template>
                        <template #cell-outlet="{ row }">{{ row.outlet || '-' }}</template>
                        <template #cell-qty="{ row }">{{ angka(row.qty) }}</template>
                        <template #cell-diskon="{ row }">{{ rupiah(row.diskon) }}</template>
                        <template #cell-total="{ row }">{{ rupiah(row.total) }}</template>
                    </DataTable>
                </div>
            </Modal>
        </Teleport>
    </div>
</template>
