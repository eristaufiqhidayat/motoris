<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import FieldError from '@/Components/ui/atoms/FieldError.vue';
import Input from '@/Components/ui/atoms/Input.vue';
import InputRibuan from '@/Components/ui/atoms/InputRibuan.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Combobox from '@/Components/ui/molecules/Combobox.vue';
import Modal from '@/Components/ui/molecules/Modal.vue';
import { rupiah } from '@/Support/format.js';

// Isi drawer satu orang di detail penggajian: rincian komponen dengan asal angkanya; pada draf juga isian hari izin
// dan komponen manual (tambah, ubah, hapus).
const props = defineProps({
    orang: { type: Object, required: true },
    // 'gaji' atau 'insentif'; insentif tidak punya hari kerja, hadir, izin, dan terlambat.
    kategori: { type: String, default: 'gaji' },
    // URL dasar penggajian ini, mis. /penggajian_sales/12.
    base: { type: String, required: true },
    pemotong: { type: Array, default: () => [] },
    // Draf dan akun punya hak ubah.
    bolehUbah: { type: Boolean, default: false },
});

const urlOrang = computed(() => `${props.base}/detail/${props.orang.id}`);

// Asal angka satu komponen, mis. "Rp 540.000 × (18 hadir + 1 izin) ÷ 27 hari kerja".
function asal(komponen) {
    const r = komponen.rincian;

    if (!r) return komponen.keterangan ?? '';
    if (r.total_ec !== undefined) {
        const t = r.tingkat;

        return t
            ? `${r.total_ec} EC sebulan → rentang ${t.bawah}–${t.atas ?? 'ke atas'} EC = ${rupiah(t.insentif)}`
            : `${r.total_ec} EC sebulan, tidak ada rentang rumus yang cocok`;
    }

    if (r.total_poin !== undefined) return `${r.total_poin} poin × ${rupiah(r.rupiah_per_poin)} per poin`;
    if (r.hari_dipotong !== undefined) {
        const perBulan = r.per_bulan ? ` (${Object.entries(r.per_bulan).map(([bulan, n]) => `${bulan}: ${n}`).join(', ')})` : '';

        return `${r.hari_terlambat} kali terlambat${perBulan}, toleransi ${r.toleransi_per_bulan} per bulan → ${r.hari_dipotong} hari × (${rupiah(r.nilai_bulanan)} ÷ ${r.hari_kerja} hari kerja)`;
    }

    if (r.hari_kerja !== undefined) {
        return `${rupiah(r.nilai_bulanan)} × (${r.hari_hadir} hadir + ${r.hari_izin} izin) ÷ ${r.hari_kerja} hari kerja`;
    }

    return `${rupiah(r.nilai_bulanan)} per bulan, dibayar penuh`;
}

const opsiPemotong = computed(() => props.pemotong.map((p) => ({ value: p.id, label: p.nama })));
const OPSI_TIPE = { potongan: 'Potongan', pendapatan: 'Pendapatan' };

// Hari izin.
const formIzin = useForm({ hari_izin: String(props.orang.hari_izin ?? 0) });
const simpanIzin = () => formIzin.put(`${urlOrang.value}/izin`, { preserveScroll: true });

// Komponen manual: satu form dipakai untuk tambah dan ubah (komponenDiubah = id yang sedang diubah, null = tambah).
const kosong = () => ({ tipe: 'potongan', parameter_pemotong_id: '', nama: '', nominal: '', keterangan: '' });
const formKomponen = useForm(kosong());
const komponenDiubah = ref(null);

function ubah(k) {
    komponenDiubah.value = k.id;
    formKomponen.clearErrors();
    formKomponen.defaults({ tipe: k.tipe, parameter_pemotong_id: k.parameter_pemotong_id ?? '', nama: k.tipe === 'pendapatan' ? k.nama : '', nominal: String(k.nominal), keterangan: k.keterangan ?? '' }).reset();
}

function batalUbah() {
    komponenDiubah.value = null;
    formKomponen.defaults(kosong()).reset();
    formKomponen.clearErrors();
}

function simpanKomponen() {
    const opsi = { preserveScroll: true, onSuccess: batalUbah };

    if (komponenDiubah.value) {
        formKomponen.put(`${urlOrang.value}/komponen/${komponenDiubah.value}`, opsi);
    } else {
        formKomponen.post(`${urlOrang.value}/komponen`, opsi);
    }
}

// Hapus komponen manual dikonfirmasi lewat Modal (bukan dialog bawaan browser). hapusKomponen = komponen yang akan dihapus.
const hapusKomponen = ref(null);
const menghapus = ref(false);

function hapus() {
    menghapus.value = true;
    useForm({}).delete(`${urlOrang.value}/komponen/${hapusKomponen.value.id}`, {
        preserveScroll: true,
        onFinish: () => { menghapus.value = false; hapusKomponen.value = null; },
    });
}
</script>

<template>
    <p v-if="kategori === 'gaji'" class="text-sm text-gray-500">
        {{ orang.hari_kerja }} hari kerja · {{ orang.hari_hadir }} hadir · {{ orang.hari_tidak_hadir }} tidak hadir · {{ orang.hari_terlambat }} terlambat · {{ orang.hari_izin }} izin
    </p>

    <div class="overflow-x-auto">
    <table class="data-table">
        <thead>
            <tr>
                <th scope="col">Komponen</th>
                <th scope="col">Asal angka</th>
                <th scope="col" class="text-end">Nominal</th>
                <th v-if="bolehUbah" scope="col"><span class="sr-only">Aksi</span></th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="k in orang.komponen" :key="k.id">
                <td>{{ k.nama }}<Badge v-if="k.sumber === 'manual'" class="ms-1" variant="primary">Manual</Badge></td>
                <td class="text-gray-500">{{ asal(k) }}</td>
                <td class="text-end">{{ k.tipe === 'potongan' ? '−' : '' }}{{ rupiah(k.nominal) }}</td>
                <td v-if="bolehUbah" class="text-end whitespace-nowrap">
                    <template v-if="k.sumber === 'manual'">
                        <Button variant="outline-secondary" @click="ubah(k)">Ubah</Button>
                        <Button variant="outline-danger" class="ms-1" @click="hapusKomponen = k"><Icon name="trash" class="me-1 w-3.5 h-3.5" />Hapus</Button>
                    </template>
                </td>
            </tr>
            <tr v-if="!orang.komponen.length">
                <td :colspan="bolehUbah ? 4 : 3" class="text-center text-gray-500 py-4">{{ kategori === 'gaji' ? 'Belum ada komponen. Isi gaji dan tunjangan di data karyawan, lalu Hitung Ulang.' : 'Belum ada komponen.' }}</td>
            </tr>
        </tbody>
        <tfoot>
            <tr><th colspan="2">Total pendapatan</th><th class="text-end">{{ rupiah(orang.total_pendapatan) }}</th><th v-if="bolehUbah" /></tr>
            <tr><th colspan="2">Total potongan</th><th class="text-end">{{ rupiah(orang.total_potongan) }}</th><th v-if="bolehUbah" /></tr>
            <tr><th colspan="2">Total bersih</th><th class="text-end">{{ rupiah(orang.total_bersih) }}</th><th v-if="bolehUbah" /></tr>
        </tfoot>
    </table>
    </div>

    <template v-if="bolehUbah">
        <form v-if="kategori === 'gaji'" class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="simpanIzin">
            <div>
                <Label for="hari_izin">Hari izin (dihitung hadir untuk tunjangan prorata) <span class="text-red-600">*</span></Label>
                <InputRibuan id="hari_izin" v-model="formIzin.hari_izin" class="w-24" />
                <FieldError v-if="formIzin.errors.hari_izin">{{ formIzin.errors.hari_izin }}</FieldError>
            </div>
            <Button type="submit" variant="outline-secondary" :loading="formIzin.processing"><Icon name="check" class="me-1 w-3.5 h-3.5" />Simpan izin</Button>
        </form>

        <form class="mt-4 flex flex-col gap-2" @submit.prevent="simpanKomponen">
            <h6 class="mb-0 font-semibold">{{ komponenDiubah ? 'Ubah komponen manual' : 'Tambah komponen manual' }}</h6>
            <div>
                <Label for="tipe">Jenis <span class="text-red-600">*</span></Label>
                <Combobox id="tipe" v-model="formKomponen.tipe" :options="OPSI_TIPE" placeholder="Pilih jenis..." :clearable="false" />
                <FieldError v-if="formKomponen.errors.tipe">{{ formKomponen.errors.tipe }}</FieldError>
            </div>
            <div v-if="formKomponen.tipe === 'potongan'">
                <Label for="parameter_pemotong_id">Jenis potongan <span class="text-red-600">*</span></Label>
                <Combobox id="parameter_pemotong_id" v-model="formKomponen.parameter_pemotong_id" :options="opsiPemotong" placeholder="Pilih dari Parameter Pemotong Gaji..." :clearable="false" />
                <FieldError v-if="formKomponen.errors.parameter_pemotong_id">{{ formKomponen.errors.parameter_pemotong_id }}</FieldError>
            </div>
            <div v-else>
                <Label for="nama">Nama komponen <span class="text-red-600">*</span></Label>
                <Input id="nama" v-model="formKomponen.nama" placeholder="mis. Bonus target" />
                <FieldError v-if="formKomponen.errors.nama">{{ formKomponen.errors.nama }}</FieldError>
            </div>
            <div>
                <Label for="nominal">Nominal (Rp) <span class="text-red-600">*</span></Label>
                <InputRibuan id="nominal" v-model="formKomponen.nominal" />
                <FieldError v-if="formKomponen.errors.nominal">{{ formKomponen.errors.nominal }}</FieldError>
            </div>
            <div>
                <Label for="keterangan">Keterangan (opsional)</Label>
                <Input id="keterangan" v-model="formKomponen.keterangan" />
                <FieldError v-if="formKomponen.errors.keterangan">{{ formKomponen.errors.keterangan }}</FieldError>
            </div>
            <div class="flex gap-2">
                <Button type="submit" :variant="komponenDiubah ? 'primary' : 'success'" :loading="formKomponen.processing">
                    <Icon :name="komponenDiubah ? 'check' : 'plus'" class="me-1 w-3.5 h-3.5" />{{ komponenDiubah ? 'Simpan' : 'Tambah' }}
                </Button>
                <Button v-if="komponenDiubah" variant="outline-secondary" @click="batalUbah">Batal</Button>
            </div>
        </form>
    </template>

    <Modal :show="hapusKomponen !== null" title="Hapus komponen" @close="hapusKomponen = null">
        <p class="mb-0">Hapus komponen <b>{{ hapusKomponen?.nama }}</b> ({{ hapusKomponen ? rupiah(hapusKomponen.nominal) : '' }})? Total orang ini dihitung ulang.</p>
        <template #footer>
            <Button variant="light" @click="hapusKomponen = null">Batal</Button>
            <Button variant="danger" :loading="menghapus" @click="hapus"><Icon name="trash" class="me-1 w-3.5 h-3.5" />Hapus</Button>
        </template>
    </Modal>
</template>
