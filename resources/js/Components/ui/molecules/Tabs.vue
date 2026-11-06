<script setup>
// Navigasi tab generik. Pemilihan tab dikirim lewat v-model (update:modelValue); pindah halaman diatur pemanggil.
// Item boleh punya `badge` (angka > 0 tampil sebagai lencana), mis. jumlah hal yang perlu perhatian di tab itu.
// `badgeVarian` mengganti warna lencana (bawaan danger), `badgeJudul` mengganti teks tooltip-nya.
defineProps({
    tabs: { type: Array, required: true },
    modelValue: { type: String, default: null },
    // true = jarak bawah kecil (mb-1), untuk tab di dalam panel padat seperti daftar kunjungan.
    rapat: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="overflow-x-auto overflow-y-hidden" :class="rapat ? 'mb-1' : 'mb-3'">
        <nav class="flex gap-2 min-w-max" role="tablist">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="tab.key === modelValue"
                class="tab-pill"
                :class="{ active: tab.key === modelValue }"
                @click="$emit('update:modelValue', tab.key)"
            >
                {{ tab.label }}
                <span v-if="tab.badge" class="badge ms-1" :class="`badge-${tab.badgeVarian ?? 'danger'}`" :title="tab.badgeJudul ?? `${tab.badge} perlu perhatian`">{{ tab.badge }}</span>
            </button>
        </nav>
    </div>
</template>
