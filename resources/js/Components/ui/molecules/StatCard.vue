<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Card from '@/Components/ui/molecules/Card.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import Skeleton from '@/Components/ui/atoms/Skeleton.vue';

// Kartu angka ringkas: ikon berwarna sesuai nada, label, nilai besar (slot default), keterangan (slot), progres
// opsional. Dengan `href`, seluruh kartu jadi tautan Inertia ke halaman rinciannya; dengan `clickable` (tanpa
// href), kartu jadi tombol yang memancarkan event `click` — dipakai untuk membuka drawer tanpa pindah halaman.
const props = defineProps({
    label: { type: String, required: true },
    icon: { type: String, required: true },
    // netral | info | sukses | peringatan | bahaya
    nada: { type: String, default: 'netral' },
    href: { type: String, default: null },
    clickable: { type: Boolean, default: false },
    // 0–100; null = tanpa batang progres.
    progres: { type: Number, default: null },
    memuat: { type: Boolean, default: false },
});

defineEmits(['click']);

const interaktif = computed(() => !!props.href || props.clickable);
const tag = computed(() => (props.href ? Link : (props.clickable ? 'button' : 'div')));

const lebarProgres = computed(() => `${Math.min(100, Math.max(0, props.progres ?? 0))}%`);
</script>

<template>
    <Card
        :tag="tag" :href="href ?? undefined" :type="tag === 'button' ? 'button' : undefined"
        class="stat-card" :class="{ 'stat-card-link': interaktif }"
        @click="clickable && $emit('click', $event)"
    >
        <div class="stat-card-isi">
            <span class="stat-card-ikon" :class="`stat-card-ikon-${nada}`"><Icon :name="icon" /></span>
            <div class="min-w-0 flex-1">
                <div class="stat-card-label">{{ label }}</div>
                <div v-if="memuat" class="animate-pulse" aria-busy="true">
                    <Skeleton class="mt-1 h-6 w-2/3" />
                    <Skeleton class="mt-1.5 h-3 w-1/2" />
                </div>
                <template v-else>
                    <div class="stat-card-nilai"><slot /></div>
                    <div v-if="$slots.keterangan" class="stat-card-keterangan"><slot name="keterangan" /></div>
                </template>
            </div>
            <Icon v-if="interaktif" name="chevron-right" class="stat-card-panah" />
        </div>
        <div v-if="progres !== null && !memuat" class="progress stat-card-progres" role="progressbar" :aria-valuenow="Math.round(progres)" aria-valuemin="0" aria-valuemax="100" :aria-label="label">
            <div class="progress-bar" :class="`progress-bar-${nada}`" :style="{ width: lebarProgres }"></div>
        </div>
    </Card>
</template>
