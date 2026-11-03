<script setup>
import Card from '@/Components/ui/molecules/Card.vue';
import Skeleton from '@/Components/ui/atoms/Skeleton.vue';

// Placeholder Beranda Koordinator saat detail/funnel/tren masih diambil (lihat <Deferred> di Home/DashboardKoordinator.vue);
// bentuknya mengikuti tata letak aslinya: kartu Tim Saya, lalu Tren Harian (7/12) + Funnel Outlet (5/12).
</script>

<template>
    <div class="animate-pulse" aria-busy="true" aria-live="polite">
        <Card class="shadow-sm mb-4">
            <template #header><Skeleton class="h-4 w-32" /></template>
            <Skeleton class="h-12 w-full mb-3" />
            <Skeleton class="h-40 w-full" />
        </Card>
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-3">
            <Card class="shadow-sm lg:col-span-7">
                <template #header><Skeleton class="h-4 w-28" /></template>
                <Skeleton class="h-56 w-full" />
            </Card>
            <Card class="shadow-sm lg:col-span-5">
                <template #header><Skeleton class="h-4 w-28" /></template>
                <Skeleton class="h-56 w-full" />
            </Card>
        </div>
    </div>
</template>
