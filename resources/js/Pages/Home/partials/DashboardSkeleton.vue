<script setup>
import Card from '@/Components/ui/molecules/Card.vue';

// Placeholder grafik Beranda admin saat funnel/tren/outlet per depo masih diambil (lihat <Deferred> di Home/Dashboard.vue).
</script>

<template>
    <div class="animate-pulse" aria-busy="true" aria-live="polite">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 mb-4">
            <Card class="lg:col-span-7" body-class="p-4"><div class="h-48 rounded bg-gray-200"></div></Card>
            <Card class="lg:col-span-5" body-class="p-4"><div class="h-48 rounded bg-gray-200"></div></Card>
        </div>
        <Card body-class="p-4"><div class="h-40 rounded bg-gray-200"></div></Card>
    </div>
</template>
