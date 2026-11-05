<script setup>
import { computed } from 'vue';
import { Deferred } from '@inertiajs/vue3';
import StatCard from '@/Components/ui/molecules/StatCard.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import { angka, angkaRingkas } from '@/Support/format.js';
import { nadaArah, nadaCapai, nadaJumlah, nadaTurun, useMetrikBeranda } from './metrikBeranda.js';
import Selisih from './Selisih.vue';
import TrenHarianChart from './TrenHarianChart.vue';

// Tab Ringkasan Beranda admin: satu kartu kunci per aspek (penyebaran, kualitas data, tindak lanjut koordinator, aktivitas,
// hal yang perlu perhatian) dan tren harian. Kartu menuju tab rinciannya (emit `buka`) atau menu terkait.
const props = defineProps({
    data: { type: Object, required: true },
});

defineEmits(['buka']);

const { kini, lalu, jangkauan, roKini, roLalu, fiktifKini, fiktifLalu } = useMetrikBeranda(() => props.data);

const persenCheckin = computed(() => (props.data.checkinPagi.total ? (props.data.checkinPagi.sudah / props.data.checkinPagi.total) * 100 : 0));
const persenHadir =computed(() => (props.data.absenHariIni.total ? (props.data.absenHariIni.hadir / props.data.absenHariIni.total) * 100 : 0));
</script>

<template>
    <section class="mb-4" aria-labelledby="judul-ringkas-hadir">
        <h6 id="judul-ringkas-hadir" class="judul-bagian">Kehadiran Hari Ini</h6>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Motoris Sudah Absen" icon="user-check" :nada="nadaCapai(persenHadir)" :progres="data.absenHariIni.total ? persenHadir : null">
                {{ angka(data.absenHariIni.hadir) }} <span class="stat-card-satuan">/ {{ angka(data.absenHariIni.total) }} motoris aktif</span>
                <template #keterangan>
                    {{ data.absenHariIni.total ? `${angka(data.absenHariIni.total - data.absenHariIni.hadir)} belum absen datang di depo` : 'Belum ada motoris aktif.' }}
                </template>
            </StatCard>
            <StatCard label="Motoris Checkin Pagi" icon="store" :nada="data.checkinPagi.lewat ? nadaCapai(persenCheckin) : 'netral'" :progres="data.checkinPagi.total ? persenCheckin : null">
                {{ angka(data.checkinPagi.sudah) }} <span class="stat-card-satuan">sudah / {{ angka(data.checkinPagi.belum) }} belum</span>
                <template #keterangan>
                    <template v-if="!data.checkinPagi.total">Belum ada motoris aktif.</template>
                    <template v-else-if="data.checkinPagi.lewat">Batas jam {{ data.checkinPagi.batas }} lewat; yang belum tidak akan checkin hari ini.</template>
                    <template v-else>Checkin outlet pertama paling lambat jam {{ data.checkinPagi.batas }} waktu setempat.</template>
                </template>
            </StatCard>
        </div>
    </section>

    <section class="mb-4" aria-labelledby="judul-ringkas-hasil">
        <h6 id="judul-ringkas-hasil" class="judul-bagian">Hasil · Penyebaran dan Kualitas Data</h6>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Outlet Terjangkau" icon="store" :nada="kini ? nadaArah(kini.outlet, lalu.outlet) : 'netral'" :memuat="!kini" :progres="kini ? jangkauan : null" clickable @click="$emit('buka', 'outlet')">
                {{ angka(kini?.outlet ?? 0) }} <span class="stat-card-satuan">outlet</span>
                <template #keterangan>
                    <Selisih v-if="kini" :sekarang="kini.outlet" :sebelum="lalu.outlet" /> · {{ angkaRingkas(jangkauan, 1) }}% dari terdaftar
                </template>
            </StatCard>
            <StatCard label="Repeat Order" icon="undo" :nada="kini ? nadaArah(roKini, roLalu) : 'netral'" :memuat="!kini" :progres="kini ? roKini : null" clickable @click="$emit('buka', 'outlet')">
                {{ angkaRingkas(roKini, 1) }}<span class="stat-card-satuan">%</span>
                <template #keterangan>
                    <Selisih v-if="kini" :sekarang="roKini" :sebelum="roLalu" mode="poin" /> · outlet membeli ≥2 kali
                </template>
            </StatCard>
            <StatCard label="Rasio Fiktif" icon="flag" :nada="kini ? nadaTurun(fiktifKini, fiktifLalu) : 'netral'" :memuat="!kini" :progres="kini ? fiktifKini : null" clickable @click="$emit('buka', 'outlet')">
                {{ angkaRingkas(fiktifKini, 1) }}<span class="stat-card-satuan">%</span>
                <template #keterangan>
                    <Selisih v-if="kini" :sekarang="fiktifKini" :sebelum="fiktifLalu" mode="poin" turun-baik /> · terbukti dari yang diperiksa
                </template>
            </StatCard>
            <StatCard label="Tindak Lanjut Temuan" icon="badge-check" :nada="data.koordinator ? nadaCapai(data.koordinator.nasional.tindak_lanjut) : 'netral'" :memuat="!data.koordinator" :progres="data.koordinator?.nasional.tindak_lanjut ?? null" clickable @click="$emit('buka', 'koordinator')">
                {{ data.koordinator?.nasional.tindak_lanjut === null || !data.koordinator ? '–' : `${angkaRingkas(data.koordinator.nasional.tindak_lanjut, 1)}%` }}
                <template #keterangan>
                    <template v-if="data.koordinator">{{ angka(data.koordinator.nasional.menunggu_lama) }} temuan menunggu lebih dari 7 hari</template>
                </template>
            </StatCard>
        </div>
    </section>

    <section class="mb-4" aria-labelledby="judul-ringkas-aktivitas">
        <h6 id="judul-ringkas-aktivitas" class="judul-bagian">Aktivitas dan Perlu Perhatian</h6>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Call" icon="phone" :nada="kini ? nadaCapai(kini.call_achievement) : 'netral'" :memuat="!kini" :progres="kini ? kini.call_achievement : null" clickable @click="$emit('buka', 'outlet')">
                {{ angkaRingkas(kini?.avg_call ?? 0, 1) }} <span class="stat-card-satuan">/ hari kerja</span>
                <template #keterangan>target {{ angkaRingkas(data.target.call, 1) }}</template>
            </StatCard>
            <StatCard label="Effective Call" icon="badge-check" :nada="kini ? nadaCapai(kini.ec_achievement) : 'netral'" :memuat="!kini" :progres="kini ? kini.ec_achievement : null" clickable @click="$emit('buka', 'outlet')">
                {{ angkaRingkas(kini?.avg_ec ?? 0, 1) }} <span class="stat-card-satuan">/ hari kerja</span>
                <template #keterangan>target {{ angkaRingkas(data.target.ec, 1) }}</template>
            </StatCard>
            <StatCard v-if="data.kendala" label="Laporan Kendala" icon="flag" :nada="nadaJumlah(data.kendala.terbuka)" :href="data.tautan.laporan_kendala">
                {{ angka(data.kendala.terbuka) }} <span class="stat-card-satuan">terbuka</span>
                <template #keterangan>
                    {{ data.kendala.ada_balasan ? `${angka(data.kendala.ada_balasan)} ada balasan baru` : 'Kendala lapangan dari koordinator.' }}
                </template>
            </StatCard>
            <StatCard label="Depo Tertinggal" icon="warehouse" :nada="nadaJumlah(data.ringkasanDepo?.depoTertinggal ?? 0, 'bahaya')" :memuat="!data.ringkasanDepo" clickable @click="$emit('buka', 'koordinator')">
                {{ angka(data.ringkasanDepo?.depoTertinggal ?? 0) }} <span class="stat-card-satuan">dari {{ angka(data.ringkasanDepo?.kpi.depo_aktif ?? 0) }} depo</span>
                <template #keterangan>EC Achievement di bawah 75%.</template>
            </StatCard>
        </div>
    </section>

    <Deferred data="tren">
        <template #fallback>
            <Card class="mb-4" body-class="p-4 animate-pulse" aria-busy="true"><div class="h-48 rounded bg-gray-200"></div></Card>
        </template>

        <Card class="shadow-sm mb-4">
            <template #header><h6 class="mb-0 font-semibold">Tren Harian</h6></template>
            <TrenHarianChart :data="data.tren" />
        </Card>
    </Deferred>
</template>
