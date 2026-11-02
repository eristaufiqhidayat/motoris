<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import DatePicker from '@/Components/ui/atoms/DatePicker.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import Spinner from '@/Components/ui/atoms/Spinner.vue';
import StatLabel from '@/Components/ui/atoms/StatLabel.vue';
import Alert from '@/Components/ui/molecules/Alert.vue';
import LineChart from '@/Components/ui/molecules/LineChart.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import Combobox from '@/Components/ui/molecules/Combobox.vue';
import Modal from '@/Components/ui/molecules/Modal.vue';
import StatRow from '@/Components/ui/molecules/StatRow.vue';
import Tabs from '@/Components/ui/molecules/Tabs.vue';
import DataTable from '@/Components/ui/organisms/DataTable.vue';
import { angka, desimal, rupiah, tanggalSingkat, tanggalWaktuPendek } from '@/Support/format.js';
import { persenTarget } from '@/Support/pusatKendali.js';

// Isi modal Detail Motoris di Lacak Motoris (spec 060): profil dan historis transaksi pada periode yang dipilih,
// untuk analisa kinerja oleh koordinator. Data dari GET /tracking_sales/{id}/detail (DetailMotorisController);
// tanpa periode, server memakai awal bulan berjalan s.d. hari ini dan mengembalikan periode itu.
const props = defineProps({
    salesId: { type: Number, required: true },
});

const KOLOM_HARIAN = { tanggal: 'Tanggal', masuk: 'Jam Masuk', pulang: 'Jam Pulang', kunjungan: 'Call', ec: 'EC', ipt: 'IPT', transaksi: 'Transaksi', penjualan: 'Penjualan' };
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
// Daftar motoris untuk pemilih "ganti motoris"; bila gagal dimuat, pemilih hanya memuat motoris yang sedang dibuka.
const pilihan = ref([]);
const emit = defineEmits(['ganti']);
const opsiMotoris = computed(() => (pilihan.value.length
    ? pilihan.value
    : (data.value ? [{ value: String(data.value.profil.id), label: data.value.profil.nama }] : [])));

async function muatPilihan() {
    try {
        const respon = await fetch(`/tracking_sales/pilihan-motoris?id=${props.salesId}`, { headers: { Accept: 'application/json' } });
        pilihan.value = respon.ok ? ((await respon.json()).motoris ?? []) : [];
    } catch {
        pilihan.value = [];
    }
}

// Pilih motoris lain: induk memperbarui salesId (dan judul modal); periode dan tab yang sedang dipakai tetap.
function gantiMotoris(nilai) {
    const dipilih = opsiMotoris.value.find((o) => String(o.value) === String(nilai));

    if (dipilih && Number(nilai) !== props.salesId) {
        emit('ganti', { id: Number(nilai), nama: dipilih.label.split(' · ')[0] });
    }
}

watch(() => props.salesId, () => {
    tanggalTerpilih.value = null;
    ambil();
});

const TAB = computed(() => [
    { key: 'harian', label: 'Harian' },
    { key: 'produk', label: 'Produk Terlaris' },
    { key: 'tanda', label: `Ditandai (${(data.value?.tanda_motoris.filter((t) => !t.dicabut).length ?? 0) + (data.value?.tanda_outlet.length ?? 0)})` },
]);

// Persen realisasi terhadap target (null bila target tidak ada) dan warna badge di tabel: belum tercapai = kuning,
// tepat 100% = hijau, melebihi target = biru muda (info).
const persen = (nilai, target) => persenTarget(nilai, target);
const warnaPersen = (p) => (p > 100 ? 'info' : (p === 100 ? 'success' : 'warning'));

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

// Grafik garis Call dan Effective Call per tanggal aktif.
const grafik = computed(() => {
    const harian = data.value?.harian ?? [];

    return {
        labels: harian.map((h) => `${h.tanggal.slice(8, 10)}/${h.tanggal.slice(5, 7)}`),
        series: [
            { key: 'call', label: 'Call', warna: 'biru', values: harian.map((h) => h.kunjungan) },
            { key: 'ec', label: 'Effective Call', warna: 'jingga', values: harian.map((h) => h.ec) },
        ],
    };
});

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

onMounted(() => {
    ambil();
    muatPilihan();
});
</script>

<template>
    <div class="flex flex-col gap-3 pb-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-gray-600">Motoris</span>
            <Combobox
                :model-value="String(salesId)"
                :options="opsiMotoris"
                :clearable="false"
                placeholder="Cari motoris..."
                aria-label="Pilih motoris"
                class="w-64"
                @update:model-value="gantiMotoris"
            />
            <span class="ms-2 text-sm text-gray-600">Periode</span>
            <DatePicker v-model="dari" :max="sampai || null" aria-label="Tanggal dari" class="w-36" />
            <span class="text-gray-400">–</span>
            <DatePicker v-model="sampai" :min="dari || null" aria-label="Tanggal sampai" class="w-36" />
            <Button :loading="memuat" :disabled="!berubah" @click="ambil"><Icon name="search" class="me-1 w-3.5 h-3.5" />Tampilkan</Button>
        </div>

        <Alert v-if="galat" variant="warning">{{ galat }}</Alert>

        <div v-if="data" class="grid grid-cols-1 gap-3 lg:grid-cols-[18rem_minmax(0,1fr)]" :class="{ 'opacity-60': memuat }">
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
                        <div v-if="persen(data.ringkasan.kunjungan, data.ringkasan.target_call) !== null" class="kpi-baris-bar">
                            <div class="kpi-bar" role="progressbar" :aria-valuenow="Math.min(persen(data.ringkasan.kunjungan, data.ringkasan.target_call), 100)" aria-valuemin="0" aria-valuemax="100" aria-label="Realisasi Call terhadap target">
                                <div class="kpi-bar-isi" :class="{ 'kpi-bar-isi-capai': persen(data.ringkasan.kunjungan, data.ringkasan.target_call) >= 100 }" :style="{ width: `${Math.min(persen(data.ringkasan.kunjungan, data.ringkasan.target_call), 100)}%` }"></div>
                            </div>
                        </div>
                    </div>
                    <div class="kpi-item" data-kpi="ec">
                        <dt class="kpi-label">Effective Call</dt>
                        <dd class="kpi-nilai">
                            {{ angka(data.ringkasan.ec) }}<span class="kpi-catatan">/ {{ angka(data.ringkasan.target_ec) }}</span>
                            <span v-if="persen(data.ringkasan.ec, data.ringkasan.target_ec) !== null" class="kpi-persen" :class="{ 'kpi-persen-capai': persen(data.ringkasan.ec, data.ringkasan.target_ec) >= 100 }">{{ persen(data.ringkasan.ec, data.ringkasan.target_ec) }}%</span>
                        </dd>
                        <div v-if="persen(data.ringkasan.ec, data.ringkasan.target_ec) !== null" class="kpi-baris-bar">
                            <div class="kpi-bar" role="progressbar" :aria-valuenow="Math.min(persen(data.ringkasan.ec, data.ringkasan.target_ec), 100)" aria-valuemin="0" aria-valuemax="100" aria-label="Realisasi Effective Call terhadap target">
                                <div class="kpi-bar-isi" :class="{ 'kpi-bar-isi-capai': persen(data.ringkasan.ec, data.ringkasan.target_ec) >= 100 }" :style="{ width: `${Math.min(persen(data.ringkasan.ec, data.ringkasan.target_ec), 100)}%` }"></div>
                            </div>
                        </div>
                    </div>
                    <div class="kpi-item" data-kpi="ipt">
                        <dt class="kpi-label">IPT (SKU/transaksi)</dt>
                        <dd class="kpi-nilai">
                            {{ desimal(data.ringkasan.ipt) }}<span class="kpi-catatan">/ {{ desimal(data.ringkasan.target_ipt) }}</span>
                            <span v-if="persen(data.ringkasan.ipt, data.ringkasan.target_ipt) !== null" class="kpi-persen" :class="{ 'kpi-persen-capai': persen(data.ringkasan.ipt, data.ringkasan.target_ipt) >= 100 }">{{ persen(data.ringkasan.ipt, data.ringkasan.target_ipt) }}%</span>
                        </dd>
                        <div v-if="persen(data.ringkasan.ipt, data.ringkasan.target_ipt) !== null" class="kpi-baris-bar">
                            <div class="kpi-bar" role="progressbar" :aria-valuenow="Math.min(persen(data.ringkasan.ipt, data.ringkasan.target_ipt), 100)" aria-valuemin="0" aria-valuemax="100" aria-label="Realisasi IPT terhadap target">
                                <div class="kpi-bar-isi" :class="{ 'kpi-bar-isi-capai': persen(data.ringkasan.ipt, data.ringkasan.target_ipt) >= 100 }" :style="{ width: `${Math.min(persen(data.ringkasan.ipt, data.ringkasan.target_ipt), 100)}%` }"></div>
                            </div>
                        </div>
                    </div>
                    <div class="kpi-item" data-kpi="rata">
                        <dt class="kpi-label">Rata-rata / hari hadir</dt>
                        <dd class="kpi-nilai">{{ rupiah(data.ringkasan.rata_penjualan) }}</dd>
                    </div>
                </dl>

                <Tabs v-model="tab" :tabs="TAB" rapat />

                <template v-if="tab === 'harian'">
                    <Card v-if="grafik.labels.length">
                        <LineChart :labels="grafik.labels" :series="grafik.series" :height="160" aria-label="Call dan Effective Call per hari" />
                    </Card>
                    <DataTable :columns="KOLOM_HARIAN" :rows="data.harian" :total="data.harian.length" baris-bisa-diklik :baris-aktif="(row) => row.tanggal === tanggalTerpilih" empty-text="Belum ada aktivitas pada periode ini." @klik-baris="bukaTransaksi">
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
                        <template #cell-ipt="{ row }">
                            <template v-if="row.ipt > 0">
                                {{ desimal(row.ipt) }}
                                <Badge v-if="persen(row.ipt, data.target.ipt) !== null" :variant="warnaPersen(persen(row.ipt, data.target.ipt))" :title="`Target IPT ${desimal(data.target.ipt)} SKU per transaksi`">{{ persen(row.ipt, data.target.ipt) }}%</Badge>
                            </template>
                            <template v-else>-</template>
                        </template>
                        <template #cell-penjualan="{ row }">{{ rupiah(row.penjualan) }}</template>
                    </DataTable>
                    <p class="m-0 text-xs text-gray-500">
                        Persentase dibanding target depo per hari: {{ data.target.call }} call, {{ data.target.ec }} EC, dan IPT {{ desimal(data.target.ipt) }} SKU per transaksi.
                        Klik satu tanggal untuk melihat transaksinya dalam dialog.
                    </p>
                </template>

                <template v-else-if="tab === 'tanda'">
                    <DataTable
                        title="Tanda motoris pada periode ini"
                        :columns="KOLOM_TANDA_MOTORIS"
                        :rows="data.tanda_motoris"
                        :total="data.tanda_motoris.length"
                       
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
                    <div v-else-if="memuatTransaksi" class="flex items-center justify-center gap-2 py-12 text-sm text-gray-500" role="status" aria-live="polite">
                        <Spinner size="md" />Memuat transaksi...
                    </div>
                    <DataTable
                        v-else
                        :columns="KOLOM_TRANSAKSI"
                        :rows="transaksiHari"
                        :total="transaksiHari.length"
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
