<script setup>
import { computed } from 'vue';
import { Deferred } from '@inertiajs/vue3';
import BarChart from '@/Components/ui/molecules/BarChart.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import StatCard from '@/Components/ui/molecules/StatCard.vue';
import { angka } from '@/Support/format.js';
import KinerjaKoordinator from './KinerjaKoordinator.vue';
import { nadaJumlah, useMetrikBeranda } from './metrikBeranda.js';

// Tab Koordinator & Depo Beranda admin (spec 066/067): kinerja organisasi lapangan. Depo yang tertinggal dan jangkauan outlet per
// motoris tiap depo, lalu apakah koordinator menjalankan perannya (tindak lanjut temuan sistem, hasil tim, join visit, verifikasi
// NOO). Data outlet ada di tab Outlet.
const props = defineProps({
    data: { type: Object, required: true },
});

const { kpi } = useMetrikBeranda(() => props.data);

const depoChart = computed(() => (props.data.outletDepo ?? []).slice(0, 10).map((d) => ({ label: d.nama, value: d.per_motoris })));
</script>

<template>
    <section class="mb-4" aria-labelledby="judul-depo">
        <h6 id="judul-depo" class="judul-bagian">Depo</h6>
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-3">
            <div class="lg:col-span-4">
                <StatCard label="Depo Tertinggal" icon="warehouse" :nada="nadaJumlah(data.ringkasanDepo?.depoTertinggal ?? 0, 'bahaya')" :memuat="!data.ringkasanDepo" :href="data.tautan.summary_all_depo">
                    {{ angka(data.ringkasanDepo?.depoTertinggal ?? 0) }} <span class="stat-card-satuan">dari {{ angka(kpi?.depo_aktif ?? 0) }} depo</span>
                    <template #keterangan>EC Achievement di bawah 75%.</template>
                </StatCard>
            </div>
            <Card class="shadow-sm lg:col-span-8">
                <template #header><h6 class="mb-0 font-semibold">Depo · Outlet Terjangkau per Motoris (10 terendah)</h6></template>
                <template v-if="data.outletDepo">
                    <BarChart v-if="depoChart.length" :items="depoChart" :desimal="1" />
                    <p v-else class="m-0 py-4 text-center text-gray-500">Belum ada data.</p>
                </template>
                <div v-else class="animate-pulse" aria-busy="true"><div class="h-40 rounded bg-gray-200"></div></div>
            </Card>
        </div>
    </section>

    <Deferred data="koordinator">
        <template #fallback>
            <div class="mb-4 animate-pulse" aria-busy="true">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 mb-3">
                    <Card v-for="kartu in 4" :key="kartu" body-class="p-4"><div class="h-12 rounded bg-gray-200"></div></Card>
                </div>
                <Card body-class="p-4"><div class="h-40 rounded bg-gray-200"></div></Card>
            </div>
        </template>

        <KinerjaKoordinator :koordinator="data.koordinator" :tautan="data.tautan" />
    </Deferred>
</template>
