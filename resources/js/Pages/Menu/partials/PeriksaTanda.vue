<script setup>
import { ref } from 'vue';
import Button from '@/Components/ui/atoms/Button.vue';
import FieldError from '@/Components/ui/atoms/FieldError.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Radio from '@/Components/ui/atoms/Radio.vue';
import Textarea from '@/Components/ui/atoms/Textarea.vue';

// Form hasil cek koordinator atas tanda sistem (spec 062): Terbukti / Wajar + catatan wajib, dikirim PATCH ke endpoint
// periksa (TandaMotorisController / TandaOutletController). Dipakai di daftar tanda modal Riwayat, All Outlet, dan
// antrean Temuan Sistem di Beranda Koordinator.
const props = defineProps({
    // Endpoint lengkap, mis. /tracking_sales/tanda/5/periksa.
    url: { type: String, required: true },
    // Keterangan cakupan di atas form, mis. "Berlaku untuk 7 outlet di titik ini."; null = tanpa keterangan.
    keterangan: { type: String, default: null },
});

const emit = defineEmits(['selesai', 'batal']);

const status = ref('');
const catatan = ref('');
const menyimpan = ref(false);
const galat = ref({});
const pesan = ref(null);

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

async function simpan() {
    menyimpan.value = true;
    galat.value = {};
    pesan.value = null;

    try {
        const respon = await fetch(props.url, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ status: status.value, catatan: catatan.value }),
        });

        if (respon.status === 422) {
            galat.value = (await respon.json()).errors ?? {};

            return;
        }
        // 404: tanda sudah diperiksa orang lain (atau tidak lagi Perlu dicek).
        if (respon.status === 404) {
            pesan.value = 'Tanda ini sudah diperiksa. Muat ulang untuk melihat hasilnya.';

            return;
        }
        if (!respon.ok) throw new Error(`HTTP ${respon.status}`);

        emit('selesai', await respon.json());
    } catch {
        pesan.value = 'Gagal menyimpan hasil cek.';
    } finally {
        menyimpan.value = false;
    }
}
</script>

<template>
    <form class="tanda-motoris-form" aria-label="Hasil cek tanda" @submit.prevent="simpan">
        <p v-if="keterangan" class="mb-0 text-xs text-gray-500">{{ keterangan }}</p>
        <div>
            <Label>Hasil cek</Label>
            <div class="flex flex-wrap gap-4 text-sm">
                <label class="inline-flex items-center gap-1.5"><Radio v-model="status" value="terbukti" name="status-periksa" />Terbukti curang</label>
                <label class="inline-flex items-center gap-1.5"><Radio v-model="status" value="wajar" name="status-periksa" />Wajar</label>
            </div>
            <FieldError v-if="galat.status">{{ galat.status[0] }}</FieldError>
        </div>
        <div>
            <Label>Catatan hasil cek</Label>
            <Textarea v-model="catatan" maxlength="500" rows="3" aria-label="Catatan hasil cek" placeholder="Mis. sudah dicek ke lokasi, toko ada dan buka." />
            <FieldError v-if="galat.catatan">{{ galat.catatan[0] }}</FieldError>
        </div>
        <p v-if="pesan" class="mb-0 text-xs text-red-600">{{ pesan }}</p>
        <div class="flex gap-2">
            <Button type="submit" variant="primary" :loading="menyimpan" :disabled="!status || !catatan.trim()">Simpan hasil</Button>
            <Button type="button" variant="light" @click="emit('batal')">Batal</Button>
        </div>
    </form>
</template>
