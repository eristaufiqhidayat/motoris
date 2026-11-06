<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, onUpdated, reactive, ref, useSlots, watch } from 'vue';
import Card from '@/Components/ui/molecules/Card.vue';
import Pagination from '@/Components/ui/molecules/Pagination.vue';
import Badge from '@/Components/ui/atoms/Badge.vue';
import Icon from '@/Components/ui/atoms/Icon.vue';
import DatePicker from '@/Components/ui/atoms/DatePicker.vue';
import Input from '@/Components/ui/atoms/Input.vue';
import Select from '@/Components/ui/atoms/Select.vue';
import Button from '@/Components/ui/atoms/Button.vue';
import Checkbox from '@/Components/ui/atoms/Checkbox.vue';
import { tanggalSingkat } from '@/Support/format.js';

const props = defineProps({
    title: { type: String, default: null },
    columns: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    links: { type: Array, default: () => [] },
    badgeColumns: { type: Array, default: () => [] },
    // Warna badge per nilai, mis. { status: { menunggu: 'warning', ditolak: 'danger' } }; kunci '*' = nilai lain yang terisi.
    // Tanpa peta: terisi = success.
    badgeVariants: { type: Object, default: () => ({}) },
    emptyText: { type: String, default: 'Tidak ada data.' },
    // Prop Inertia yang diambil ulang saat pindah halaman tabel (lihat Pagination).
    paginationOnly: { type: Array, default: () => [] },
    // Kolom yang header-nya bisa diklik untuk sort (kosong = tidak ada sort).
    sortable: { type: Array, default: () => [] },
    // Sort yang sedang aktif dari backend: { kolom, arah: 'asc' | 'desc' }, atau null.
    sort: { type: Object, default: null },
    // Kolom yang punya input filter di bawah header (kosong = tidak ada baris filter).
    filterable: { type: Array, default: () => [] },
    // Nilai filter aktif dari backend: { kolom: nilai }.
    filterValues: { type: Object, default: () => ({}) },
    // Kolom angka (bagian dari filterable) yang difilter dengan operator, mis. <, =, >.
    filterAngka: { type: Array, default: () => [] },
    // Operator aktif per kolom angka dari backend: { kolom: op }.
    filterOperator: { type: Object, default: () => ({}) },
    // Kolom tanggal (bagian dari filterable) yang difilter dengan datepicker dan operator.
    filterTanggal: { type: Array, default: () => [] },
    // Kolom yang menempel di kiri saat tabel digeser horizontal, urut dari kiri (mis. ['foto', 'nama']).
    stickyColumns: { type: Array, default: () => [] },
    // Tabel mengisi sisa tinggi layar: header kolom tetap terlihat saat baris di-scroll (scroll vertikal di dalam tabel).
    tinggiPenuh: { type: Boolean, default: false },
    // Dengan tinggiPenuh: tinggi area tabel dipatok (bukan hanya maksimum), jadi tetap setinggi layar walau data kosong.
    tinggiTetap: { type: Boolean, default: false },
    // Tabel mengisi tinggi wadah induknya (induk harus berukuran tetap, mis. flex-1 min-h-0 di dalam modal): hanya area
    // baris yang bergulir, header kolom tetap. Beda dengan tinggiPenuh yang menghitung dari sisa tinggi layar.
    isiWadah: { type: Boolean, default: false },
    // Data sedang diambil ulang (mis. pencarian): baris diganti placeholder skeleton, bukan teks.
    memuat: { type: Boolean, default: false },
    // Seluruh baris bisa diklik (atau Enter saat difokus) dan mengirim 'klik-baris' dengan datanya, mis. untuk membuka drawer detail.
    barisBisaDiklik: { type: Boolean, default: false },
    // Fungsi (row) => boolean: baris yang sedang dipilih diberi sorotan (mis. tanggal yang detailnya sedang dibuka).
    barisAktif: { type: Function, default: null },
    // Dengan barisBisaDiklik: kolom penunjuk (panah) di ujung kanan tiap baris supaya terlihat bahwa baris bisa dibuka.
    penandaKlik: { type: Boolean, default: false },
    // Baris total di bawah tabel (tetap terlihat saat tabel bergulir): { kolom: nilai }, kolom yang tidak diisi dibiarkan kosong.
    barisTotal: { type: Object, default: null },
    // Kolom checkbox di kiri untuk memilih baris (v-model = daftar nilai kunciBaris yang dipilih). Pilihan bertahan antar
    // halaman tabel; checkbox header memilih/melepas semua baris yang bisa dipilih di halaman ini.
    dapatDipilih: { type: Boolean, default: false },
    modelValue: { type: Array, default: () => [] },
    kunciBaris: { type: String, default: 'id' },
    // Fungsi (row) => boolean: baris yang tidak bisa dipilih tetap tampil dengan checkbox nonaktif (alasannya jadi tooltip).
    bisaDipilih: { type: Function, default: () => true },
    alasanTidakBisaDipilih: { type: String, default: null },
});

// Kolom yang punya pilihan operator: kolom angka dan kolom tanggal.
const kolomOperator = computed(() => [...props.filterAngka, ...props.filterTanggal]);

const OPERATOR_FILTER = [
    { value: '=', label: '=' },
    { value: '!=', label: '≠' },
    { value: '<', label: '<' },
    { value: '<=', label: '≤' },
    { value: '>', label: '>' },
    { value: '>=', label: '≥' },
];

const emit = defineEmits(['sort', 'filter', 'klik-baris', 'update:modelValue']);

const barisPilihan = computed(() => props.rows.filter((r) => props.bisaDipilih(r)));
const semuaTerpilih = computed(() => barisPilihan.value.length > 0 && barisPilihan.value.every((r) => props.modelValue.includes(r[props.kunciBaris])));
const sebagianTerpilih = computed(() => !semuaTerpilih.value && barisPilihan.value.some((r) => props.modelValue.includes(r[props.kunciBaris])));

function pilihBaris(row, aktif) {
    const kunci = row[props.kunciBaris];
    const lain = props.modelValue.filter((k) => k !== kunci);

    emit('update:modelValue', aktif ? [...lain, kunci] : lain);
}

function pilihSemua(aktif) {
    const kunciHalaman = barisPilihan.value.map((r) => r[props.kunciBaris]);
    const lain = props.modelValue.filter((k) => !kunciHalaman.includes(k));

    emit('update:modelValue', aktif ? [...lain, ...kunciHalaman] : lain);
}

// Jumlah kolom di luar `columns` (checkbox dan aksi), untuk colspan baris kosong.
const adaPenanda = computed(() => props.penandaKlik && props.barisBisaDiklik);
const kolomTambahan = computed(() => (props.dapatDipilih ? 1 : 0) + (slots.aksi ? 1 : 0) + (adaPenanda.value ? 1 : 0));

// Klik/Enter pada baris (prop barisBisaDiklik); klik pada kontrol di dalam baris (tombol, tautan, input) tidak ikut dihitung.
function klikBaris(row, e) {
    if (!props.barisBisaDiklik || e.target.closest('button, a, input, select, textarea, label')) {
        return;
    }

    emit('klik-baris', row);
}

// Kolom "Aksi" muncul bila halaman mengisi slot #aksi="{ row }".
// Sel kolom tertentu bisa diganti lewat slot #cell-<nama kolom>="{ row }" (mis. tautan, badge ganda).
const slots = useSlots();

function formatTotal(total) {
    return new Intl.NumberFormat('id-ID').format(total);
}

function badgeVariant(column, value) {
    const peta = props.badgeVariants[column];

    return peta?.[value] ?? (value ? peta?.['*'] : undefined) ?? (value ? 'success' : 'secondary');
}

// Arah sort kolom ini ('asc' | 'desc'), atau null bila kolom ini tidak sedang diurutkan.
function arahSort(kolom) {
    return props.sort?.kolom === kolom ? props.sort.arah : null;
}

// Klik berurutan: naik → turun → netral (tanpa sort), lalu naik lagi.
function urutkan(kolom) {
    const arah = arahSort(kolom);

    emit('sort', { kolom, arah: arah === null ? 'asc' : (arah === 'asc' ? 'desc' : null) });
}

function classHeaderSort(kolom) {
    if (!props.sortable.includes(kolom)) {
        return null;
    }

    const arah = arahSort(kolom);

    return ['sortable', arah && 'sort-aktif', arah === 'asc' && 'sort-asc'];
}

function ariaSort(kolom) {
    const arah = arahSort(kolom);

    return arah === null ? 'none' : (arah === 'asc' ? 'ascending' : 'descending');
}

// Nilai input dan operator per kolom: isian sementara di flyout, baru dikirim saat Terapkan atau Enter.
const nilaiFilter = reactive({});
const operatorFilter = reactive({});

// Flyout filter: hanya satu kolom terbuka. Tertutup saat klik di luar atau tekan Esc.
const flyoutKolom = ref(null);

// Isi dari backend, kecuali kolom yang flyout-nya sedang terbuka supaya isian yang belum diterapkan tidak tertimpa.
function sinkronFilter() {
    for (const kolom of props.filterable) {
        if (kolom !== flyoutKolom.value) {
            nilaiFilter[kolom] = props.filterValues[kolom] ?? '';
        }
    }

    for (const kolom of kolomOperator.value) {
        if (kolom !== flyoutKolom.value) {
            operatorFilter[kolom] = props.filterOperator[kolom] ?? '=';
        }
    }
}

sinkronFilter();
watch(() => [props.filterValues, props.filterOperator], sinkronFilter, { deep: true });

function kirimFilter() {
    // Seluruh nilai dan operator dikirim sekaligus; kolom lain tetap berisi nilai dari backend.
    emit('filter', {
        nilai: Object.fromEntries(props.filterable.map((k) => [k, (nilaiFilter[k] ?? '').trim()])),
        operator: Object.fromEntries(kolomOperator.value.map((k) => [k, operatorFilter[k] ?? '='])),
    });
}

function ubahFilter(kolom, nilai) {
    nilaiFilter[kolom] = nilai;
}

function ubahOperator(kolom, op) {
    operatorFilter[kolom] = op;
}

// Menutup flyout membuang isian yang belum diterapkan.
function tutupFlyout() {
    flyoutKolom.value = null;
    sinkronFilter();
}

async function bukaFlyout(kolom) {
    const tujuan = flyoutKolom.value === kolom ? null : kolom;

    tutupFlyout();
    flyoutKolom.value = tujuan;

    // Langsung bisa mengetik begitu flyout terbuka (kolom operator: fokus ke nilai, bukan pilihan operator).
    await nextTick();
    tabel.value?.querySelector('.filter-flyout input')?.focus();
}

function tutupFlyoutSaatKlikLuar() {
    if (flyoutKolom.value !== null) {
        tutupFlyout();
    }
}

function tutupFlyoutSaatEsc(event) {
    if (event.key === 'Escape') {
        tutupFlyout();
    }
}

// Filter yang berlaku di server, bukan isian yang belum diterapkan.
function adaFilterAktif(kolom) {
    return (props.filterValues[kolom] ?? '') !== '';
}

function filterBerubah(kolom) {
    const operatorBerubah = kolomOperator.value.includes(kolom)
        && (operatorFilter[kolom] ?? '=') !== (props.filterOperator[kolom] ?? '=');

    return (nilaiFilter[kolom] ?? '').trim() !== (props.filterValues[kolom] ?? '') || operatorBerubah;
}

// Ringkasan filter yang berlaku di server: label kolom dan nilainya, dengan operator untuk kolom angka/tanggal.
const filterTerpasang = computed(() => props.filterable
    .filter((kolom) => adaFilterAktif(kolom))
    .map((kolom) => {
        const nilai = props.filterValues[kolom];
        const teks = props.filterTanggal.includes(kolom) ? tanggalSingkat(nilai) || nilai : nilai;
        const operator = OPERATOR_FILTER.find((o) => o.value === (props.filterOperator[kolom] ?? '='))?.label ?? '=';

        return { kolom, label: props.columns[kolom] ?? kolom, nilai: kolomOperator.value.includes(kolom) ? `${operator} ${teks}` : teks };
    }));

function resetFilter() {
    for (const { kolom } of filterTerpasang.value) {
        nilaiFilter[kolom] = '';

        if (kolomOperator.value.includes(kolom)) {
            operatorFilter[kolom] = '=';
        }
    }

    kirimFilter();
    tutupFlyout();
}

function terapkanFilter(kolom) {
    if (filterBerubah(kolom)) {
        kirimFilter();
    }

    tutupFlyout();
}

function hapusFilter(kolom) {
    nilaiFilter[kolom] = '';

    if (kolomOperator.value.includes(kolom)) {
        operatorFilter[kolom] = '=';
    }

    kirimFilter();
    tutupFlyout();
}

// Kolom sticky disusun berurutan: offset `left` tiap kolom = total lebar kolom sticky di sebelah kirinya.
// Lebar diukur dari header (sel tbody memakai kolom yang sama, jadi lebarnya ikut).
const tabel = ref(null);

function kelasSticky(kolom) {
    return props.stickyColumns.includes(kolom) ? 'kolom-sticky' : null;
}

function aturSticky() {
    if (!tabel.value || props.stickyColumns.length === 0) {
        return;
    }

    let kiri = 0;

    Object.keys(props.columns).forEach((kolom, index) => {
        if (!props.stickyColumns.includes(kolom)) {
            return;
        }

        const sel = tabel.value.querySelectorAll(`tr > :nth-child(${index + 1 + (props.dapatDipilih ? 1 : 0)})`);

        sel.forEach((el) => {
            el.style.left = `${kiri}px`;
        });

        kiri += sel[0]?.offsetWidth ?? 0;
    });
}

// Tinggi tabel = sisa tinggi layar dari posisi tabel sampai bawah viewport, dikurangi footer kartu, margin bawah
// kartu (mis. mb-3 dari pemanggil), dan padding bawah <main> (1.5rem, lihat app.css) supaya halaman tidak ikut
// ter-scroll. Dihitung ulang saat data/footer berubah dan saat ukuran jendela berubah. Sama dengan pola
// sesuaikanTinggi() di SummaryDepoTabel.vue.
const scrollEl = ref(null);
const footerEl = ref(null);
const PADDING_BAWAH_MAIN = 24 + 2;

function sesuaikanTinggi() {
    if (!props.tinggiPenuh || !scrollEl.value) {
        return;
    }

    const kartu = footerEl.value?.closest('.card');
    const atas = Math.round(scrollEl.value.getBoundingClientRect().top);
    const marginKartu = parseFloat(kartu ? getComputedStyle(kartu).marginBottom : 0) || 0;
    // Di dalam drawer/modal sisa di bawah kartu hanya padding .modal-body (plus padding body kartu dan border),
    // bukan padding <main>, supaya drawer tidak ikut ter-scroll.
    const modal = scrollEl.value.closest('.modal-body');
    const paddingBodyKartu = parseFloat(getComputedStyle(scrollEl.value.closest('.card-body') ?? document.body).paddingBottom) || 0;
    const sisa = modal ? (parseFloat(getComputedStyle(modal).paddingBottom) || 0) + paddingBodyKartu + 4 : PADDING_BAWAH_MAIN;
    const bawah = (footerEl.value?.closest('.card-footer')?.offsetHeight ?? 0) + marginKartu + sisa;
    const tinggi = `calc(100vh - ${atas}px - ${bawah}px)`;

    scrollEl.value.style[props.tinggiTetap ? 'height' : 'maxHeight'] = tinggi;
}

function perbaruiTampilan() {
    aturSticky();
    sesuaikanTinggi();
}

onMounted(() => {
    document.addEventListener('click', tutupFlyoutSaatKlikLuar);
    document.addEventListener('keydown', tutupFlyoutSaatEsc);
    window.addEventListener('resize', sesuaikanTinggi);
    perbaruiTampilan();
});

onUpdated(perbaruiTampilan);

onBeforeUnmount(() => {
    document.removeEventListener('click', tutupFlyoutSaatKlikLuar);
    document.removeEventListener('keydown', tutupFlyoutSaatEsc);
    window.removeEventListener('resize', sesuaikanTinggi);
});
</script>


<template>
    <Card :class="{ 'card-isi-wadah': isiWadah }" :body-class="isiWadah ? 'card-body card-body-isi-wadah' : 'overflow-x-auto'">
        <template v-if="slots.header || title || filterTerpasang.length" #header>
            <slot name="header">{{ title }}</slot>
            <div v-if="filterTerpasang.length" class="filter-terpasang" :class="{ 'mt-2': slots.header || title }" aria-label="Filter aktif">
                <span class="text-gray-500">Filter aktif:</span>
                <span v-for="f in filterTerpasang" :key="f.kolom" class="filter-chip">
                    {{ f.label }}: <strong>{{ f.nilai }}</strong>
                    <button type="button" class="filter-chip-hapus" :aria-label="`Hapus filter ${f.label}`" @click="hapusFilter(f.kolom)"><Icon name="x" class="w-3 h-3" /></button>
                </span>
                <Button variant="outline-secondary" class="filter-reset" @click="resetFilter"><Icon name="x" class="me-1" /> Reset filter</Button>
            </div>
        </template>

        <div ref="scrollEl" class="overflow-auto" :class="{ 'h-full': isiWadah }">
        <table ref="tabel" class="data-table" :class="{ 'tabel-header-sticky': tinggiPenuh || isiWadah }">
            <thead>
                <tr>
                    <th v-if="dapatDipilih" class="kolom-pilih">
                        <Checkbox
                            :model-value="semuaTerpilih"
                            :indeterminate="sebagianTerpilih"
                            :disabled="!barisPilihan.length"
                            aria-label="Pilih semua baris yang bisa dipilih di halaman ini"
                            @update:model-value="pilihSemua"
                        />
                    </th>
                    <th
                        v-for="[kolom, label] in Object.entries(columns)"
                        :key="kolom"
                        :class="[classHeaderSort(kolom), kelasSticky(kolom)]"
                        :aria-sort="sortable.includes(kolom) ? ariaSort(kolom) : null"
                    >
                        <div class="th-isi">
                            <button v-if="sortable.includes(kolom)" type="button" class="sort-button" @click="urutkan(kolom)">
                                {{ label }}
                                <Icon :name="arahSort(kolom) ? 'chevron-down' : 'chevrons-down'" class="sort-icon" />
                            </button>
                            <template v-else>{{ label }}</template>
                            <button
                                v-if="filterable.includes(kolom)"
                                type="button"
                                class="filter-button"
                                :class="{ 'filter-aktif': adaFilterAktif(kolom) }"
                                :aria-label="`Filter ${label}`"
                                :aria-expanded="flyoutKolom === kolom"
                                @click.stop="bukaFlyout(kolom)"
                            >
                                <Icon name="filter" class="filter-icon" />
                            </button>
                        </div>
                        <div v-if="flyoutKolom === kolom" class="filter-flyout" @click.stop>
                            <p class="filter-flyout-judul">Filter {{ label }}</p>
                            <div v-if="kolomOperator.includes(kolom)" class="filter-angka">
                                <Select
                                    class="col-filter-op"
                                    :model-value="operatorFilter[kolom]"
                                    :options="OPERATOR_FILTER"
                                    :aria-label="`Operator ${label}`"
                                    @update:model-value="ubahOperator(kolom, $event)"
                                />
                                <DatePicker
                                    v-if="filterTanggal.includes(kolom)"
                                    class="col-filter"
                                    :model-value="nilaiFilter[kolom]"
                                    :aria-label="`Nilai ${label}`"
                                    clearable
                                    @update:model-value="ubahFilter(kolom, $event)"
                                />
                                <Input
                                    v-else
                                    :inputmode="filterAngka.includes(kolom) ? 'decimal' : null"
                                    class="col-filter"
                                    :model-value="nilaiFilter[kolom]"
                                    :placeholder="label"
                                    :aria-label="`Nilai ${label}`"
                                    @update:model-value="ubahFilter(kolom, $event)"
                                    @keydown.enter.prevent="terapkanFilter(kolom)"
                                />
                            </div>
                            <Input
                                v-else
                                type="search"
                                class="col-filter"
                                :model-value="nilaiFilter[kolom]"
                                :placeholder="label"
                                :aria-label="`Nilai ${label}`"
                                @update:model-value="ubahFilter(kolom, $event)"
                                @keydown.enter.prevent="terapkanFilter(kolom)"
                            />
                            <div class="filter-flyout-aksi">
                                <Button variant="outline-secondary" :disabled="!adaFilterAktif(kolom)" @click="hapusFilter(kolom)">Hapus filter</Button>
                                <Button :disabled="!filterBerubah(kolom)" @click="terapkanFilter(kolom)">Terapkan</Button>
                            </div>
                        </div>
                    </th>
                    <th v-if="slots.aksi">Aksi</th>
                    <th v-if="adaPenanda" class="kolom-panah"><span class="sr-only">Buka rincian</span></th>
                </tr>
            </thead>
            <tbody v-if="memuat" class="animate-pulse" aria-busy="true" aria-live="polite">
                <tr v-for="baris in 8" :key="baris">
                    <td v-if="dapatDipilih" class="kolom-pilih"></td>
                    <td v-for="column in Object.keys(columns)" :key="column"><div class="h-3 rounded bg-gray-200"></div></td>
                    <td v-if="slots.aksi"><div class="h-3 w-12 rounded bg-gray-200"></div></td>
                    <td v-if="adaPenanda" class="kolom-panah"></td>
                </tr>
            </tbody>
            <tbody v-else>
                <tr
                    v-for="(row, index) in rows"
                    :key="index"
                    :class="{ 'baris-klik': barisBisaDiklik, 'baris-aktif': barisAktif?.(row) }"
                    :tabindex="barisBisaDiklik ? 0 : null"
                    @click="klikBaris(row, $event)"
                    @keydown.enter.self="barisBisaDiklik && emit('klik-baris', row)"
                >
                    <td v-if="dapatDipilih" class="kolom-pilih" :title="bisaDipilih(row) ? null : alasanTidakBisaDipilih">
                        <Checkbox
                            :model-value="modelValue.includes(row[kunciBaris])"
                            :disabled="!bisaDipilih(row)"
                            :aria-label="`Pilih baris ${index + 1}`"
                            @update:model-value="pilihBaris(row, $event)"
                        />
                    </td>
                    <td v-for="column in Object.keys(columns)" :key="column" :class="kelasSticky(column)">
                        <slot v-if="slots[`cell-${column}`]" :name="`cell-${column}`" :row="row" />
                        <Badge v-else-if="badgeColumns.includes(column)" :variant="badgeVariant(column, row[column])">{{ row[column] || '-' }}</Badge>
                        <template v-else>{{ row[column] }}</template>
                    </td>
                    <td v-if="slots.aksi" class="whitespace-nowrap">
                        <slot name="aksi" :row="row" />
                    </td>
                    <td v-if="adaPenanda" class="kolom-panah"><Icon name="chevron-right" class="w-4 h-4" /></td>
                </tr>
                <tr v-if="!rows.length">
                    <td :colspan="Object.keys(columns).length + kolomTambahan" class="text-center text-gray-500 py-4">{{ emptyText }}</td>
                </tr>
            </tbody>
            <tfoot v-if="barisTotal && rows.length && !memuat">
                <tr class="baris-total">
                    <td v-if="dapatDipilih" class="kolom-pilih"></td>
                    <td v-for="column in Object.keys(columns)" :key="column">{{ barisTotal[column] ?? '' }}</td>
                    <td v-if="slots.aksi"></td>
                    <td v-if="adaPenanda" class="kolom-panah"></td>
                </tr>
            </tfoot>
        </table>
        </div>

        <template #footer>
            <div ref="footerEl" class="flex justify-between items-center">
                <small class="text-gray-500">Total {{ formatTotal(total) }} data</small>
                <Pagination :links="links" :only="paginationOnly" />
            </div>
        </template>
    </Card>
</template>
