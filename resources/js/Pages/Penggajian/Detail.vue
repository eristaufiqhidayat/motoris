<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import Alert from '@/Components/ui/molecules/Alert.vue';
import MenuAksi from '@/Components/ui/molecules/MenuAksi.vue';
import InfoPopover from '@/Components/ui/molecules/InfoPopover.vue';
import Modal from '@/Components/ui/molecules/Modal.vue';
import PageHeader from '@/Components/ui/molecules/PageHeader.vue';
import StatCard from '@/Components/ui/molecules/StatCard.vue';
import DataTable from '@/Components/ui/organisms/DataTable.vue';
import RincianOrang from './partials/RincianOrang.vue';
import { desimal, rupiah, tanggalSingkat } from '@/Support/format.js';

// Detail penggajian, tata letaknya mengikuti Beranda Koordinator: PageHeader dengan info, bagian kartu ringkas
// (StatCard), lalu tabel rincian per orang yang barisnya membuka drawer.
const props = defineProps({
    slug: { type: String, required: true },
    judul: { type: String, required: true },
    penggajian: { type: Object, required: true },
    detail: { type: Array, required: true },
    kekurangan: { type: Array, default: () => [] },
    pemotong: { type: Array, default: () => [] },
    bisaUbah: { type: Boolean, default: false },
    bisaEkspor: { type: Boolean, default: false },
    orangLabel: { type: String, default: 'motoris' },
});

const page = usePage();
const appName = page.props.appName;
const base = `/${props.slug}`;
const urlIni = `${base}/${props.penggajian.id}`;

const WARNA_STATUS = { draf: 'warning', final: 'success', batal: 'secondary' };

const insentif = computed(() => props.penggajian.kategori === 'insentif');
const subtitle = computed(() => {
    const p = props.penggajian;

    return `Periode ${p.periode_label}: ${tanggalSingkat(p.periode_mulai)} – ${tanggalSingkat(p.periode_selesai)}`;
});

// Insentif dihitung dari penjualan, jadi kolom hari kerja, hadir, terlambat, dan izin tidak dipakai.
const KOLOM = computed(() => ({
    nama: 'Nama',
    ...(insentif.value ? {} : { hari_kerja: 'Hari Kerja', hari_hadir: 'Hadir', hari_tidak_hadir: 'Tidak Hadir', hari_terlambat: 'Terlambat', hari_izin: 'Izin' }),
    total_pendapatan: 'Pendapatan',
    total_potongan: 'Potongan',
    total_bersih: 'Bersih',
}));

// Penggajian hanya bisa diubah selagi draf; final dan batal terkunci.
const draf = computed(() => props.penggajian.status === 'draf');
const bolehUbah = computed(() => draf.value && props.bisaUbah);

// Orang yang datanya belum lengkap ditandai di tabelnya (bukan hanya di peringatan).
const kurangPerOrang = computed(() => new Map(props.kekurangan.map((k) => [k.karyawan_id, k.masalah])));

const baris = computed(() => props.detail.map((d) => ({
    ...d,
    masalah: kurangPerOrang.value.get(d.karyawan_id) ?? null,
    total_pendapatan: rupiah(d.total_pendapatan),
    total_potongan: rupiah(d.total_potongan),
    total_bersih: rupiah(d.total_bersih),
})));

const barisTotal = computed(() => ({
    nama: 'Total',
    total_pendapatan: rupiah(props.penggajian.total_pendapatan),
    total_potongan: rupiah(props.penggajian.total_potongan),
    total_bersih: rupiah(props.penggajian.total_bersih),
}));

// Pesan penolakan dari server (mis. finalkan ditolak, penggajian sudah final).
const galat = computed(() => page.props.errors?.penggajian ?? null);

const dipilih = ref(null);
const orang = computed(() => props.detail.find((d) => d.id === dipilih.value?.id) ?? null);

// Konfirmasi Finalkan / Batalkan / Hitung Ulang. aksi = kunci yang sedang dikonfirmasi, null = tertutup.
const aksi = ref(null);
const memproses = ref(false);
const KONFIRMASI = {
    'hitung-ulang': { judul: 'Hitung ulang draf', tombol: 'Hitung ulang', variant: 'primary' },
    finalkan: { judul: 'Finalkan penggajian', tombol: 'Finalkan', variant: 'success' },
    'kembalikan-ke-draf': { judul: 'Kembalikan ke Draf', tombol: 'Kembalikan ke Draf', variant: 'primary' },
    batalkan: { judul: 'Batalkan draf', tombol: 'Batalkan draf', variant: 'danger' },
};

// Daftar orang yang datanya belum lengkap sebagai submenu; tiap item membuka data karyawannya untuk dilengkapi.
const itemKekurangan = computed(() => props.kekurangan.map((k) => ({
    kunci: `kekurangan-${k.karyawan_id}`,
    label: `${k.nama} · ${k.masalah}`,
    href: `/${props.slug.replace('penggajian_', 'karyawan_')}/${k.karyawan_id}/edit`,
})));

// Langkah utama menurut status tampil sebagai tombol sendiri di header: Finalkan saat Draf, Ekspor Excel saat Final.
const bisaFinalkan = computed(() => draf.value && props.bisaUbah);
const bisaEksporLangsung = computed(() => props.penggajian.status === 'final' && props.bisaEkspor);

// Submenu "Aksi" untuk perintah lainnya, urut menurut alur hidup penggajian: hitung ulang, kembalikan ke draf, ekspor, lalu
// (terpisah) batalkan. Perintah tanpa hak disembunyikan; yang belum berlaku di status ini tetap tampil nonaktif beralasan.
const itemAksi = computed(() => [
    ...(props.bisaUbah ? [
        { kunci: 'hitung-ulang', label: 'Hitung Ulang', icon: 'calculator', disabled: !draf.value, alasan: 'Hanya penggajian Draf yang bisa dihitung ulang.' },
        { kunci: 'kembalikan-ke-draf', label: 'Kembalikan ke Draf', icon: 'undo', disabled: props.penggajian.status !== 'final', alasan: 'Hanya penggajian Final yang bisa dikembalikan ke Draf.' },
    ] : []),
    ...(props.bisaEkspor && !bisaEksporLangsung.value ? [
        { kunci: 'ekspor', label: 'Ekspor Excel', icon: 'download', href: `${urlIni}/ekspor`, disabled: props.penggajian.status !== 'final', alasan: 'Ekspor Excel tersedia setelah penggajian difinalkan.' },
    ] : []),
    ...(props.bisaUbah ? [
        { kunci: 'batalkan', label: 'Batalkan Draf', icon: 'x', variant: 'bahaya', pisah: true, disabled: !draf.value, alasan: 'Hanya penggajian Draf yang bisa dibatalkan.' },
    ] : []),
]);

function jalankan() {
    memproses.value = true;
    router.post(`${urlIni}/${aksi.value}`, {}, {
        preserveScroll: true,
        onFinish: () => { memproses.value = false; aksi.value = null; },
    });
}
</script>

<template>
    <Head :title="`${judul} · ${appName}`" />
    <AppLayout :breadcrumb-slug="slug">
        <PageHeader :title="`${judul} · ${penggajian.depo}${insentif ? ' · Insentif' : ''}`" :subtitle="subtitle">
            <template #info>
                <Badge :variant="WARNA_STATUS[penggajian.status] ?? 'secondary'" class="ms-2 align-middle">{{ penggajian.status_label }}</Badge>
                <InfoPopover title="Tentang Penggajian" class="ms-1 align-middle">
                    <ul class="list-disc ps-4 space-y-1">
                        <li>Penggajian berstatus <b>Draf</b> bisa diubah: isi hari izin, tambah komponen manual, atau <b>Hitung Ulang</b> setelah data karyawan, absensi, atau penjualan diperbaiki. Komponen manual dan hari izin tetap dipertahankan.</li>
                        <li>Setelah <b>Final</b>, rincian tiap orang tersimpan dan terkunci: perubahan data karyawan atau rumus tidak mengubah angkanya.</li>
                        <li v-if="!insentif"><b>Tingkat kehadiran</b> = total hari hadir ÷ (hari kerja × {{ penggajian.dasar_hadir === 'kuota' ? 'kuota motoris depo bulan ' + penggajian.periode_label : 'jumlah ' + orangLabel }}); angka kecil di bawahnya adalah rata-rata {{ orangLabel }} yang hadir per hari kerja. Kuota diisi di Master Data › Kuota Motoris, sama dengan Motoris Report. Tunjangan BBM &amp; makan dan sewa motor dihitung prorata dari hari hadir ditambah hari izin.</li>
                        <li v-else>Insentif dihitung dari total EC dan poin penjualan bulan itu, dengan aturan yang sama dengan Lap. Insentif Sales.</li>
                        <li>Klik baris untuk melihat rincian komponen dan asal angkanya di panel samping.</li>
                    </ul>
                </InfoPopover>
            </template>
            <template #actions>
                <Button v-if="bisaFinalkan" variant="success" @click="aksi = 'finalkan'"><Icon name="check" class="me-1 w-3.5 h-3.5" />Finalkan</Button>
                <Button v-if="bisaEksporLangsung" tag="a" variant="success" :href="`${urlIni}/ekspor`"><Icon name="download" class="me-1 w-3.5 h-3.5" />Ekspor Excel</Button>
                <MenuAksi :items="itemAksi" @pilih="aksi = $event" />
                <Button :tag="Link" :href="`${base}?tab=${penggajian.kategori}`" variant="outline-secondary"><Icon name="chevron-left" class="me-1 w-3.5 h-3.5" />Kembali</Button>
            </template>
        </PageHeader>

        <Alert v-if="galat" variant="danger" role="alert" class="mb-3">{{ galat }}</Alert>

        <Alert v-if="draf && kekurangan.length" variant="warning" class="mb-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span>
                    <strong>{{ kekurangan.length }} orang datanya belum lengkap</strong>, sehingga penggajian belum bisa difinalkan.
                    Lengkapi di data karyawan, lalu Hitung Ulang.
                </span>
                <MenuAksi :label="`Lihat daftar (${kekurangan.length})`" :items="itemKekurangan" />
            </div>
        </Alert>

        <div class="grid gap-3 2xl:grid-cols-2 mb-4">
            <section aria-labelledby="judul-ringkasan" class="flex flex-col">
                <h6 id="judul-ringkasan" class="judul-bagian">{{ insentif ? 'Ringkasan Insentif' : 'Kehadiran' }}</h6>
                <div class="grid flex-1 gap-3 sm:grid-cols-2">
                    <StatCard label="Tanggal Bayar" icon="calendar-check" nada="info">
                        {{ penggajian.tanggal_bayar ? tanggalSingkat(penggajian.tanggal_bayar) : '-' }}
                        <template #keterangan>{{ insentif ? 'Insentif' : 'Gaji' }} bulan {{ penggajian.periode_label }}</template>
                    </StatCard>
                    <StatCard
                        v-if="penggajian.total_hadir != null"
                        label="Tingkat Kehadiran"
                        icon="user-check"
                        nada="info"
                        :progres="penggajian.tingkat_hadir"
                    >
                        {{ penggajian.tingkat_hadir != null ? `${desimal(penggajian.tingkat_hadir)}%` : '-' }}
                        <template #keterangan>
                            <template v-if="penggajian.tingkat_hadir != null">
                                Rata-rata {{ desimal(penggajian.rata_hadir) }} hari hadir
                                dari {{ penggajian.pembagi_hadir }} {{ penggajian.dasar_hadir === 'kuota' ? 'kuota motoris' : orangLabel }}
                            </template>
                            <template v-else-if="penggajian.dasar_hadir === 'kuota' && penggajian.pembagi_hadir == null">
                                Kuota motoris depo belum diisi (Master Data › Kuota Motoris).
                            </template>
                        </template>
                    </StatCard>
                    <StatCard v-else :label="`Jumlah ${orangLabel}`" icon="users" nada="info">
                        {{ detail.length }} <span class="stat-card-satuan">{{ orangLabel }}</span>
                        <template #keterangan>Penerima insentif bulan {{ penggajian.periode_label }}</template>
                    </StatCard>
                </div>
            </section>

            <section aria-labelledby="judul-total" class="flex flex-col">
                <h6 id="judul-total" class="judul-bagian">Total Penggajian</h6>
                <div class="grid flex-1 gap-3 sm:grid-cols-2">
                    <StatCard label="Total Pendapatan" icon="banknote" nada="netral">
                        {{ rupiah(penggajian.total_pendapatan) }}
                        <template #keterangan>Potongan {{ rupiah(penggajian.total_potongan) }}</template>
                    </StatCard>
                    <StatCard label="Total Bersih" icon="badge-check" nada="sukses">
                        {{ rupiah(penggajian.total_bersih) }}
                        <template #keterangan>Pendapatan dikurangi potongan</template>
                    </StatCard>
                </div>
            </section>
        </div>

        <DataTable
            title="Rincian per Orang"
            tinggi-penuh
            :columns="KOLOM"
            :rows="baris"
            :total="baris.length"
            empty-text="Tidak ada orang di penggajian ini."
            :baris-total="barisTotal"
            baris-bisa-diklik
            penanda-klik
            @klik-baris="dipilih = $event"
        >
            <template #cell-nama="{ row }">
                {{ row.nama }}
                <Badge v-if="row.masalah" variant="warning" class="ms-1" :title="row.masalah">Data kurang</Badge>
            </template>
        </DataTable>

        <Modal :show="orang !== null" :title="orang?.nama ?? ''" placement="kanan" size="xl" @close="dipilih = null">
            <RincianOrang v-if="orang" :key="orang.id" :orang="orang" :kategori="penggajian.kategori" :base="urlIni" :pemotong="pemotong" :boleh-ubah="bolehUbah" />
        </Modal>

        <Modal :show="aksi !== null" :title="KONFIRMASI[aksi]?.judul ?? ''" @close="aksi = null">
            <template v-if="aksi === 'finalkan'">
                <Alert v-if="penggajian.belum_selesai" variant="warning">
                    Periode ini belum selesai (berakhir {{ tanggalSingkat(penggajian.periode_selesai) }}).
                    <template v-if="insentif">Penjualan yang belum terjadi belum terhitung, sehingga insentif bisa lebih kecil.</template>
                    <template v-else>Hari yang belum terjadi dihitung tidak hadir, sehingga tunjangan prorata lebih kecil.</template>
                    Sebaiknya finalkan setelah periode berakhir.
                </Alert>
                <p class="mb-0">Setelah difinalkan, penggajian terkunci: tidak bisa diubah, dihitung ulang, atau dibatalkan.</p>
            </template>
            <p v-else-if="aksi === 'kembalikan-ke-draf'" class="mb-0">Penggajian kembali berstatus Draf sehingga bisa dikoreksi (hari izin, komponen manual, hitung ulang), lalu difinalkan lagi. Selama Draf, penggajian ini tidak muncul di Riwayat per Orang dan tidak bisa diekspor.</p>
            <p v-else-if="aksi === 'batalkan'" class="mb-0">Draf dibatalkan dan tidak bisa dibuka kembali. Penggajian baru untuk periode yang sama tetap bisa dibuat.</p>
            <p v-else-if="aksi === 'hitung-ulang'" class="mb-0">Komponen otomatis dihitung ulang dari {{ insentif ? 'penjualan, rumus, dan konversi poin' : 'data karyawan dan absensi' }} terbaru. {{ insentif ? '' : 'Hari izin dan ' }}Komponen manual dipertahankan.</p>
            <template #footer>
                <Button variant="light" @click="aksi = null">Batal</Button>
                <Button v-if="aksi" :variant="KONFIRMASI[aksi].variant" :loading="memproses" @click="jalankan">{{ KONFIRMASI[aksi].tombol }}</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
