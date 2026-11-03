<script setup>
import Badge from '@/Components/ui/atoms/Badge.vue';
import Skeleton from '@/Components/ui/atoms/Skeleton.vue';
import { angka } from '@/Support/format.js';

// Isi drawer kartu ringkas Beranda Koordinator: satu tabel per jenis (absen | checkin | motoris_ditandai |
// outlet_ditandai), dipakai lewat satu <Modal placement="kanan"> di DashboardKoordinator.vue supaya tidak
// perlu empat instance Modal. Data diambil lewat fetch JSON (lihat bukaDrawer di DashboardKoordinator.vue),
// bukan Inertia::render, sama dengan pola "Lihat Detail" di Motoris/SummaryDepo.vue.
defineProps({
    jenis: { type: String, required: true },
    baris: { type: Array, default: null },
    galat: { type: String, default: null },
});
</script>

<template>
    <div v-if="galat" class="alert alert-warning">{{ galat }}</div>
    <div v-else-if="!baris" class="space-y-2" aria-busy="true" aria-live="polite">
        <Skeleton v-for="i in 5" :key="i" class="h-8 w-full" />
    </div>
    <p v-else-if="!baris.length" class="text-center text-gray-500 py-4">Tidak ada data.</p>

    <div v-else class="overflow-x-auto">
        <table v-if="jenis === 'absen'" class="data-table">
            <thead><tr><th>Nama Sales</th><th class="text-center">Status</th><th>Jam Masuk</th></tr></thead>
            <tbody>
                <tr v-for="(b, i) in baris" :key="i">
                    <td>{{ b.nama }}</td>
                    <td class="text-center">
                        <Badge v-if="b.sudah" variant="success">Sudah</Badge>
                        <Badge v-else variant="danger">Belum</Badge>
                    </td>
                    <td>{{ b.waktu ?? '-' }} <Badge v-if="b.terlambat" variant="warning">Terlambat</Badge></td>
                </tr>
            </tbody>
        </table>

        <table v-else-if="jenis === 'checkin'" class="data-table">
            <thead><tr><th>Nama Sales</th><th class="text-center">Status</th><th>Checkin Pertama</th></tr></thead>
            <tbody>
                <tr v-for="(b, i) in baris" :key="i">
                    <td>{{ b.nama }}</td>
                    <td class="text-center">
                        <Badge v-if="b.sudah" variant="success">Sudah</Badge>
                        <Badge v-else variant="danger">Belum</Badge>
                    </td>
                    <td>{{ b.waktu ?? '-' }}</td>
                </tr>
            </tbody>
        </table>

        <table v-else-if="jenis === 'motoris_ditandai'" class="data-table">
            <thead><tr><th>Motoris</th><th>Kategori</th><th>Tanggal</th><th>Ditandai Oleh</th></tr></thead>
            <tbody>
                <tr v-for="(b, i) in baris" :key="i">
                    <td>{{ b.motoris }}</td>
                    <td><Badge variant="danger">{{ b.kategori }}</Badge></td>
                    <td>{{ b.tanggal }}</td>
                    <td>{{ b.ditandai_oleh }}</td>
                </tr>
            </tbody>
        </table>

        <table v-else-if="jenis === 'outlet_ditandai'" class="data-table">
            <thead><tr><th>Outlet</th><th>Kategori</th><th>Ditandai Pada</th><th>Ditandai Oleh</th></tr></thead>
            <tbody>
                <tr v-for="(b, i) in baris" :key="i">
                    <td>{{ b.outlet }}</td>
                    <td><Badge variant="danger">{{ b.kategori }}</Badge></td>
                    <td>{{ b.waktu }}</td>
                    <td>{{ b.ditandai_oleh }}</td>
                </tr>
            </tbody>
        </table>

        <p class="text-xs text-gray-500 mt-2">{{ angka(baris.length) }} baris.</p>
    </div>
</template>
