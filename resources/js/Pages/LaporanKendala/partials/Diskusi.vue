<script setup>
import { computed, ref } from 'vue';
import Button from '@/Components/ui/atoms/Button.vue';
import FieldError from '@/Components/ui/atoms/FieldError.vue';
import Label from '@/Components/ui/atoms/Label.vue';
import Textarea from '@/Components/ui/atoms/Textarea.vue';

// Linimasa diskusi Laporan Kendala (spec 065): komentar urut waktu + kotak komentar. Siapa pun yang boleh
// melihat laporan boleh berkomentar; penulis boleh menyunting/menghapus komentarnya sendiri (`milik_sendiri`).
// Daftar terbaru dikembalikan server dan diteruskan lewat `diperbarui`.
const props = defineProps({
    laporanId: { type: Number, required: true },
    komentar: { type: Array, default: () => [] },
});

const emit = defineEmits(['diperbarui']);

const jumlah = computed(() => props.komentar.filter((k) => k.jenis === 'komentar').length);

// Peristiwa otomatis (bukan komentar) ditampilkan sebagai garis ikon + teks.
const PERISTIWA = { selesai: 'menandai laporan Selesai', dibuka_kembali: 'membuka kembali laporan' };

const isi = ref('');
const galat = ref('');
const mengirim = ref(false);

// Komentar yang sedang disunting (id) beserta isiannya dan galatnya.
const disuntingId = ref(null);
const isiSuntingan = ref('');
const galatSuntingan = ref('');

/** Panggil endpoint komentar; hasil: { ok, data }. Galat validasi/jaringan jadi pesan di `data.pesan`. */
async function panggil(url, metode, body = null) {
    try {
        const respons = await fetch(url, {
            method: metode,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await respons.json().catch(() => ({}));

        return { ok: respons.ok, data: respons.ok ? data : { pesan: data.errors?.isi?.[0] ?? data.message ?? 'Permintaan gagal.' } };
    } catch {
        return { ok: false, data: { pesan: 'Permintaan gagal. Periksa koneksi lalu coba lagi.' } };
    }
}

async function kirim() {
    mengirim.value = true;
    galat.value = '';

    const { ok, data } = await panggil(`/laporan_kendala/${props.laporanId}/komentar`, 'POST', { isi: isi.value });
    if (ok) {
        isi.value = '';
        emit('diperbarui', data.komentar);
    } else {
        galat.value = data.pesan;
    }

    mengirim.value = false;
}

function mulaiSunting(k) {
    disuntingId.value = k.id;
    isiSuntingan.value = k.isi;
    galatSuntingan.value = '';
}

async function simpanSuntingan() {
    const { ok, data } = await panggil(`/laporan_kendala/${props.laporanId}/komentar/${disuntingId.value}`, 'PUT', { isi: isiSuntingan.value });
    if (ok) {
        disuntingId.value = null;
        emit('diperbarui', data.komentar);
    } else {
        galatSuntingan.value = data.pesan;
    }
}

async function hapus(k) {
    if (!confirm('Hapus komentar ini?')) return;

    const { ok, data } = await panggil(`/laporan_kendala/${props.laporanId}/komentar/${k.id}`, 'DELETE');
    if (ok) emit('diperbarui', data.komentar);
    else galat.value = data.pesan;
}
</script>

<template>
    <section class="diskusi" aria-label="Diskusi">
        <h3 class="diskusi-judul">Diskusi<template v-if="jumlah"> ({{ jumlah }})</template></h3>

        <ol v-if="komentar.length" class="diskusi-daftar">
            <li v-for="k in komentar" :key="k.id" :class="k.jenis === 'komentar' ? 'komentar' : 'peristiwa'">
                <template v-if="k.jenis !== 'komentar'">
                    <p class="peristiwa-teks">
                        <strong>{{ k.penulis }}</strong> {{ PERISTIWA[k.jenis] ?? k.jenis }}
                        <span class="text-gray-500"> · {{ k.waktu }}</span>
                    </p>
                    <p v-if="k.isi" class="peristiwa-isi">{{ k.isi }}</p>
                </template>
                <template v-else>
                <div class="komentar-kepala">
                    <span>
                        <strong>{{ k.penulis }}</strong>
                        <span class="text-gray-500"> · {{ k.waktu }}<template v-if="k.disunting"> · disunting</template></span>
                    </span>
                    <span v-if="k.milik_sendiri && disuntingId !== k.id" class="flex gap-1">
                        <Button variant="outline-secondary" size="sm" type="button" @click="mulaiSunting(k)">Sunting</Button>
                        <Button variant="outline-danger" size="sm" type="button" @click="hapus(k)">Hapus</Button>
                    </span>
                </div>
                <form v-if="disuntingId === k.id" class="komentar-isi" @submit.prevent="simpanSuntingan">
                    <Label :for="`lk_sunting_${k.id}`" class="sr-only">Sunting komentar</Label>
                    <Textarea :id="`lk_sunting_${k.id}`" v-model="isiSuntingan" rows="3" maxlength="2000" />
                    <FieldError v-if="galatSuntingan">{{ galatSuntingan }}</FieldError>
                    <div class="mt-2 flex justify-end gap-2">
                        <Button variant="outline-secondary" type="button" @click="disuntingId = null">Batal</Button>
                        <Button type="submit" :disabled="!isiSuntingan.trim()">Simpan</Button>
                    </div>
                </form>
                <p v-else class="komentar-isi">{{ k.isi }}</p>
                </template>
            </li>
        </ol>
        <p v-else class="text-gray-500">Belum ada komentar.</p>

        <form class="mt-3" @submit.prevent="kirim">
            <Label for="lk_komentar" class="sr-only">Komentar</Label>
            <Textarea id="lk_komentar" v-model="isi" rows="3" maxlength="2000" placeholder="Tulis komentar…" />
            <FieldError v-if="galat">{{ galat }}</FieldError>
            <div class="mt-2 flex justify-end">
                <Button type="submit" :loading="mengirim" :disabled="!isi.trim()">Kirim Komentar</Button>
            </div>
        </form>
    </section>
</template>
