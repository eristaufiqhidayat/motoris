<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import FieldError from '@/Components/ui/atoms/FieldError.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import Input from '@/Components/ui/atoms/Input.vue';
import InputRibuan from '@/Components/ui/atoms/InputRibuan.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Radio from '@/Components/ui/atoms/Radio.vue';
import Select from '@/Components/ui/atoms/Select.vue';
import Textarea from '@/Components/ui/atoms/Textarea.vue';
import Alert from '@/Components/ui/molecules/Alert.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import PageHeader from '@/Components/ui/molecules/PageHeader.vue';
import { useLeafletMap } from '@/Composables/useLeafletMap.js';
import { ikonDepo, ikonLingkaran } from '@/Support/peta.js';

// Form "Tambah Warehouse / Depo", meniru field & peta lokasi dari /master_depo/add di Marketama.
// Disimpan ke mk_depo (tabel master Enesis), lihat catatan di App\Http\Controllers\Admin\MasterDepoController.
const props = defineProps({
    field: { type: Array, required: true },
    nilai: { type: Object, required: true },
    provinsiList: { type: Array, required: true },
    terbaru: { type: Array, default: () => [] },
    bisaTambah: { type: Boolean, default: false },
});

const appName = usePage().props.appName;

// Default sama dengan form Marketama (titik tengah Jakarta) supaya peta tidak kosong sebelum dipilih.
const DEFAULT_LAT = -6.200221;
const DEFAULT_LNG = 106.815191;

const fieldByName = Object.fromEntries(props.field.map((f) => [f.nama, f]));

const form = useForm({
    ...props.nilai,
    kode: '', nama: '',
    provinsi: '', kota_kabupaten: '', kecamatan: '', kelurahan_desa: '',
    alamat: '',
    latitude: DEFAULT_LAT, longitude: DEFAULT_LNG,
    foto: null,
    project_id: '', city_group_id: '',
    minimum_sku: '', kuota_koordinator: '', kuota_sales_motoris: '',
    target_call: '', target_ec: '', durasi_tracking_gps: '',
    insentif_per_poin: '', outlet_1_sku: '', minimal_value_transaksi: '',
});

// --- Dropdown wilayah berjenjang (Provinsi -> Kota/Kab -> Kecamatan -> Kelurahan/Desa) ---
const kotaList = ref([]);
const kecamatanList = ref([]);
const kelurahanList = ref([]);

async function ambilWilayah(url, params) {
    try {
        const qs = new URLSearchParams(params).toString();
        const respons = await fetch(`${url}?${qs}`, { headers: { Accept: 'application/json' } });

        return respons.ok ? await respons.json() : [];
    } catch {
        return [];
    }
}

watch(() => form.provinsi, async (provinsi, lama) => {
    if (lama !== undefined) {
        form.kota_kabupaten = '';
        form.kecamatan = '';
        form.kelurahan_desa = '';
        kecamatanList.value = [];
        kelurahanList.value = [];
    }
    kotaList.value = provinsi ? await ambilWilayah('/master_depo/tambah/wilayah/kota', { provinsi }) : [];
});

watch(() => form.kota_kabupaten, async (kotaKabupaten, lama) => {
    if (lama !== undefined) {
        form.kecamatan = '';
        form.kelurahan_desa = '';
        kelurahanList.value = [];
    }
    kecamatanList.value = kotaKabupaten ? await ambilWilayah('/master_depo/tambah/wilayah/kecamatan', { provinsi: form.provinsi, kota_kabupaten: kotaKabupaten }) : [];
});

watch(() => form.kecamatan, async (kecamatan, lama) => {
    if (lama !== undefined) {
        form.kelurahan_desa = '';
    }
    kelurahanList.value = kecamatan ? await ambilWilayah('/master_depo/tambah/wilayah/kelurahan', { provinsi: form.provinsi, kota_kabupaten: form.kota_kabupaten, kecamatan }) : [];
});

const opsiWilayah = (daftar) => daftar.map((nama) => ({ value: nama, label: nama }));
const opsiProvinsi = computed(() => opsiWilayah(props.provinsiList));

// --- Foto ---
const pratinjau = ref(null);
function pilihFoto(e) {
    const file = e.target.files?.[0] ?? null;
    form.foto = file;
    pratinjau.value = file ? URL.createObjectURL(file) : null;
}

// --- Peta: klik atau geser penanda untuk mengatur latitude/longitude; kotak pencarian alamat (Nominatim) ---
const wadahPeta = ref(null);
const cariAlamat = ref('');
const sedangCari = ref(false);
let penanda = null;

function pindahkanPenanda(lat, lng) {
    form.latitude = Number(lat.toFixed(7));
    form.longitude = Number(lng.toFixed(7));
    penanda?.setLatLng([lat, lng]);
}

const { peta } = useLeafletMap(wadahPeta, {
    pusat: [Number(form.latitude) || DEFAULT_LAT, Number(form.longitude) || DEFAULT_LNG],
    zoom: 13,
    onSiap(map, L) {
        penanda = L.marker([Number(form.latitude) || DEFAULT_LAT, Number(form.longitude) || DEFAULT_LNG], {
            draggable: true,
            icon: ikonLingkaran('#dc2626', ikonDepo),
        }).addTo(map);

        penanda.on('dragend', () => {
            const { lat, lng } = penanda.getLatLng();
            pindahkanPenanda(lat, lng);
        });

        map.on('click', (e) => pindahkanPenanda(e.latlng.lat, e.latlng.lng));
    },
});

async function cariLokasi() {
    const q = cariAlamat.value.trim();
    if (!q) return;

    sedangCari.value = true;
    try {
        const respons = await fetch(`https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=id&q=${encodeURIComponent(q)}`);
        const hasil = await respons.json();
        const titik = hasil?.[0];

        if (titik) {
            const lat = Number(titik.lat);
            const lng = Number(titik.lon);
            pindahkanPenanda(lat, lng);
            peta()?.setView([lat, lng], 16);
        }
    } catch {
        // Nominatim tidak terjangkau: lokasi tetap bisa diatur manual lewat klik peta.
    } finally {
        sedangCari.value = false;
    }
}

function simpan() {
    form.transform((d) => ({ ...d, project_id: d.project_id || null, city_group_id: d.city_group_id || null })).post('/master_depo/tambah', {
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            form.kode = '';
            form.latitude = DEFAULT_LAT;
            form.longitude = DEFAULT_LNG;
            form.foto = null;
            pratinjau.value = null;
            peta()?.setView([DEFAULT_LAT, DEFAULT_LNG], 13);
            penanda?.setLatLng([DEFAULT_LAT, DEFAULT_LNG]);
        },
    });
}
</script>

<template>
    <Head :title="`Tambah Warehouse / Depo · ${appName}`" />
    <AppLayout breadcrumb-slug="master_depo">
        <PageHeader title="Tambah Warehouse / Depo" subtitle="Tersimpan ke data master (mk_depo); lihat kartu “Depo terbaru” di bawah.">
            <template #actions>
                <Button :tag="Link" variant="light" href="/master_depo">Kembali ke daftar</Button>
            </template>
        </PageHeader>

        <Alert v-if="!bisaTambah" variant="warning" role="alert" class="mb-3">Akun ini tidak punya hak tambah untuk Master Warehouse / Depo.</Alert>

        <form @submit.prevent="simpan">
            <fieldset :disabled="!bisaTambah" class="contents">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-3">
                    <Card class="lg:col-span-2">
                        <template #header>Data Warehouse / Depo</template>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <Label for="kode">{{ fieldByName.kode.label }} <span class="text-red-600">*</span></Label>
                                <Input id="kode" v-model="form.kode" :maxlength="fieldByName.kode.maks" required />
                                <FieldError v-if="form.errors.kode">{{ form.errors.kode }}</FieldError>
                            </div>
                            <div>
                                <Label for="nama">{{ fieldByName.nama.label }} <span class="text-red-600">*</span></Label>
                                <Input id="nama" v-model="form.nama" :maxlength="fieldByName.nama.maks" required />
                                <FieldError v-if="form.errors.nama">{{ form.errors.nama }}</FieldError>
                            </div>

                            <div>
                                <Label for="provinsi">Provinsi <span class="text-red-600">*</span></Label>
                                <Select id="provinsi" v-model="form.provinsi" :options="opsiProvinsi" placeholder="Pilih Provinsi" required />
                                <FieldError v-if="form.errors.provinsi">{{ form.errors.provinsi }}</FieldError>
                            </div>
                            <div>
                                <Label for="kota_kabupaten">Kota / Kabupaten <span class="text-red-600">*</span></Label>
                                <Select id="kota_kabupaten" v-model="form.kota_kabupaten" :options="opsiWilayah(kotaList)" placeholder="Pilih Kota/Kab" :disabled="!form.provinsi" required />
                                <FieldError v-if="form.errors.kota_kabupaten">{{ form.errors.kota_kabupaten }}</FieldError>
                            </div>
                            <div>
                                <Label for="kecamatan">Kecamatan <span class="text-red-600">*</span></Label>
                                <Select id="kecamatan" v-model="form.kecamatan" :options="opsiWilayah(kecamatanList)" placeholder="Pilih Kecamatan" :disabled="!form.kota_kabupaten" required />
                                <FieldError v-if="form.errors.kecamatan">{{ form.errors.kecamatan }}</FieldError>
                            </div>
                            <div>
                                <Label for="kelurahan_desa">Kelurahan / Desa <span class="text-red-600">*</span></Label>
                                <Select id="kelurahan_desa" v-model="form.kelurahan_desa" :options="opsiWilayah(kelurahanList)" placeholder="Pilih Kelurahan/Desa" :disabled="!form.kecamatan" required />
                                <FieldError v-if="form.errors.kelurahan_desa">{{ form.errors.kelurahan_desa }}</FieldError>
                            </div>

                            <div class="sm:col-span-2">
                                <Label for="alamat">Alamat <span class="text-red-600">*</span></Label>
                                <Textarea id="alamat" v-model="form.alamat" rows="2" required />
                                <FieldError v-if="form.errors.alamat">{{ form.errors.alamat }}</FieldError>
                            </div>

                            <div>
                                <Label for="latitude">Latitude <span class="text-red-600">*</span></Label>
                                <Input id="latitude" v-model="form.latitude" type="number" step="any" required />
                                <FieldError v-if="form.errors.latitude">{{ form.errors.latitude }}</FieldError>
                            </div>
                            <div>
                                <Label for="longitude">Longitude <span class="text-red-600">*</span></Label>
                                <Input id="longitude" v-model="form.longitude" type="number" step="any" required />
                                <FieldError v-if="form.errors.longitude">{{ form.errors.longitude }}</FieldError>
                            </div>
                        </div>
                    </Card>

                    <div class="flex flex-col gap-3 min-w-0">
                        <Card>
                            <template #header>Foto</template>
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="foto-produk">
                                    <img v-if="pratinjau" :src="pratinjau" alt="Pratinjau foto depo">
                                    <Icon v-else name="camera" />
                                </span>
                                <div class="min-w-0">
                                    <Label for="foto">Unggah foto <span class="text-red-600">*</span></Label>
                                    <Input id="foto" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" @change="pilihFoto" />
                                    <small class="block text-gray-500 mt-1">.jpg, .jpeg, .png, maksimal 2 MB.</small>
                                    <FieldError v-if="form.errors.foto">{{ form.errors.foto }}</FieldError>
                                </div>
                            </div>
                        </Card>

                        <Card>
                            <template #header>Project &amp; City Group</template>
                            <div class="grid grid-cols-1 gap-3">
                                <div>
                                    <Label for="project_id">Project</Label>
                                    <Select id="project_id" v-model="form.project_id" :options="fieldByName.project_id.opsi" placeholder="Pilih Project (opsional)" />
                                    <FieldError v-if="form.errors.project_id">{{ form.errors.project_id }}</FieldError>
                                </div>
                                <div>
                                    <Label for="city_group_id">City Group</Label>
                                    <Select id="city_group_id" v-model="form.city_group_id" :options="fieldByName.city_group_id.opsi" placeholder="Pilih City Group (opsional)" />
                                    <FieldError v-if="form.errors.city_group_id">{{ form.errors.city_group_id }}</FieldError>
                                </div>
                            </div>
                        </Card>
                    </div>
                </div>

                <Card class="mb-3">
                    <template #header>Pilih Lokasi Warehouse</template>
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <Icon name="map-pin" />
                        <form class="grow min-w-56 flex gap-2" @submit.prevent="cariLokasi">
                            <Input v-model="cariAlamat" type="search" placeholder="Cari alamat…" class="grow" />
                            <Button type="submit" variant="outline-secondary" :loading="sedangCari">Cari</Button>
                        </form>
                    </div>
                    <p class="text-xs text-gray-500 mb-2">Klik pada peta atau geser penanda untuk memilih titik lokasi.</p>
                    <div ref="wadahPeta" class="tambah-depo-peta"></div>
                </Card>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-3">
                    <Card class="lg:col-span-2">
                        <template #header>Pengaturan Depo</template>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div v-for="nama in ['minimum_sku', 'kuota_koordinator', 'kuota_sales_motoris', 'target_call', 'target_ec', 'durasi_tracking_gps', 'outlet_1_sku']" :key="nama">
                                <Label :for="nama">{{ fieldByName[nama].label }}</Label>
                                <Input :id="nama" v-model="form[nama]" type="number" min="0" :placeholder="fieldByName[nama].bantuan" />
                                <small v-if="fieldByName[nama].bantuan" class="block text-gray-500 mt-1">{{ fieldByName[nama].bantuan }}</small>
                                <FieldError v-if="form.errors[nama]">{{ form.errors[nama] }}</FieldError>
                            </div>
                        </div>
                    </Card>

                    <Card>
                        <template #header>Nilai Rupiah</template>
                        <div class="grid grid-cols-1 gap-3">
                            <div v-for="nama in ['insentif_per_poin', 'minimal_value_transaksi']" :key="nama">
                                <Label :for="nama">{{ fieldByName[nama].label }}</Label>
                                <InputRibuan :id="nama" v-model="form[nama]" />
                                <small v-if="fieldByName[nama].bantuan" class="block text-gray-500 mt-1">{{ fieldByName[nama].bantuan }}</small>
                                <FieldError v-if="form.errors[nama]">{{ form.errors[nama] }}</FieldError>
                            </div>
                        </div>
                    </Card>
                </div>

                <Card class="mb-3">
                    <template #header>Multi Area / Polygon Sales &amp; Status</template>
                    <div class="flex flex-wrap gap-8">
                        <div>
                            <Label>Multi Area / Polygon Sales</Label>
                            <div class="flex gap-4 mt-1">
                                <label class="flex items-center gap-1"><Radio v-model="form.multi_polygon_sales" value="0" /> Tidak</label>
                                <label class="flex items-center gap-1"><Radio v-model="form.multi_polygon_sales" value="1" /> Ya</label>
                            </div>
                        </div>
                        <div>
                            <Label>Status</Label>
                            <div class="flex gap-4 mt-1">
                                <label class="flex items-center gap-1"><Radio v-model="form.aktif" value="1" /> Aktif</label>
                                <label class="flex items-center gap-1"><Radio v-model="form.aktif" value="0" /> Tidak Aktif</label>
                            </div>
                        </div>
                    </div>
                </Card>

                <div v-if="bisaTambah" class="flex flex-wrap items-center gap-2 mb-3">
                    <Button type="submit" :loading="form.processing" :disabled="form.processing">Submit</Button>
                    <Button variant="light" :tag="Link" href="/master_depo">Batal</Button>
                </div>
            </fieldset>
        </form>

        <Card v-if="terbaru.length">
            <template #header>Depo terbaru ditambahkan lewat Enesis</template>
            <ul class="flex flex-col gap-1 mb-0">
                <li v-for="d in terbaru" :key="d.id" class="flex items-center gap-2">
                    <span class="foto-produk-kecil">
                        <img v-if="d.foto" :src="d.foto" :alt="`Foto ${d.nama}`" loading="lazy">
                        <Icon v-else name="building" />
                    </span>
                    <span class="font-semibold">{{ d.nama }}</span>
                    <span class="kode-mono text-gray-500">{{ d.kode }}</span>
                    — {{ d.wilayah }}
                    <Badge :variant="d.aktif === 'Aktif' ? 'success' : 'secondary'">{{ d.aktif }}</Badge>
                    <span v-if="d.target_call || d.target_ec" class="text-gray-500">Target Call {{ d.target_call ?? '-' }} · Target EC {{ d.target_ec ?? '-' }}</span>
                    <span class="text-gray-500">· {{ d.diubah }}</span>
                </li>
            </ul>
        </Card>
    </AppLayout>
</template>
