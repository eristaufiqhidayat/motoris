<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import FieldError from '@/Components/ui/atoms/FieldError.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Textarea from '@/Components/ui/atoms/Textarea.vue';
import Modal from '@/Components/ui/molecules/Modal.vue';
import Diskusi from './Diskusi.vue';
import { tanggalSingkat } from '@/Support/format.js';

// Detail Laporan Kendala (spec 064). Pelapor dengan laporan Terbuka (`laporan.bisa_ubah`) mendapat tombol Ubah dan
// Tandai Selesai (catatan penyelesaian wajib); Supervisor/Admin dan laporan Selesai hanya membaca.
const props = defineProps({
    laporan: { type: Object, default: null },
    // Pembaca laporan ini { nama, dibaca } (hanya diisi server untuk pelapor); null = belum dimuat.
    pembaca: { type: Array, default: null },
    // Komentar laporan ini (spec 065); null = belum dimuat.
    komentar: { type: Array, default: null },
});

const emit = defineEmits(['close', 'ubah', 'komentar']);

const VARIAN_STATUS = { terbuka: 'warning', selesai: 'success' };
const menutup = ref(false);
const membuka = ref(false);
const form = useForm({ catatan_selesai: '' });
const formBuka = useForm({ alasan: '' });

// Setiap laporan yang dibuka mulai dari tampilan baca, tanpa sisa isian catatan.
watch(() => props.laporan?.id, () => {
    menutup.value = false;
    membuka.value = false;
    form.reset();
    form.clearErrors();
    formBuka.reset();
    formBuka.clearErrors();
});

function tandaiSelesai() {
    form.patch(`/laporan_kendala/${props.laporan.id}/selesai`, { preserveScroll: true, onSuccess: () => emit('close') });
}

// Buka Kembali (spec 065): pelapor membuka lagi laporannya yang Selesai; alasan opsional masuk linimasa.
function bukaKembali() {
    formBuka.patch(`/laporan_kendala/${props.laporan.id}/buka-kembali`, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <Modal :show="laporan !== null" :title="laporan?.judul ?? 'Detail Laporan Kendala'" @close="emit('close')">
        <dl v-if="laporan" class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <Badge :variant="VARIAN_STATUS[laporan.status] ?? 'secondary'">{{ laporan.status_label }}</Badge>
                <Badge variant="info">{{ laporan.kategori_label }}</Badge>
            </div>
            <div>
                <dt class="text-gray-500">Pelapor</dt>
                <dd>{{ laporan.pelapor }}<template v-if="laporan.depo"> · {{ laporan.depo }}</template></dd>
            </div>
            <div>
                <dt class="text-gray-500">Tanggal kejadian</dt>
                <dd>{{ tanggalSingkat(laporan.tanggal_kejadian) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Uraian</dt>
                <dd class="whitespace-pre-line">{{ laporan.uraian }}</dd>
            </div>
            <div v-if="laporan.status === 'selesai'">
                <dt class="text-gray-500">Catatan penyelesaian<template v-if="laporan.selesai"> · {{ laporan.selesai }}</template></dt>
                <dd class="whitespace-pre-line">{{ laporan.catatan_selesai }}</dd>
            </div>

            <div v-if="pembaca?.length">
                <dt class="text-gray-500">Sudah dibaca oleh</dt>
                <dd>
                    <ul class="m-0 list-none p-0">
                        <li v-for="p in pembaca" :key="p.nama + p.dibaca">{{ p.nama }} · <span class="text-gray-500">{{ p.dibaca }}</span></li>
                    </ul>
                </dd>
            </div>

            <Diskusi v-if="komentar" :laporan-id="laporan.id" :komentar="komentar" @diperbarui="emit('komentar', $event)" />

            <form v-if="laporan.bisa_buka_kembali && membuka" id="form-buka-kendala" @submit.prevent="bukaKembali">
                <Label for="lk_alasan_buka">Alasan (opsional)</Label>
                <Textarea id="lk_alasan_buka" v-model="formBuka.alasan" rows="3" placeholder="Kenapa kendala ini muncul lagi?" />
                <FieldError v-if="formBuka.errors.alasan">{{ formBuka.errors.alasan }}</FieldError>
            </form>

            <form v-if="laporan.bisa_ubah && menutup" id="form-selesai-kendala" @submit.prevent="tandaiSelesai">
                <Label for="lk_catatan_selesai">Catatan penyelesaian</Label>
                <Textarea id="lk_catatan_selesai" v-model="form.catatan_selesai" rows="4" placeholder="Bagaimana kendala ini teratasi?" />
                <FieldError v-if="form.errors.catatan_selesai">{{ form.errors.catatan_selesai }}</FieldError>
            </form>
        </dl>

        <template v-if="laporan?.bisa_buka_kembali" #footer>
            <template v-if="membuka">
                <Button variant="outline-secondary" type="button" @click="membuka = false">Batal</Button>
                <Button type="submit" form="form-buka-kendala" variant="success" :loading="formBuka.processing">Buka Kembali</Button>
            </template>
            <Button v-else variant="outline-secondary" type="button" @click="membuka = true">Buka Kembali</Button>
        </template>
        <template v-else-if="laporan?.bisa_ubah" #footer>
            <template v-if="menutup">
                <Button variant="outline-secondary" type="button" @click="menutup = false">Batal</Button>
                <Button type="submit" form="form-selesai-kendala" variant="success" :loading="form.processing">Simpan Selesai</Button>
            </template>
            <template v-else>
                <Button variant="outline-secondary" type="button" @click="emit('ubah', laporan)">Ubah</Button>
                <Button variant="success" type="button" @click="menutup = true">Tandai Selesai</Button>
            </template>
        </template>
    </Modal>
</template>
