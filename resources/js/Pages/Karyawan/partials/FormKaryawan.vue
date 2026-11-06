<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useSlots, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/ui/atoms/Button.vue';
import FieldError from '@/Components/ui/atoms/FieldError.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import Input from '@/Components/ui/atoms/Input.vue';
import DatePicker from '@/Components/ui/atoms/DatePicker.vue';
import InputRibuan from '@/Components/ui/atoms/InputRibuan.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Select from '@/Components/ui/atoms/Select.vue';
import Textarea from '@/Components/ui/atoms/Textarea.vue';
import Alert from '@/Components/ui/molecules/Alert.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import Combobox from '@/Components/ui/molecules/Combobox.vue';
import Tabs from '@/Components/ui/molecules/Tabs.vue';

// Isi form tambah/edit karyawan (Sales, Koordinator, Supervisor, Lainnya), dipakai halaman Form.
// Field per kategori mengikuti Marketama. Pilihan koordinator/area (sales) dan wilayah depo (koordinator) dimuat
// ulang dari /{menu}/opsi saat depo diganti. Isian dibagi per tab dan semuanya tersimpan sekali klik. Validasi wajib isi
// sengaja di server (novalidate): field wajib di tab tersembunyi tidak bisa difokuskan browser, Simpan jadi terkesan diam.
// Slot `penempatan` (mis. Pindah Area) dan `riwayat` ada di luar <form> karena isinya boleh punya form sendiri.
const props = defineProps({
    slug: { type: String, required: true },
    kategori: { type: String, required: true },
    nama: { type: String, required: true },
    item: { type: Object, default: null },
    nilai: { type: Object, required: true },
    opsi: { type: Object, required: true },
    bisa: { type: Object, required: true },
});

const base = `/${props.slug}`;
const bolehSimpan = computed(() => (props.item ? props.bisa.ubah : props.bisa.tambah));
const pakaiDepo = computed(() => ['sales', 'koordinator'].includes(props.kategori));
const wajibKontak = computed(() => props.kategori !== 'lainnya');

const GAJI = [
    ['gaji_pokok', 'Gaji pokok'],
    ['tunjangan_bbm_makan', 'Tunjangan BBM & makan'],
    ['sewa_motor', 'Sewa motor'],
    ['tunjangan_internet', 'Tunjangan kuota internet'],
    ['tunjangan_jabatan', 'Tunjangan jabatan'],
];

const akarForm = ref(null);
const form = useForm({ ...props.nilai, foto: null });

const slots = useSlots();
const tab = ref('data_diri');

// Field per tab, untuk lencana galat. Galat elemen larik berkunci "area_ids.0", dipetakan lewat bagian sebelum titik.
const FIELD_TAB = {
    data_diri: ['nama', 'nik', 'status_ptkp', 'tempat_lahir', 'tanggal_lahir', 'alamat', 'foto'],
    penempatan: ['jabatan', 'depo_id', 'koordinator_id', 'wilayah_depo_id', 'area_ids', 'area_aktif_ids'],
    kontak: ['no_hp', 'email', 'tanggal_mulai', 'aktif'],
    gaji: GAJI.map(([kolom]) => kolom),
};

const galatTab = (kunci) => Object.keys(form.errors).filter((k) => FIELD_TAB[kunci].includes(k.split('.')[0])).length;

// Isian wajib yang masih kosong per tab, supaya tab yang belum lengkap terlihat sebelum menekan Simpan.
function kosongWajib(kunci) {
    const penempatan = [];
    if (pakaiDepo.value) penempatan.push(form.depo_id);
    if (props.kategori === 'sales') penempatan.push(form.koordinator_id, form.area_ids.length ? '1' : '');
    if (props.kategori === 'lainnya') penempatan.push(form.jabatan);

    const wajib = {
        data_diri: [form.nama],
        penempatan,
        kontak: [form.aktif, ...(wajibKontak.value ? [form.no_hp, form.tanggal_mulai] : [])],
        gaji: [],
    }[kunci];

    return wajib.filter((nilai) => !String(nilai ?? '').trim()).length;
}

function itemTab(key, label) {
    const galat = galatTab(key);
    const kosong = kosongWajib(key);

    if (galat) return { key, label, badge: galat, badgeJudul: `${galat} isian perlu diperbaiki` };

    return { key, label, badge: kosong, badgeVarian: 'warning', badgeJudul: `${kosong} isian wajib belum diisi` };
}

const daftarTab = computed(() => [
    itemTab('data_diri', 'Data diri'),
    ...(pakaiDepo.value || props.kategori === 'lainnya' ? [itemTab('penempatan', 'Penempatan')] : []),
    itemTab('kontak', 'Kontak & kepegawaian'),
    itemTab('gaji', 'Gaji & tunjangan'),
    ...(slots.riwayat ? [{ key: 'riwayat', label: 'Riwayat' }] : []),
]);
const pilihan = ref({ koordinator: props.opsi.koordinator ?? [], area: props.opsi.area ?? [], wilayah: props.opsi.wilayah ?? [] });
const memuatOpsi = ref(false);
const pratinjau = ref(null);

// Baca KTP otomatis (OCR): foto KTP cuma dipakai sebentar untuk dibaca lewat /{menu}/baca-ktp, tidak diikutkan
// ke form simpan karyawan dan tidak disimpan di server. Field hasil bacaan tetap bisa diedit sebelum disimpan.
const fotoKtp = ref(null);
const pratinjauKtp = ref(null);
const memindaiKtp = ref(false);
const statusScan = ref(null); // null | 'lengkap' | 'sebagian' | 'gagal'
const teksMentahKtp = ref('');
const tampilkanTeksMentah = ref(false);
const fieldDariKtp = ref(new Set());

function pilihFotoKtp(e) {
    const file = e.target.files?.[0] ?? null;
    fotoKtp.value = file;
    pratinjauKtp.value = file ? URL.createObjectURL(file) : null;
    statusScan.value = null;
}

// Ambil foto KTP lewat kamera komputer, sebagai alternatif unggah file. Hasil jepretan dipakai
// persis seperti file yang diunggah (dikirim ke endpoint baca-ktp yang sama), bukan jalur terpisah.
const sumberFotoKtp = ref('unggah'); // 'unggah' | 'kamera'
const videoKtp = ref(null);
const kameraAktif = ref(false);
const kameraError = ref('');
let streamKtp = null;

function matikanKamera() {
    streamKtp?.getTracks().forEach((track) => track.stop());
    streamKtp = null;
    kameraAktif.value = false;
}

async function aktifkanKamera() {
    kameraError.value = '';

    if (!navigator.mediaDevices?.getUserMedia) {
        kameraError.value = 'Kamera tidak didukung di browser ini, atau halaman tidak diakses lewat HTTPS.';
        return;
    }

    try {
        // Minta resolusi setinggi mungkin ("ideal", bukan wajib) — tanpa ini kebanyakan webcam
        // default ke 640x480, yang bikin teks di KTP terlalu buram buat dibaca OCR.
        streamKtp = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } },
        });
        kameraAktif.value = true;
        await nextTick();
        if (videoKtp.value) videoKtp.value.srcObject = streamKtp;
    } catch {
        kameraError.value = 'Tidak bisa mengakses kamera. Pastikan izin kamera diizinkan di browser.';
    }
}

function jepretKtp() {
    const video = videoKtp.value;
    if (!video) return;

    const kanvas = document.createElement('canvas');
    kanvas.width = video.videoWidth;
    kanvas.height = video.videoHeight;
    kanvas.getContext('2d').drawImage(video, 0, 0);

    kanvas.toBlob((blob) => {
        if (!blob) return;
        const file = new File([blob], 'ktp-kamera.jpg', { type: 'image/jpeg' });
        fotoKtp.value = file;
        pratinjauKtp.value = URL.createObjectURL(blob);
        statusScan.value = null;
        matikanKamera();
    }, 'image/jpeg', 0.92);
}

// Panel KTP dilipat: kamera jangan tetap menyala tanpa terlihat.
function lipatKtp(e) {
    if (!e.target.open) matikanKamera();
}

watch(tab, matikanKamera);

watch(sumberFotoKtp, (sumber, sebelum) => {
    if (sebelum === 'kamera') matikanKamera();
});

onBeforeUnmount(matikanKamera);

async function bacaKtp() {
    if (!fotoKtp.value) return;
    memindaiKtp.value = true;
    statusScan.value = null;

    const data = new FormData();
    data.append('foto_ktp', fotoKtp.value);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

    try {
        const respons = await fetch(`${base}/baca-ktp`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: data,
        });

        if (!respons.ok) {
            statusScan.value = 'gagal';
            return;
        }

        const hasil = await respons.json();
        teksMentahKtp.value = hasil.teks_mentah ?? '';
        const terisi = new Set();

        [['nik', hasil.nik], ['nama', hasil.nama], ['tempat_lahir', hasil.tempat_lahir], ['tanggal_lahir', hasil.tanggal_lahir], ['alamat', hasil.alamat]]
            .forEach(([kolom, nilai]) => {
                if (nilai) {
                    form[kolom] = nilai;
                    terisi.add(kolom);
                }
            });
        fieldDariKtp.value = terisi;
        statusScan.value = (hasil.nik && hasil.nama && hasil.alamat) ? 'lengkap' : 'sebagian';
    } catch {
        statusScan.value = 'gagal';
    } finally {
        memindaiKtp.value = false;
    }
}

// Ganti depo: muat ulang pilihan dan kosongkan isian yang bergantung pada depo lama.
watch(() => form.depo_id, async (depo, sebelum) => {
    if (depo === sebelum) return;
    form.koordinator_id = '';
    form.wilayah_depo_id = '';
    form.area_ids = [];
    form.area_aktif_ids = [];

    if (!depo) {
        pilihan.value = { koordinator: [], area: [], wilayah: [] };
        return;
    }

    memuatOpsi.value = true;
    try {
        const respons = await fetch(`${base}/opsi?depo=${encodeURIComponent(depo)}`, { headers: { Accept: 'application/json' } });
        pilihan.value = await respons.json();
    } finally {
        memuatOpsi.value = false;
    }
});

// Area aktif hanya boleh dari area yang dipilih.
const opsiAreaAktif = computed(() => pilihan.value.area.filter((a) => form.area_ids.includes(a.value)));
watch(() => form.area_ids, (area) => {
    form.area_aktif_ids = form.area_aktif_ids.filter((a) => area.includes(a));
}, { deep: true });

function pilihFoto(e) {
    const file = e.target.files?.[0] ?? null;
    form.foto = file;
    pratinjau.value = file ? URL.createObjectURL(file) : null;
}

// Galat bisa ada di tab yang sedang tertutup: tetap di tab ini bila ia punya galat, kalau tidak buka tab pertama
// yang bermasalah, lalu gulir ke galat yang terlihat supaya Simpan tidak terkesan tidak berbuat apa-apa.
async function tampilkanGalatPertama() {
    if (!(tab.value in FIELD_TAB) || !galatTab(tab.value)) {
        tab.value = Object.keys(FIELD_TAB).find(galatTab) ?? tab.value;
    }

    await nextTick();
    [...(akarForm.value?.querySelectorAll('[data-field-error]') ?? [])].find((el) => el.offsetParent)
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function simpan() {
    const opsiKirim = { forceFormData: true, preserveScroll: true, onError: tampilkanGalatPertama };

    if (props.item) {
        form.transform((d) => ({ ...d, _method: 'put' })).post(`${base}/${props.item.id}`, opsiKirim);
    } else {
        form.post(base, opsiKirim);
    }
}
</script>

<template>
    <div class="max-w-4xl">
        <Tabs v-model="tab" :tabs="daftarTab" />

        <form id="form-karyawan" ref="akarForm" novalidate @submit.prevent="simpan">
            <fieldset :disabled="!bolehSimpan" class="contents">
                <div class="flex flex-col gap-3">
                    <!-- Saat edit data biasanya sudah terisi, jadi baca KTP dilipat; saat tambah langsung terbuka. -->
                    <details v-if="kategori === 'sales'" v-show="tab === 'data_diri'" class="panel-lipat" :open="!item" @toggle="lipatKtp">
                        <summary>
                            <Icon name="chevron-right" class="panel-lipat-panah" />
                            Isi otomatis dari foto KTP
                        </summary>
                        <div class="panel-lipat-isi">
                            <p class="text-gray-500 mb-3">Mengisi NIK, nama, tempat/tanggal lahir, dan alamat. Foto tidak disimpan, dan hasilnya tetap bisa diedit.</p>
                            <div class="flex flex-wrap items-start gap-3">
                                <span class="foto-produk">
                                    <img v-if="pratinjauKtp" :src="pratinjauKtp" alt="Pratinjau KTP">
                                    <template v-else>Belum ada foto</template>
                                </span>
                                <div class="min-w-0 flex-1 basis-60">
                                    <Tabs
                                        :tabs="[{ key: 'unggah', label: 'Unggah file' }, { key: 'kamera', label: 'Pakai kamera' }]"
                                        v-model="sumberFotoKtp"
                                    />

                                    <template v-if="sumberFotoKtp === 'unggah'">
                                        <Label for="foto_ktp">Foto KTP</Label>
                                        <Input id="foto_ktp" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" @change="pilihFotoKtp" />
                                        <small class="block text-gray-500 mt-1">.jpg, .jpeg, .png, maksimal 5 MB. Pastikan terang, rata, dan tidak terpotong.</small>
                                    </template>

                                    <template v-else>
                                        <template v-if="kameraAktif">
                                            <video ref="videoKtp" autoplay playsinline class="w-full max-w-xs rounded border border-gray-300"></video>
                                            <div class="flex gap-2 mt-2">
                                                <Button type="button" @click="jepretKtp">Ambil foto</Button>
                                                <Button type="button" variant="light" @click="matikanKamera">Batal</Button>
                                            </div>
                                        </template>
                                        <template v-else>
                                            <Button type="button" @click="aktifkanKamera">Aktifkan kamera</Button>
                                            <small class="block text-gray-500 mt-1">Dekatkan KTP ke kamera sampai tulisannya memenuhi sebagian besar layar, pastikan terang dan tidak buram, lalu klik Ambil foto.</small>
                                            <Alert v-if="kameraError" variant="danger" class="mt-2">{{ kameraError }}</Alert>
                                        </template>
                                    </template>

                                    <Button type="button" class="mt-2" :loading="memindaiKtp" :disabled="!fotoKtp || memindaiKtp" @click="bacaKtp">
                                        Baca KTP
                                    </Button>

                                    <Alert v-if="statusScan === 'lengkap'" variant="success" class="mt-3">NIK, nama, dan alamat terbaca — cek sekali lagi di bawah sebelum disimpan.</Alert>
                                    <Alert v-if="statusScan === 'sebagian'" variant="warning" class="mt-3">Sebagian data tidak terbaca jelas, lengkapi manual pada field yang masih kosong.</Alert>
                                    <Alert v-if="statusScan === 'gagal'" variant="danger" class="mt-3">Foto KTP tidak bisa dibaca. Coba foto ulang dengan cahaya lebih terang dan tidak miring.</Alert>

                                    <button v-if="teksMentahKtp" type="button" class="text-sky-600 underline mt-2 block" @click="tampilkanTeksMentah = !tampilkanTeksMentah">
                                        {{ tampilkanTeksMentah ? 'Sembunyikan' : 'Lihat' }} teks mentah hasil OCR
                                    </button>
                                    <pre v-if="tampilkanTeksMentah" class="bg-gray-900 text-green-200 text-xs p-3 rounded mt-2 max-h-40 overflow-auto whitespace-pre-wrap">{{ teksMentahKtp }}</pre>
                                </div>
                            </div>
                        </div>
                    </details>

                    <Card v-show="tab === 'data_diri'">
                        <div class="flex flex-wrap items-start gap-3 mb-3">
                            <div class="flex w-24 shrink-0 flex-col gap-2">
                                <span class="foto-produk">
                                    <img v-if="pratinjau || item?.foto" :src="pratinjau ?? item.foto" :alt="`Foto ${item?.nama ?? nama}`">
                                    <template v-else>Belum ada foto</template>
                                </span>
                                <template v-if="bolehSimpan">
                                    <input id="foto" type="file" class="peer sr-only" accept=".jpg,.jpeg,.png,image/jpeg,image/png" @change="pilihFoto">
                                    <Button tag="label" for="foto" variant="outline-secondary" class="cursor-pointer justify-center peer-focus-visible:ring-2">
                                        {{ pratinjau || item?.foto ? 'Ganti foto' : 'Unggah foto' }}
                                    </Button>
                                </template>
                            </div>
                            <div class="grid min-w-0 flex-1 basis-64 grid-cols-1 gap-3 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <Label for="nama">Nama lengkap <span class="text-red-600">*</span></Label>
                                    <Input id="nama" v-model="form.nama" required maxlength="150" />
                                    <FieldError v-if="form.errors.nama">{{ form.errors.nama }}</FieldError>
                                </div>
                                <div>
                                    <Label for="nik">NIK{{ fieldDariKtp.has('nik') ? ' (dari KTP)' : '' }}</Label>
                                    <Input id="nik" v-model="form.nik" inputmode="numeric" maxlength="16" placeholder="16 digit sesuai KTP" />
                                    <FieldError v-if="form.errors.nik">{{ form.errors.nik }}</FieldError>
                                </div>
                                <div>
                                    <Label for="status_ptkp">Status keluarga (PTKP)</Label>
                                    <Combobox id="status_ptkp" v-model="form.status_ptkp" :options="opsi.ptkp" placeholder="Pilih status keluarga" clearable />
                                    <small class="block text-gray-500 mt-1">Untuk PPh 21 dan BPJS.</small>
                                    <FieldError v-if="form.errors.status_ptkp">{{ form.errors.status_ptkp }}</FieldError>
                                </div>
                            </div>
                        </div>
                        <FieldError v-if="form.errors.foto" class="mb-3">{{ form.errors.foto }}</FieldError>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <Label for="tempat_lahir">Tempat lahir{{ fieldDariKtp.has('tempat_lahir') ? ' (dari KTP)' : '' }}</Label>
                                <Input id="tempat_lahir" v-model="form.tempat_lahir" maxlength="100" />
                                <FieldError v-if="form.errors.tempat_lahir">{{ form.errors.tempat_lahir }}</FieldError>
                            </div>
                            <div>
                                <Label for="tanggal_lahir">Tanggal lahir{{ fieldDariKtp.has('tanggal_lahir') ? ' (dari KTP)' : '' }}</Label>
                                <DatePicker id="tanggal_lahir" v-model="form.tanggal_lahir" />
                                <FieldError v-if="form.errors.tanggal_lahir">{{ form.errors.tanggal_lahir }}</FieldError>
                            </div>
                            <div class="sm:col-span-2">
                                <Label for="alamat">Alamat{{ fieldDariKtp.has('alamat') ? ' (dari KTP)' : '' }}</Label>
                                <Textarea id="alamat" v-model="form.alamat" maxlength="500" />
                                <FieldError v-if="form.errors.alamat">{{ form.errors.alamat }}</FieldError>
                            </div>
                        </div>
                    </Card>

                    <Card v-if="pakaiDepo || kategori === 'lainnya'" v-show="tab === 'penempatan'">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div v-if="kategori === 'lainnya'">
                                <Label for="jabatan">Kategori karyawan <span class="text-red-600">*</span></Label>
                                <Select id="jabatan" v-model="form.jabatan" :options="opsi.jabatan" placeholder="-- Pilih kategori --" required />
                                <FieldError v-if="form.errors.jabatan">{{ form.errors.jabatan }}</FieldError>
                            </div>

                            <template v-if="pakaiDepo">
                                <div>
                                    <Label for="depo_id">Depo <span class="text-red-600">*</span></Label>
                                    <Combobox id="depo_id" v-model="form.depo_id" :options="opsi.depo" placeholder="Cari depo..." :clearable="false" />
                                    <small v-if="item" class="block text-gray-500 mt-1">{{ kategori === 'sales' ? 'Ganti depo mengosongkan koordinator dan area.' : 'Ganti depo mengosongkan wilayah depo.' }}</small>
                                    <FieldError v-if="form.errors.depo_id">{{ form.errors.depo_id }}</FieldError>
                                </div>
                                <div v-if="kategori === 'sales'">
                                    <Label for="koordinator_id">Koordinator <span class="text-red-600">*</span></Label>
                                    <Combobox id="koordinator_id" v-model="form.koordinator_id" :options="pilihan.koordinator" :disabled="!form.depo_id || memuatOpsi"
                                        :placeholder="form.depo_id ? 'Cari koordinator...' : 'Pilih depo dulu'" :clearable="false" />
                                    <FieldError v-if="form.errors.koordinator_id">{{ form.errors.koordinator_id }}</FieldError>
                                </div>
                                <div v-else>
                                    <Label for="wilayah_depo_id">Wilayah depo</Label>
                                    <Select id="wilayah_depo_id" v-model="form.wilayah_depo_id" :options="pilihan.wilayah" :disabled="!form.depo_id || memuatOpsi"
                                        :placeholder="form.depo_id ? '-- Pilih wilayah depo --' : 'Pilih depo dulu'" />
                                    <FieldError v-if="form.errors.wilayah_depo_id">{{ form.errors.wilayah_depo_id }}</FieldError>
                                </div>
                            </template>

                            <template v-if="kategori === 'sales'">
                                <div class="sm:col-span-2">
                                    <Label for="area_ids">Area <span class="text-red-600">*</span></Label>
                                    <Combobox id="area_ids" v-model="form.area_ids" multiple :options="pilihan.area" :disabled="!form.depo_id || memuatOpsi"
                                        :placeholder="form.depo_id ? 'Cari area di depo ini...' : 'Pilih depo dulu'" />
                                    <small class="block text-gray-500 mt-1">{{ form.area_ids.length }} area dipilih dari {{ pilihan.area.length }} area depo.</small>
                                    <FieldError v-if="form.errors.area_ids">{{ form.errors.area_ids }}</FieldError>
                                </div>
                                <div class="sm:col-span-2">
                                    <Label for="area_aktif_ids">Area aktif (rute di aplikasi HP)</Label>
                                    <Combobox id="area_aktif_ids" v-model="form.area_aktif_ids" multiple :options="opsiAreaAktif" :disabled="!form.area_ids.length"
                                        placeholder="Pilih dari area di atas..." />
                                    <small class="block text-gray-500 mt-1">Kosong = semua area motoris ini menjadi rute.</small>
                                    <FieldError v-if="form.errors.area_aktif_ids">{{ form.errors.area_aktif_ids }}</FieldError>
                                </div>
                            </template>
                        </div>
                    </Card>

                    <Card v-show="tab === 'kontak'">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <Label for="no_hp">No HP <span v-if="wajibKontak" class="text-red-600">*</span></Label>
                                <Input id="no_hp" v-model="form.no_hp" :inputmode="kategori === 'sales' ? 'numeric' : 'tel'" :hanya-angka="kategori === 'sales'" maxlength="30" :required="wajibKontak" />
                                <small v-if="kategori === 'sales'" class="block text-gray-500 mt-1">Hanya angka, tanpa spasi.</small>
                                <FieldError v-if="form.errors.no_hp">{{ form.errors.no_hp }}</FieldError>
                            </div>
                            <div>
                                <Label for="email">Email</Label>
                                <Input id="email" v-model="form.email" type="email" maxlength="150" />
                                <FieldError v-if="form.errors.email">{{ form.errors.email }}</FieldError>
                            </div>
                            <div>
                                <Label for="tanggal_mulai">Tanggal mulai bekerja <span v-if="wajibKontak" class="text-red-600">*</span></Label>
                                <DatePicker id="tanggal_mulai" v-model="form.tanggal_mulai" :required="wajibKontak" />
                                <FieldError v-if="form.errors.tanggal_mulai">{{ form.errors.tanggal_mulai }}</FieldError>
                            </div>
                            <div>
                                <Label for="aktif">Status <span class="text-red-600">*</span></Label>
                                <Select id="aktif" v-model="form.aktif" :options="[{ value: '1', label: 'Aktif' }, { value: '0', label: 'Tidak aktif' }]" required />
                                <FieldError v-if="form.errors.aktif">{{ form.errors.aktif }}</FieldError>
                            </div>
                        </div>
                    </Card>

                    <Card v-show="tab === 'gaji'">
                        <p class="text-gray-500 mb-3">Nilai per bulan, dalam rupiah.</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div v-for="[kolom, label] in GAJI" :key="kolom">
                                <Label :for="kolom">{{ label }}</Label>
                                <InputRibuan :id="kolom" v-model="form[kolom]" />
                                <FieldError v-if="form.errors[kolom]">{{ form.errors[kolom] }}</FieldError>
                            </div>
                        </div>
                    </Card>
                </div>
            </fieldset>
        </form>

        <div v-if="$slots.penempatan" v-show="tab === 'penempatan'" class="mt-3"><slot name="penempatan" /></div>
        <div v-if="$slots.riwayat" v-show="tab === 'riwayat'"><slot name="riwayat" /></div>

        <div v-if="bolehSimpan && tab !== 'riwayat'" class="form-aksi-tetap">
            <Button type="submit" form="form-karyawan" :loading="form.processing" :disabled="form.processing">{{ item ? 'Simpan perubahan' : `Tambah ${nama}` }}</Button>
            <span v-if="form.isDirty && item" class="text-gray-500">Ada perubahan yang belum disimpan.</span>
        </div>
    </div>
</template>
