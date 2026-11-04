<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/ui/atoms/Button.vue';
import DatePicker from '@/Components/ui/atoms/DatePicker.vue';
import FieldError from '@/Components/ui/atoms/FieldError.vue';
import Input from '@/Components/ui/atoms/Input.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Select from '@/Components/ui/atoms/Select.vue';
import Textarea from '@/Components/ui/atoms/Textarea.vue';
import Alert from '@/Components/ui/molecules/Alert.vue';
import Modal from '@/Components/ui/molecules/Modal.vue';

// Form Buat / Ubah Laporan Kendala (spec 064). `laporan` null = buat baru, terisi = ubah laporan itu.
const props = defineProps({
    show: { type: Boolean, default: false },
    laporan: { type: Object, default: null },
    pilihanKategori: { type: Object, required: true },
    hariIni: { type: String, required: true },
});

const emit = defineEmits(['close']);

const form = useForm({ kategori: '', tanggal_kejadian: props.hariIni, judul: '', uraian: '' });
const mengubah = computed(() => props.laporan !== null);

// Isi ulang setiap kali modal dibuka: nilai laporan yang diubah, atau isian kosong dengan tanggal hari ini.
watch(() => props.show, (terbuka) => {
    if (!terbuka) return;

    form.clearErrors();
    form.defaults({
        kategori: props.laporan?.kategori ?? '',
        tanggal_kejadian: props.laporan?.tanggal_kejadian ?? props.hariIni,
        judul: props.laporan?.judul ?? '',
        uraian: props.laporan?.uraian ?? '',
    }).reset();
});

function simpan() {
    const opsi = { preserveScroll: true, onSuccess: () => emit('close') };

    if (mengubah.value) form.put(`/laporan_kendala/${props.laporan.id}`, opsi);
    else form.post('/laporan_kendala', opsi);
}
</script>

<template>
    <Modal :show="show" :title="mengubah ? 'Ubah Laporan Kendala' : 'Buat Laporan Kendala'" @close="emit('close')">
        <form id="form-laporan-kendala" class="flex flex-col gap-3" @submit.prevent="simpan">
            <Alert v-if="form.errors.karyawan" variant="danger" role="alert">{{ form.errors.karyawan }}</Alert>

            <div>
                <Label for="lk_kategori">Kategori</Label>
                <Select id="lk_kategori" v-model="form.kategori" :options="pilihanKategori" placeholder="Pilih kategori" />
                <FieldError v-if="form.errors.kategori">{{ form.errors.kategori }}</FieldError>
            </div>
            <div>
                <Label for="lk_tanggal">Tanggal kejadian</Label>
                <DatePicker id="lk_tanggal" v-model="form.tanggal_kejadian" :max="hariIni" />
                <FieldError v-if="form.errors.tanggal_kejadian">{{ form.errors.tanggal_kejadian }}</FieldError>
            </div>
            <div>
                <Label for="lk_judul">Judul</Label>
                <Input id="lk_judul" v-model="form.judul" maxlength="120" placeholder="mis. Stok canvassing habis di depo" />
                <FieldError v-if="form.errors.judul">{{ form.errors.judul }}</FieldError>
            </div>
            <div>
                <Label for="lk_uraian">Uraian</Label>
                <Textarea id="lk_uraian" v-model="form.uraian" rows="5" placeholder="Jelaskan kendala, dampaknya ke operasional, dan perkembangannya" />
                <FieldError v-if="form.errors.uraian">{{ form.errors.uraian }}</FieldError>
            </div>
        </form>

        <template #footer>
            <Button variant="outline-secondary" type="button" @click="emit('close')">Batal</Button>
            <Button type="submit" form="form-laporan-kendala" variant="success" :loading="form.processing">{{ mengubah ? 'Simpan Perubahan' : 'Kirim Laporan' }}</Button>
        </template>
    </Modal>
</template>
