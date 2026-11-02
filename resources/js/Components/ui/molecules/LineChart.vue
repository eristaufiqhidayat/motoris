<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { angkaRingkas } from '@/Support/format.js';

// Grafik garis multi-seri (SVG, tanpa pustaka, teknik sama dengan BarChart): satu garis per seri dengan titik, tooltip
// bawaan (<title>), garis bantu sumbu Y, dan legenda. Lebar mengisi wadah (diukur ulang saat wadah berubah ukuran);
// bila titik terlalu banyak untuk lebar itu, grafik melebar sesuai jumlah titik dan digeser mendatar.
const props = defineProps({
    // Label sumbu X, satu per titik (mis. "03/11").
    labels: { type: Array, required: true },
    // [{ key, label, values: number[], warna: 'biru' | 'jingga' | 'hijau' | 'ungu' }]; values sejajar dengan labels.
    series: { type: Array, required: true },
    height: { type: Number, default: 180 },
    ariaLabel: { type: String, default: 'Grafik garis' },
});

// Kelas SVG per warna seri (stroke garis, fill titik dan swatch legenda). Statis agar Tailwind menyertakannya.
const WARNA = {
    biru: { garis: 'stroke-blue-500', titik: 'fill-blue-500', swatch: 'bg-blue-500' },
    jingga: { garis: 'stroke-orange-500', titik: 'fill-orange-500', swatch: 'bg-orange-500' },
    hijau: { garis: 'stroke-green-600', titik: 'fill-green-600', swatch: 'bg-green-600' },
    ungu: { garis: 'stroke-purple-500', titik: 'fill-purple-500', swatch: 'bg-purple-500' },
};

const KIRI = 36;
const KANAN = 12;
const ATAS = 12;
const BAWAH = 28;
const JARAK_TITIK = 28;
const LEBAR_MIN = 360;

// Batas atas sumbu Y dibulatkan ke 1/2/5 × 10^k supaya garis bantu berangka bulat.
function batasAtas(maks) {
    if (maks <= 0) {
        return 1;
    }

    const pangkat = 10 ** Math.floor(Math.log10(maks));

    return [1, 2, 5, 10].map((k) => k * pangkat).find((b) => b >= maks);
}

const wadah = ref(null);
const lebarWadah = ref(0);
let pengamat = null;

onMounted(() => {
    const ukur = () => { lebarWadah.value = Math.floor(wadah.value?.clientWidth ?? 0); };

    ukur();

    if (typeof ResizeObserver !== 'undefined' && wadah.value) {
        pengamat = new ResizeObserver(ukur);
        pengamat.observe(wadah.value);
    }
});

onBeforeUnmount(() => pengamat?.disconnect());

const lebar = computed(() => Math.max(LEBAR_MIN, lebarWadah.value, KIRI + KANAN + props.labels.length * JARAK_TITIK));
const maks = computed(() => batasAtas(Math.max(0, ...props.series.flatMap((s) => s.values.map((v) => Number(v) || 0)))));
const x = (i) => KIRI + (props.labels.length > 1 ? (i / (props.labels.length - 1)) * (lebar.value - KIRI - KANAN) : (lebar.value - KIRI - KANAN) / 2);
const y = (v) => ATAS + props.height - ((Number(v) || 0) / maks.value) * props.height;

const garisBantu = computed(() => [0, 0.5, 1].map((p) => ({ nilai: maks.value * p, y: y(maks.value * p) })));

const gambar = computed(() => props.series.map((s) => ({
    key: s.key,
    label: s.label,
    warna: WARNA[s.warna] ?? WARNA.biru,
    garis: s.values.map((v, i) => `${x(i)},${y(v)}`).join(' '),
    titik: s.values.map((v, i) => ({ x: x(i), y: y(v), v: Number(v) || 0, label: props.labels[i] })),
})));

// Dengan banyak titik, label X dijarangkan supaya tidak bertumpuk.
const langkahLabel = computed(() => (props.labels.length > 16 ? 2 : 1));
</script>

<template>
    <div>
        <ul v-if="series.length > 1" class="m-0 mb-2 flex list-none flex-wrap gap-x-4 gap-y-1 p-0 text-xs text-gray-600" aria-label="Legenda grafik">
            <li v-for="g in gambar" :key="g.key" class="flex items-center gap-1.5">
                <span class="inline-block h-0.5 w-4 rounded-full" :class="g.warna.swatch"></span>{{ g.label }}
            </li>
        </ul>
        <div ref="wadah" class="overflow-x-auto">
            <svg v-if="labels.length" :width="lebar" :height="ATAS + height + BAWAH" role="img" :aria-label="ariaLabel">
                <g v-for="b in garisBantu" :key="b.nilai">
                    <line :x1="KIRI" :x2="lebar - KANAN" :y1="b.y" :y2="b.y" :class="b.nilai === 0 ? 'stroke-gray-300' : 'stroke-gray-200'" :stroke-dasharray="b.nilai === 0 ? null : 4" />
                    <text :x="KIRI - 6" :y="b.y + 3" text-anchor="end" font-size="10" class="fill-gray-500">{{ angkaRingkas(b.nilai) }}</text>
                </g>
                <text
                    v-for="(label, i) in labels" :key="`${label}-${i}`"
                    v-show="i % langkahLabel === 0"
                    :x="x(i)" :y="ATAS + height + 16" text-anchor="middle" font-size="9" class="fill-gray-500"
                >{{ label }}</text>
                <g v-for="g in gambar" :key="g.key">
                    <polyline :points="g.garis" fill="none" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" :class="g.warna.garis" />
                    <circle v-for="t in g.titik" :key="t.label" :cx="t.x" :cy="t.y" r="3" :class="g.warna.titik">
                        <title>{{ t.label }} · {{ g.label }}: {{ angkaRingkas(t.v) }}</title>
                    </circle>
                </g>
            </svg>
            <p v-else class="m-0 text-sm text-gray-500">Tidak ada data.</p>
        </div>
    </div>
</template>
