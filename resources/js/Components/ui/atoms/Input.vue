<script setup>
const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    type: { type: String, default: 'text' },
    // Hanya digit: spasi, huruf, dan tanda lain langsung dibuang saat diketik atau ditempel (mis. nomor HP).
    hanyaAngka: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

function ubah(event) {
    const el = event.target;

    if (props.hanyaAngka) {
        el.value = el.value.replace(/\D/g, '');
    }

    emit('update:modelValue', el.value);
}
</script>

<template>
    <input :type="type" :value="modelValue" class="form-control form-control-sm" @input="ubah">
</template>
