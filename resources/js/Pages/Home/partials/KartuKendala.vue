<script setup>
import { Link } from '@inertiajs/vue3';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import StatLabel from '@/Components/ui/atoms/StatLabel.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import { angka, tanggalSingkat } from '@/Support/format.js';

// Kartu Laporan Kendala di Beranda (spec 064). Cakupan tim (koordinator): jumlah Terbuka tim, 5 terbaru, tombol Buat
// Laporan. Cakupan lain (supervisor/admin): jumlah Terbuka dan Belum dibaca, 5 terbaru yang belum dibaca.
// `ringkas` (Beranda admin): kartu statistik berisi jumlah Terbuka, menuju /laporan_kendala.
defineProps({
    kendala: { type: Object, required: true },
    ringkas: { type: Boolean, default: false },
});
</script>

<template>
    <Link v-if="ringkas" href="/laporan_kendala" class="block no-underline">
        <Card class="shadow-sm h-full" body-class="py-3 text-center">
            <StatLabel>Laporan Kendala</StatLabel>
            <div class="text-lg font-bold text-amber-600"><Icon name="flag" class="w-4 h-4 inline -mt-1" /> {{ angka(kendala.terbuka) }} Terbuka</div>
        </Card>
    </Link>
    <Card v-else class="mb-4" body-class="card-body py-2">
        <template #header>
            <div class="flex items-center justify-between gap-2">
                <span>{{ kendala.tim ? 'Laporan Kendala' : 'Kendala Lapangan' }}</span>
                <span class="flex items-center gap-2">
                    <Badge variant="warning">{{ angka(kendala.terbuka) }} Terbuka</Badge>
                    <Badge v-if="kendala.ada_balasan" variant="primary">{{ angka(kendala.ada_balasan) }} Ada balasan baru</Badge>
                    <Badge v-if="!kendala.tim" :variant="kendala.belum_dibaca ? 'danger' : 'secondary'">{{ angka(kendala.belum_dibaca) }} Belum dibaca</Badge>
                </span>
            </div>
        </template>

        <ul v-if="kendala.terbaru.length" class="m-0 list-none p-0">
            <li v-for="l in kendala.terbaru" :key="l.id" class="py-1">
                <Link :href="kendala.tim ? '/laporan_kendala' : '/laporan_kendala?belum_dibaca=1'" class="font-semibold">{{ l.judul }}</Link>
                <span class="text-gray-500"> · {{ l.kategori_label }} · {{ l.pelapor }} · {{ tanggalSingkat(l.tanggal_kejadian) }}</span>
            </li>
        </ul>
        <p v-else class="m-0 text-gray-500">{{ kendala.tim ? 'Tidak ada kendala yang Terbuka.' : 'Semua laporan Terbuka sudah dibaca.' }}</p>

        <template #footer>
            <div class="flex flex-wrap items-center gap-2">
                <Button v-if="kendala.bisa_tambah" :tag="Link" variant="success" href="/laporan_kendala?buat=1">Buat Laporan</Button>
                <Link href="/laporan_kendala">Lihat semua laporan</Link>
            </div>
        </template>
    </Card>
</template>
