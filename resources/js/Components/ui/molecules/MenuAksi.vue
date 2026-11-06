<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import Button from '@/Components/ui/atoms/Button.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';

// Tombol dengan submenu aksi (dropdown), mis. "Aksi" di halaman detail: satu pemicu, banyak perintah berurutan.
// items: [{ kunci, label, icon?, variant?: 'bahaya', disabled?, alasan?, href?, pisah? }] dalam urutan tampil.
//  - disabled + alasan: item tetap tampil (supaya perintahnya dikenali) tetapi tidak bisa dipilih; alasan jadi tooltip.
//  - href: item berupa tautan biasa (mis. unduhan berkas), tidak memancarkan 'pilih'.
//  - pisah: garis pemisah sebelum item (mis. untuk perintah berbahaya).
// Menutup saat klik di luar, Esc (fokus kembali ke pemicu), atau setelah memilih; panah atas/bawah memindah fokus antar item aktif.
const props = defineProps({
    label: { type: String, default: 'Aksi' },
    items: { type: Array, required: true },
    variant: { type: String, default: 'outline-secondary' },
});

const emit = defineEmits(['pilih']);

const terbuka = ref(false);
const akar = ref(null);

const pemicu = () => akar.value?.querySelector('button');
const itemAktif = () => [...(akar.value?.querySelectorAll('[role="menuitem"]:not([aria-disabled="true"])') ?? [])];

function tutup(kembaliKePemicu = false) {
    terbuka.value = false;

    if (kembaliKePemicu) {
        nextTick(() => pemicu()?.focus());
    }
}

function pilih(item) {
    if (item.disabled) {
        return;
    }

    terbuka.value = false;

    if (!item.href) {
        emit('pilih', item.kunci);
    }
}

function geser(arah) {
    const daftar = itemAktif();

    if (!daftar.length) {
        return;
    }

    const sekarang = daftar.indexOf(document.activeElement);
    daftar[(sekarang + arah + daftar.length) % daftar.length].focus();
}

function tombol(e) {
    if (e.key === 'Escape') {
        tutup(true);
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        geser(1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        geser(-1);
    }
}

function klikLuar(e) {
    if (akar.value && !akar.value.contains(e.target)) {
        terbuka.value = false;
    }
}

onMounted(() => document.addEventListener('click', klikLuar));
onBeforeUnmount(() => document.removeEventListener('click', klikLuar));
</script>

<template>
    <div v-if="items.length" ref="akar" class="menu-aksi">
        <Button :variant="variant" aria-haspopup="menu" :aria-expanded="terbuka ? 'true' : 'false'" @click="terbuka = !terbuka">
            {{ label }}<Icon name="chevron-down" class="ms-1 w-3.5 h-3.5" />
        </Button>

        <div v-if="terbuka" class="menu-aksi-panel" role="menu" :aria-label="label" @keydown="tombol">
            <template v-for="item in items" :key="item.kunci">
                <div v-if="item.pisah" class="menu-aksi-pemisah" role="separator"></div>
                <a
                    v-if="item.href && !item.disabled"
                    :href="item.href"
                    role="menuitem"
                    class="menu-aksi-item"
                    :class="{ 'menu-aksi-item-bahaya': item.variant === 'bahaya' }"
                    @click="pilih(item)"
                >
                    <Icon v-if="item.icon" :name="item.icon" />{{ item.label }}
                </a>
                <button
                    v-else
                    type="button"
                    role="menuitem"
                    class="menu-aksi-item"
                    :class="{ 'menu-aksi-item-bahaya': item.variant === 'bahaya', 'menu-aksi-item-nonaktif': item.disabled }"
                    :aria-disabled="item.disabled ? 'true' : null"
                    :title="item.disabled ? item.alasan : null"
                    @click="pilih(item)"
                >
                    <Icon v-if="item.icon" :name="item.icon" />{{ item.label }}
                </button>
            </template>
        </div>
    </div>
</template>
