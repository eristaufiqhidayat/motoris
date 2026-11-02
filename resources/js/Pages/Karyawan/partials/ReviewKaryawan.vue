<script setup>
import { computed } from 'vue';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import { rupiah, tanggalSingkat } from '@/Support/format.js';

// Review (baca saja) data karyawan, dibuka saat klik nama di daftar. Tombol "Update Motoris" di footer
// modal (lihat Index.vue) yang mengganti drawer ini jadi FormKaryawan, tanpa fetch ulang.
const props = defineProps({
    kategori: { type: String, required: true },
    nama: { type: String, required: true },
    item: { type: Object, required: true },
    nilai: { type: Object, required: true },
    opsi: { type: Object, required: true },
});

const GAJI = [
    ['gaji_pokok', 'Gaji pokok'],
    ['tunjangan_bbm_makan', 'Tunjangan BBM & makan'],
    ['sewa_motor', 'Sewa motor'],
    ['tunjangan_internet', 'Tunjangan kuota internet'],
];

const pakaiDepo = computed(() => ['sales', 'koordinator'].includes(props.kategori));
const cariLabel = (daftar, value) => daftar?.find((o) => o.value === value)?.label ?? '-';
const tanggal = (t) => (t ? tanggalSingkat(t) || '-' : '-');

const area = computed(() => props.nilai.area_ids.map((id) => cariLabel(props.opsi.area, id)).join(', ') || '-');
const areaAktif = computed(() => (props.nilai.area_aktif_ids.length
    ? props.nilai.area_aktif_ids.map((id) => cariLabel(props.opsi.area, id)).join(', ')
    : 'Semua area jadi rute'));
</script>

<template>
    <div class="grid grid-cols-1 gap-3">
        <Card>
            <template #header>Data {{ nama }}</template>
            <dl class="grid grid-cols-2 gap-3 mb-0">
                <div class="col-span-2"><dt class="text-xs text-gray-500">Nama lengkap</dt><dd class="font-semibold mb-0">{{ nilai.nama || '-' }}</dd></div>
                <div><dt class="text-xs text-gray-500">NIK</dt><dd class="font-semibold mb-0">{{ nilai.nik || '-' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Tempat lahir</dt><dd class="font-semibold mb-0">{{ nilai.tempat_lahir || '-' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Tanggal lahir</dt><dd class="font-semibold mb-0">{{ tanggal(nilai.tanggal_lahir) }}</dd></div>
                <div class="col-span-2"><dt class="text-xs text-gray-500">Alamat</dt><dd class="font-semibold mb-0">{{ nilai.alamat || '-' }}</dd></div>

                <div v-if="kategori === 'lainnya'"><dt class="text-xs text-gray-500">Kategori karyawan</dt><dd class="font-semibold mb-0">{{ nilai.jabatan || '-' }}</dd></div>

                <template v-if="pakaiDepo">
                    <div><dt class="text-xs text-gray-500">Depo</dt><dd class="font-semibold mb-0">{{ cariLabel(opsi.depo, nilai.depo_id) }}</dd></div>
                    <div v-if="kategori === 'sales'"><dt class="text-xs text-gray-500">Koordinator</dt><dd class="font-semibold mb-0">{{ cariLabel(opsi.koordinator, nilai.koordinator_id) }}</dd></div>
                    <div v-else><dt class="text-xs text-gray-500">Wilayah depo</dt><dd class="font-semibold mb-0">{{ cariLabel(opsi.wilayah, nilai.wilayah_depo_id) }}</dd></div>
                </template>

                <div><dt class="text-xs text-gray-500">No HP</dt><dd class="font-semibold mb-0">{{ nilai.no_hp || '-' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Email</dt><dd class="font-semibold mb-0">{{ nilai.email || '-' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Tanggal mulai bekerja</dt><dd class="font-semibold mb-0">{{ tanggal(nilai.tanggal_mulai) }}</dd></div>
                <div><dt class="text-xs text-gray-500">Status</dt><dd class="mb-0"><Badge :variant="nilai.aktif === '1' ? 'success' : 'secondary'">{{ nilai.aktif === '1' ? 'Aktif' : 'Tidak aktif' }}</Badge></dd></div>
            </dl>
        </Card>

        <Card v-if="kategori === 'sales'">
            <template #header>Area motoris</template>
            <dl class="grid grid-cols-1 gap-3 mb-0">
                <div><dt class="text-xs text-gray-500">Area</dt><dd class="font-semibold mb-0">{{ area }}</dd></div>
                <div><dt class="text-xs text-gray-500">Area aktif (rute di aplikasi HP)</dt><dd class="font-semibold mb-0">{{ areaAktif }}</dd></div>
            </dl>
        </Card>

        <Card>
            <template #header>Gaji & tunjangan (Rp)</template>
            <dl class="grid grid-cols-2 gap-3 mb-0">
                <div v-for="[kolom, label] in GAJI" :key="kolom">
                    <dt class="text-xs text-gray-500">{{ label }}</dt>
                    <dd class="font-semibold mb-0">{{ nilai[kolom] !== '' ? rupiah(Number(nilai[kolom])) : '-' }}</dd>
                </div>
            </dl>
        </Card>

        <Card>
            <template #header>Foto</template>
            <span class="foto-produk">
                <img v-if="item.foto" :src="item.foto" :alt="`Foto ${item.nama}`">
                <template v-else>Belum ada foto</template>
            </span>
        </Card>
    </div>
</template>
