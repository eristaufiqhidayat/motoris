<script setup>
import { computed } from 'vue';
import { angkaRingkas } from '@/Support/format.js';

// Selisih nilai periode ini terhadap periode sebelumnya (Beranda admin, spec 066), untuk keterangan StatCard.
// mode 'persen': perubahan relatif ((sekarang − sebelum) ÷ sebelum); 'poin': selisih langsung, untuk nilai yang
// sudah berupa persen (mis. repeat order 40% → 45% = ▲ 5 poin). Naik dianggap baik, kecuali `turunBaik` (metrik keburukan seperti
// outlet fiktif atau NOO ditolak): turun hijau, naik merah.
const props = defineProps({
    sekarang: { type: Number, required: true },
    sebelum: { type: Number, required: true },
    mode: { type: String, default: 'persen' },
    turunBaik: { type: Boolean, default: false },
});

const selisih = computed(() => {
    if (props.mode === 'poin') return props.sekarang - props.sebelum;

    return props.sebelum > 0 ? ((props.sekarang - props.sebelum) / props.sebelum) * 100 : null;
});

// Dibulatkan dulu supaya ▲ 0% tidak muncul untuk perubahan yang sangat kecil.
const bulat = computed(() => (selisih.value === null ? null : Math.round(selisih.value * 10) / 10));
const arah = computed(() => (!bulat.value ? 'tetap' : (bulat.value > 0 ? 'naik' : 'turun')));
// Warna: baik = hijau, buruk = merah; arah panah tetap mengikuti arah angka.
const warna = computed(() => (arah.value === 'tetap' ? 'tetap' : ((arah.value === 'naik') !== props.turunBaik ? 'naik' : 'turun')));
const satuan = computed(() => (props.mode === 'poin' ? ' poin' : '%'));
</script>

<template>
    <span v-if="bulat === null" class="selisih selisih-tetap">belum ada pembanding</span>
    <span v-else class="selisih" :class="`selisih-${warna}`" :title="`Dibanding 30 hari sebelumnya`">
        {{ arah === 'naik' ? '▲' : (arah === 'turun' ? '▼' : '–') }} {{ angkaRingkas(Math.abs(bulat), 1) }}{{ satuan }}
    </span>
</template>
