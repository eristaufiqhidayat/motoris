<script setup>
import { computed, ref } from 'vue';
import Badge from '@/Components/ui/atoms/Badge.vue';
import InfoPopover from '@/Components/ui/molecules/InfoPopover.vue';
import StatCard from '@/Components/ui/molecules/StatCard.vue';
import DataTable from '@/Components/ui/organisms/DataTable.vue';
import { angka, angkaRingkas } from '@/Support/format.js';
import Selisih from './Selisih.vue';

// Kinerja Koordinator di Beranda admin (spec 067): kartu ringkasan nasional dan tabel koordinator yang paling perlu perhatian,
// semuanya 30 hari terakhir dibanding 30 hari sebelumnya. Inti metriknya membandingkan temuan sistem dengan tindak lanjut
// koordinator. Rasio Terbukti hanya penanda audit (lencana Perlu audit), bukan nilai kinerja.
const props = defineProps({
    // { nasional, sebelumnya, perhatian } dari KinerjaKoordinator.
    koordinator: { type: Object, required: true },
    // Tautan menu rincian ({ motoris_ditandai, outlet_all }); null bila akun tidak boleh membukanya.
    tautan: { type: Object, default: () => ({}) },
});

const kini = computed(() => props.koordinator.nasional);
const lalu = computed(() => props.koordinator.sebelumnya);

const persen = (n) => (n === null || n === undefined ? '–' : `${angkaRingkas(n, 1)}%`);
const hari = (n) => (n === null || n === undefined ? '–' : `${angkaRingkas(n, 1)} hari`);
const nadaCapai = (n) => (n === null ? 'netral' : (n >= 80 ? 'sukses' : (n >= 50 ? 'peringatan' : 'bahaya')));
const nadaJumlah = (n) => (n > 0 ? 'bahaya' : 'sukses');

const KOLOM = {
    nama: 'Koordinator',
    depo: 'Depo',
    motoris: 'Motoris',
    outlet_per_motoris: 'Outlet / Motoris',
    tindak_lanjut: 'Tindak Lanjut',
    median_hari: 'Median Waktu',
    menunggu_lama: 'Menunggu Lama',
    noo_median_hari: 'Verifikasi NOO',
    rasio_terbukti: 'Rasio Terbukti',
    cakupan_join_visit: 'Cakupan Join Visit',
    join_visit_sah: 'Join Visit Sah',
};

// Urutan bawaan = urutan server (tindak lanjut terendah dulu), yaitu saat sort null; kolom bisa diurutkan di klien.
const sort = ref(null);
const baris = computed(() => {
    if (!sort.value) return props.koordinator.perhatian;
    const { kolom, arah } = sort.value;
    const faktor = arah === 'asc' ? 1 : -1;
    const nilai = (b) => b[kolom] ?? -1;

    return [...props.koordinator.perhatian].sort((a, b) => (typeof a[kolom] === 'string' || typeof b[kolom] === 'string' ? String(a[kolom] ?? '').localeCompare(String(b[kolom] ?? '')) : nilai(a) - nilai(b)) * faktor);
});
</script>

<template>
    <section class="mb-4" aria-labelledby="judul-koordinator">
        <h6 id="judul-koordinator" class="judul-bagian">Kinerja Koordinator · Tindak Lanjut Temuan Sistem</h6>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 mb-3">
            <StatCard label="Tindak Lanjut Temuan" icon="badge-check" :nada="nadaCapai(kini.tindak_lanjut)" :progres="kini.tindak_lanjut">
                {{ persen(kini.tindak_lanjut) }}
                <template #keterangan>
                    <Selisih v-if="kini.tindak_lanjut !== null && lalu.tindak_lanjut !== null" :sekarang="kini.tindak_lanjut" :sebelum="lalu.tindak_lanjut" mode="poin" /> ·
                    {{ angka(kini.diperiksa) }} dari {{ angka(kini.temuan) }} temuan diperiksa · median {{ hari(kini.median_hari) }}
                </template>
            </StatCard>
            <StatCard label="Temuan Menunggu Lama" icon="flag" :nada="nadaJumlah(kini.menunggu_lama)" :href="tautan.motoris_ditandai">
                {{ angka(kini.menunggu_lama) }} <span class="stat-card-satuan">temuan</span>
                <template #keterangan>Perlu dicek lebih dari 7 hari.</template>
            </StatCard>
            <StatCard label="Cakupan Join Visit" icon="users" :nada="nadaCapai(kini.cakupan_join_visit)" :progres="kini.cakupan_join_visit">
                {{ persen(kini.cakupan_join_visit) }}
                <template #keterangan>
                    <Selisih :sekarang="kini.cakupan_join_visit" :sebelum="lalu.cakupan_join_visit" mode="poin" /> · motoris yang di-join visit koordinatornya
                </template>
            </StatCard>
            <StatCard label="Verifikasi NOO" icon="map-pin" :nada="nadaJumlah(kini.noo_menunggu_lama)" :href="tautan.outlet_all">
                {{ hari(kini.noo_median_hari) }}
                <template #keterangan>Median sampai diverifikasi · {{ angka(kini.noo_menunggu_lama) }} menunggu lebih dari 3 hari.</template>
            </StatCard>
            <StatCard label="Koordinator Perlu Audit" icon="user-x" :nada="nadaJumlah(kini.perlu_audit)">
                {{ angka(kini.perlu_audit) }} <span class="stat-card-satuan">dari {{ angka(kini.koordinator) }}</span>
                <template #keterangan>Rasio Terbukti {{ persen(kini.rasio_terbukti) }} secara nasional.</template>
            </StatCard>
        </div>

        <DataTable
            :columns="KOLOM"
            :rows="baris"
            :total="baris.length"
            :sortable="Object.keys(KOLOM)"
            :sort="sort"
            empty-text="Belum ada koordinator aktif dalam cakupan."
            @sort="sort = $event.arah ? $event : null"
        >
            <template #header>
                <h6 class="mb-0 font-semibold">
                    Koordinator Perlu Perhatian (10 teratas)
                    <InfoPopover title="Cara membaca tabel ini" align="start" class="ms-1 align-middle">
                        <ul class="list-disc ps-4 space-y-1">
                            <li><b>Sumber:</b> 30 hari terakhir. Tim = motoris aktif yang koordinatornya baris itu. Temuan = tanda sistem (lokasi palsu, outlet fiktif) yang dibuat otomatis tiap malam.</li>
                            <li><b>Urutan:</b> Tindak Lanjut terendah dulu (koordinator tanpa temuan di akhir), lalu Cakupan Join Visit terendah.</li>
                            <li><b>Tindak Lanjut:</b> temuan yang sudah diperiksa (Terbukti/Wajar) ÷ temuan yang dibuat dalam periode. Tanda outlet dihitung milik tim bila motoris tim pernah mengunjungi outletnya.</li>
                            <li><b>Median Waktu:</b> median hari dari temuan dibuat sampai diperiksa. <b>Menunggu Lama:</b> temuan masih Perlu dicek lebih dari 7 hari (semua periode).</li>
                            <li><b>Rasio Terbukti:</b> Terbukti ÷ diperiksa. Hanya penanda: lencana <i>Perlu audit</i> muncul bila minimal 5 temuan diperiksa dan rasionya kurang dari separuh rasio nasional (bisa timnya bersih, bisa juga semua temuan ditutup sebagai Wajar).</li>
                            <li><b>Outlet / Motoris:</b> outlet berbeda yang membeli (EC, tidak dibatalkan, bukan outlet terbukti fiktif) ÷ motoris tim yang menjual.</li>
                            <li><b>Cakupan Join Visit:</b> motoris tim yang pernah di-join visit koordinator ÷ motoris tim. <b>Join Visit Sah:</b> tanpa mock location, sudah selesai, jarak ke outlet ≤ 200 m.</li>
                            <li><b>Verifikasi NOO:</b> median hari dari pendaftaran sampai disetujui/ditolak koordinator; lencana menunjukkan pendaftaran yang menunggu lebih dari 3 hari.</li>
                        </ul>
                    </InfoPopover>
                </h6>
            </template>

            <template #cell-nama="{ row }">
                <span class="font-semibold">{{ row.nama }}</span>
                <Badge v-if="row.perlu_audit" variant="danger" class="ms-1">Perlu audit</Badge>
            </template>
            <template #cell-motoris="{ row }">{{ angka(row.motoris) }}</template>
            <template #cell-outlet_per_motoris="{ row }">{{ angkaRingkas(row.outlet_per_motoris, 1) }}</template>
            <template #cell-tindak_lanjut="{ row }">
                {{ persen(row.tindak_lanjut) }} <span class="text-gray-500">({{ angka(row.diperiksa) }}/{{ angka(row.temuan) }})</span>
            </template>
            <template #cell-median_hari="{ row }">{{ hari(row.median_hari) }}</template>
            <template #cell-menunggu_lama="{ row }">
                <Badge v-if="row.menunggu_lama" variant="warning">{{ angka(row.menunggu_lama) }}</Badge>
                <span v-else class="text-gray-500">0</span>
            </template>
            <template #cell-noo_median_hari="{ row }">
                {{ hari(row.noo_median_hari) }}
                <Badge v-if="row.noo_menunggu_lama" variant="warning" class="ms-1" :title="`${row.noo_menunggu_lama} NOO menunggu lebih dari 3 hari`">{{ angka(row.noo_menunggu_lama) }} menunggu</Badge>
            </template>
            <template #cell-rasio_terbukti="{ row }">{{ persen(row.rasio_terbukti) }}</template>
            <template #cell-cakupan_join_visit="{ row }">{{ persen(row.cakupan_join_visit) }}</template>
            <template #cell-join_visit_sah="{ row }">{{ persen(row.join_visit_sah) }}</template>
        </DataTable>
    </section>
</template>
