<script setup>
import { Deferred } from '@inertiajs/vue3';
import Card from '@/Components/ui/molecules/Card.vue';
import StatCard from '@/Components/ui/molecules/StatCard.vue';
import { angka, angkaRingkas } from '@/Support/format.js';
import FunnelOutletChart from './FunnelOutletChart.vue';
import { nadaArah, nadaCapai, nadaJumlah, nadaTurun, useMetrikBeranda } from './metrikBeranda.js';
import Selisih from './Selisih.vue';

// Tab Outlet & Motoris Beranda admin (spec 066/067): hasil dan aktivitas di lapangan. Seberapa luas produk tersebar (outlet
// terjangkau, outlet baru, repeat order, outlet stok kosong), seberapa bisa dipercaya datanya (outlet fiktif, verifikasi NOO), apakah
// motoris bekerja (call, EC, IPT, hari kerja, motoris ditandai), dan funnel outlet. Rincian per depo ada di tab Koordinator & Depo.
const props = defineProps({
    data: { type: Object, required: true },
});

const { kini, lalu, kpi, jangkauan, roKini, roLalu, funnelOss, fiktifKini, fiktifLalu, nooTolakKini, nooTolakLalu, strikeRate } = useMetrikBeranda(() => props.data);

</script>

<template>
    <section class="mb-4" aria-labelledby="judul-penyebaran">
        <h6 id="judul-penyebaran" class="judul-bagian">Penyebaran Produk ke Outlet</h6>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Outlet Terjangkau" icon="store" :nada="kini ? nadaArah(kini.outlet, lalu.outlet) : 'netral'" :memuat="!kini" :progres="kini ? jangkauan : null">
                {{ angka(kini?.outlet ?? 0) }} <span class="stat-card-satuan">outlet</span>
                <template #keterangan>
                    <Selisih v-if="kini" :sekarang="kini.outlet" :sebelum="lalu.outlet" /> ·
                    {{ angkaRingkas(jangkauan, 1) }}% dari {{ angka(data.perbandingan?.outlet_terdaftar ?? 0) }} terdaftar
                </template>
            </StatCard>
            <StatCard label="Outlet Baru (NOO)" icon="map-pin" :nada="kini ? nadaArah(kini.noo, lalu.noo) : 'netral'" :memuat="!kini">
                {{ angka(kini?.noo ?? 0) }} <span class="stat-card-satuan">outlet</span>
                <template #keterangan>
                    <Selisih v-if="kini" :sekarang="kini.noo" :sebelum="lalu.noo" /> · disetujui · {{ angka(kini?.noo_menunggu ?? 0) }} menunggu verifikasi
                </template>
            </StatCard>
            <StatCard label="Repeat Order" icon="undo" :nada="kini ? nadaArah(roKini, roLalu) : 'netral'" :memuat="!kini" :progres="kini ? roKini : null">
                {{ angkaRingkas(roKini, 1) }}<span class="stat-card-satuan">%</span>
                <template #keterangan>
                    <Selisih v-if="kini" :sekarang="roKini" :sebelum="roLalu" mode="poin" /> ·
                    {{ angka(kini?.ro ?? 0) }} outlet membeli ≥2 kali
                </template>
            </StatCard>
            <StatCard label="Outlet Stok Kosong" icon="box" :nada="nadaJumlah(funnelOss)" :memuat="!data.funnel" :href="data.tautan.outlet_oss">
                {{ angka(funnelOss) }} <span class="stat-card-satuan">outlet</span>
                <template #keterangan>Dikunjungi, belum membeli, tanpa stok produk kita.</template>
            </StatCard>
        </div>
    </section>

    <section class="mb-4" aria-labelledby="judul-kualitas">
        <h6 id="judul-kualitas" class="judul-bagian">Kualitas Data Outlet</h6>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Outlet Terbukti Fiktif" icon="flag" :nada="kini ? nadaTurun(kini.fiktif_terbukti, lalu.fiktif_terbukti) : 'netral'" :memuat="!kini" :href="data.tautan.outlet_ditandai">
                {{ angka(kini?.fiktif_terbukti ?? 0) }} <span class="stat-card-satuan">outlet</span>
                <template #keterangan>
                    <Selisih v-if="kini" :sekarang="kini.fiktif_terbukti" :sebelum="lalu.fiktif_terbukti" turun-baik /> · sebelumnya {{ angka(lalu?.fiktif_terbukti ?? 0) }}
                </template>
            </StatCard>
            <StatCard label="Rasio Fiktif" icon="boxes" :nada="kini ? nadaTurun(fiktifKini, fiktifLalu) : 'netral'" :memuat="!kini" :progres="kini ? fiktifKini : null">
                {{ angkaRingkas(fiktifKini, 1) }}<span class="stat-card-satuan">%</span>
                <template #keterangan>
                    <Selisih v-if="kini" :sekarang="fiktifKini" :sebelum="fiktifLalu" mode="poin" turun-baik /> ·
                    {{ angka(kini?.fiktif_terbukti ?? 0) }} terbukti dari {{ angka((kini?.fiktif_terbukti ?? 0) + (kini?.fiktif_wajar ?? 0)) }} diperiksa
                </template>
            </StatCard>
            <StatCard label="NOO Ditolak" icon="undo" :nada="kini ? nadaTurun(nooTolakKini, nooTolakLalu) : 'netral'" :memuat="!kini" :progres="kini ? nooTolakKini : null">
                {{ angkaRingkas(nooTolakKini, 1) }}<span class="stat-card-satuan">%</span>
                <template #keterangan>
                    <Selisih v-if="kini" :sekarang="nooTolakKini" :sebelum="nooTolakLalu" mode="poin" turun-baik /> ·
                    {{ angka(kini?.noo_ditolak ?? 0) }} dari {{ angka((kini?.noo_ditolak ?? 0) + (kini?.noo_disetujui ?? 0)) }} diverifikasi
                </template>
            </StatCard>
            <StatCard label="NOO Menunggu Verifikasi" icon="map-pin" :nada="nadaJumlah(data.perbandingan?.menunggu_total ?? 0)" :memuat="!data.perbandingan" :href="data.tautan.outlet_all">
                {{ angka(data.perbandingan?.menunggu_total ?? 0) }} <span class="stat-card-satuan">outlet</span>
                <template #keterangan>
                    {{ data.perbandingan?.menunggu_umur === null || data.perbandingan?.menunggu_umur === undefined ? 'Tidak ada antrean.' : `Tertua ${angka(data.perbandingan.menunggu_umur)} hari.` }}
                </template>
            </StatCard>
        </div>
    </section>

    <section class="mb-4" aria-labelledby="judul-aktivitas">
        <h6 id="judul-aktivitas" class="judul-bagian">Aktivitas Motoris</h6>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            <StatCard label="Call" icon="phone" :nada="kini ? nadaCapai(kini.call_achievement) : 'netral'" :memuat="!kini" :progres="kini ? kini.call_achievement : null">
                {{ angkaRingkas(kini?.avg_call ?? 0, 1) }} <span class="stat-card-satuan">/ hari kerja</span>
                <template #keterangan>
                    Total {{ angka(kini?.call ?? 0) }} <Selisih v-if="kini" :sekarang="kini.call" :sebelum="lalu.call" /> ·
                    target {{ angkaRingkas(data.target.call, 1) }}
                </template>
            </StatCard>
            <StatCard label="Effective Call" icon="badge-check" :nada="kini ? nadaCapai(kini.ec_achievement) : 'netral'" :memuat="!kini" :progres="kini ? kini.ec_achievement : null">
                {{ angkaRingkas(kini?.avg_ec ?? 0, 1) }} <span class="stat-card-satuan">/ hari kerja</span>
                <template #keterangan>
                    Total {{ angka(kini?.ec ?? 0) }} <Selisih v-if="kini" :sekarang="kini.ec" :sebelum="lalu.ec" /> ·
                    strike rate {{ angkaRingkas(strikeRate, 1) }}%
                </template>
            </StatCard>
            <StatCard label="IPT" icon="boxes" :nada="kini ? nadaCapai(kini.ipt_achievement) : 'netral'" :memuat="!kini" :progres="kini ? kini.ipt_achievement : null">
                {{ angkaRingkas(kini?.ipt ?? 0, 1) }} <span class="stat-card-satuan">SKU / EC</span>
                <template #keterangan>
                    <Selisih v-if="kini" :sekarang="kini.ipt" :sebelum="lalu.ipt" /> ·
                    {{ angkaRingkas(kini?.ipt_achievement ?? 0, 1) }}% dari target {{ angkaRingkas(data.target.ipt, 1) }}
                </template>
            </StatCard>
            <StatCard label="Motoris Aktif" icon="bike" :memuat="!kpi">
                {{ angka(kpi?.motoris_aktif ?? 0) }} <span class="stat-card-satuan">motoris</span>
                <template #keterangan>{{ angka(kpi?.depo_aktif ?? 0) }} depo · {{ angka(kpi?.sk_aktif ?? 0) }} SK aktif</template>
            </StatCard>
            <StatCard label="Hari Kerja" icon="calendar" :memuat="!kini || !kpi">
                {{ angkaRingkas(kpi?.motoris_aktif ? (kini?.hari_kerja ?? 0) / kpi.motoris_aktif : 0, 1) }} <span class="stat-card-satuan">hari / motoris</span>
                <template #keterangan>
                    Total {{ angka(kini?.hari_kerja ?? 0) }} hari kerja <Selisih v-if="kini" :sekarang="kini.hari_kerja" :sebelum="lalu.hari_kerja" />
                </template>
            </StatCard>
        </div>
    </section>

    <section class="mb-4" aria-labelledby="judul-perhatian">
        <h6 id="judul-perhatian" class="judul-bagian">Perlu Perhatian</h6>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Motoris Ditandai" icon="user-x" :nada="nadaJumlah(data.tandaMotoris?.motoris ?? 0, 'bahaya')" :memuat="!data.tandaMotoris" :href="data.tautan.motoris_ditandai">
                {{ angka(data.tandaMotoris?.motoris ?? 0) }} <span class="stat-card-satuan">motoris</span>
                <template #keterangan>
                    {{ data.tandaMotoris?.total ? `${angka(data.tandaMotoris.total)} tanda Perlu dicek` : 'Tidak ada tanda Perlu dicek.' }}
                </template>
            </StatCard>
        </div>
    </section>

    <Deferred data="funnel">
        <template #fallback>
            <Card class="mb-4" body-class="p-4 animate-pulse" aria-busy="true"><div class="h-48 rounded bg-gray-200"></div></Card>
        </template>

        <Card class="shadow-sm mb-4">
            <template #header><h6 class="mb-0 font-semibold">Funnel Outlet</h6></template>
            <FunnelOutletChart :funnel="data.funnel" />
        </Card>
    </Deferred>
</template>
