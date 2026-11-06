<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import DatePicker from '@/Components/ui/atoms/DatePicker.vue';
import FieldError from '@/Components/ui/atoms/FieldError.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import InputRibuan from '@/Components/ui/atoms/InputRibuan.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Card from '@/Components/ui/molecules/Card.vue';
import Combobox from '@/Components/ui/molecules/Combobox.vue';
import InfoPopover from '@/Components/ui/molecules/InfoPopover.vue';
import PageHeader from '@/Components/ui/molecules/PageHeader.vue';
import StatCard from '@/Components/ui/molecules/StatCard.vue';
import { tanggalSingkat } from '@/Support/format.js';

// Pengaturan tanggal penggajian, tata letaknya mengikuti Beranda Koordinator: PageHeader dengan info, bagian kartu
// ringkas (StatCard) untuk nilai yang berlaku hari ini, form ubah, lalu riwayat perubahan.
const props = defineProps({
    slug: { type: String, required: true },
    judul: { type: String, required: true },
    berlaku: { type: Object, required: true },
    riwayat: { type: Array, default: () => [] },
    hariIni: { type: String, required: true },
    kunci: { type: Object, required: true },
});

const appName = usePage().props.appName;

const tanggal = (kunci) => `Tanggal ${props.berlaku[kunci].tanggal}`;

// Cut off insentif tetap akhir bulan kalender, jadi tidak ada di pengaturan dan tampil sebagai nilai tetap.
const kelompok = computed(() => [
    {
        id: 'judul-gaji',
        judul: 'Gaji',
        kartu: [
            { label: 'Cut off Gaji', icon: 'calendar', nilai: tanggal('cutoff_gaji'), keterangan: 'Awal periode absen, mis. 20 sampai 19 bulan berikutnya.' },
            { label: 'Bayar Gaji', icon: 'calendar-check', nilai: tanggal('bayar_gaji'), keterangan: 'Di bulan label periode, setelah periode berakhir.' },
        ],
    },
    {
        id: 'judul-insentif',
        judul: 'Insentif',
        kartu: [
            { label: 'Cut off Insentif', icon: 'calendar', nilai: 'Akhir bulan', keterangan: 'Tetap bulan kalender, tidak bisa diubah.' },
            { label: 'Bayar Insentif', icon: 'calendar-check', nilai: tanggal('bayar_insentif'), keterangan: 'Pada bulan berikutnya.' },
        ],
    },
]);

const form = useForm({ kunci: 'cutoff_gaji', tanggal: '', berlaku_mulai: props.hariIni });

function simpan() {
    form.post('/pengaturan_penggajian', {
        preserveScroll: true,
        onSuccess: () => form.reset('tanggal'),
    });
}
</script>

<template>
    <Head :title="`${judul} · ${appName}`" />
    <AppLayout :breadcrumb-slug="slug">
        <PageHeader :title="judul" subtitle="Tanggal cut off dan tanggal bayar gaji dan insentif · perubahan berlaku mulai tanggal yang dipilih">
            <template #info>
                <InfoPopover title="Tentang Pengaturan Penggajian" class="ms-1 align-middle">
                    <ul class="list-disc ps-4 space-y-1">
                        <li><b>Gaji:</b> cut off menentukan periode absen (mis. 20 sampai 19 bulan berikutnya) dan dibayar pada tanggal bayar gaji di bulan label periode.</li>
                        <li><b>Insentif:</b> periode bulan kalender (cut off akhir bulan), dibayar pada tanggal bayar insentif bulan berikutnya.</li>
                        <li>Nilai 1–28. Tanggal bayar gaji tidak boleh lebih awal dari tanggal cut off, dan cut off tanggal 1 tidak dipakai.</li>
                        <li>Perubahan berlaku mulai tanggal yang dipilih dan <b>tidak mengubah</b> penggajian yang sudah dibuat. Riwayat tidak ditimpa: tanggal berlaku yang sama tidak bisa dipakai dua kali.</li>
                    </ul>
                </InfoPopover>
            </template>
        </PageHeader>

        <div class="grid gap-3 xl:grid-cols-2 mb-4">
            <section v-for="g in kelompok" :key="g.id" :aria-labelledby="g.id">
                <h6 :id="g.id" class="judul-bagian">{{ g.judul }}</h6>
                <div class="grid gap-3 sm:grid-cols-2">
                    <StatCard v-for="k in g.kartu" :key="k.label" :label="k.label" :icon="k.icon" nada="info">
                        {{ k.nilai }}
                        <template #keterangan>{{ k.keterangan }}</template>
                    </StatCard>
                </div>
            </section>
        </div>

        <Card class="shadow-sm mb-3">
            <template #header><h6 class="mb-0 font-semibold">Ubah Pengaturan</h6></template>
            <form class="flex flex-wrap items-start gap-3" @submit.prevent="simpan">
                <div>
                    <Label for="kunci">Pengaturan <span class="text-red-600">*</span></Label>
                    <Combobox id="kunci" v-model="form.kunci" :options="kunci" placeholder="Pilih pengaturan..." :clearable="false" class="w-52" />
                    <FieldError v-if="form.errors.kunci">{{ form.errors.kunci }}</FieldError>
                </div>
                <div>
                    <Label for="tanggal">Tanggal (1–28) <span class="text-red-600">*</span></Label>
                    <InputRibuan id="tanggal" v-model="form.tanggal" class="w-24" />
                    <FieldError v-if="form.errors.tanggal">{{ form.errors.tanggal }}</FieldError>
                </div>
                <div>
                    <Label for="berlaku_mulai">Berlaku mulai <span class="text-red-600">*</span></Label>
                    <DatePicker id="berlaku_mulai" v-model="form.berlaku_mulai" :min="hariIni" class="w-36" />
                    <FieldError v-if="form.errors.berlaku_mulai">{{ form.errors.berlaku_mulai }}</FieldError>
                </div>
                <div class="self-end">
                    <Button type="submit" :loading="form.processing" :disabled="form.tanggal === ''"><Icon name="check" class="me-1 w-3.5 h-3.5" />Simpan</Button>
                </div>
            </form>
        </Card>

        <Card class="shadow-sm" body-class="card-body overflow-x-auto">
            <template #header><h6 class="mb-0 font-semibold">Riwayat Perubahan</h6></template>
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Pengaturan</th>
                        <th scope="col" class="text-end">Tanggal</th>
                        <th scope="col">Berlaku mulai</th>
                        <th scope="col">Status</th>
                        <th scope="col">Diubah oleh</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in riwayat" :key="i">
                        <td>{{ r.nama }}</td>
                        <td class="text-end">{{ r.tanggal }}</td>
                        <td>{{ tanggalSingkat(r.berlaku_mulai) }}</td>
                        <td><Badge :variant="r.status === 'Terjadwal' ? 'warning' : 'secondary'">{{ r.status }}</Badge></td>
                        <td class="text-gray-500">{{ r.oleh ? `${r.oleh} · ${r.diubah}` : 'Bawaan sistem' }}</td>
                    </tr>
                    <tr v-if="!riwayat.length">
                        <td colspan="5" class="text-center text-gray-500 py-4">Belum ada data.</td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </AppLayout>
</template>
