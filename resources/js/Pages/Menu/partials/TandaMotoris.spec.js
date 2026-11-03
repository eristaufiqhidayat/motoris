import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TandaMotoris from './TandaMotoris.vue';

const URL = '/tracking_sales/tanda';
const props = { url: URL, salesId: 11, tanggal: '2026-10-24' };

// Tanda sistem (spec 062): Perlu dicek yang boleh diperiksa, dan satu yang sudah Terbukti.
const dasar = { oleh: 'Sistem', hasil_catatan: null, dicek_oleh: null, dicek_waktu: null };
const tanda = [
    { ...dasar, id: 5, kategori: 'lokasi_palsu', kategori_label: 'Diduga lokasi / GPS palsu', catatan: 'Mock location pada 3 titik GPS.', waktu: '2026-10-25 09:00', status: 'perlu_dicek', status_label: 'Perlu dicek', bisa_periksa: true },
    { ...dasar, id: 6, kategori: 'lokasi_palsu', kategori_label: 'Diduga lokasi / GPS palsu', catatan: null, waktu: '2026-10-25 10:00', status: 'terbukti', status_label: 'Terbukti', hasil_catatan: 'Benar, HP tester.', dicek_oleh: 'koor', dicek_waktu: '2026-10-26 08:00', bisa_periksa: false },
];

let daftar;
let lainnya;

function pasangFetch() {
    global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ tanda: daftar, lainnya, outlet: [] }) }));
}

async function siapkan(lain = {}) {
    const wrapper = mount(TandaMotoris, { props: { ...props, ...lain }, attachTo: document.body });
    await flushPromises();

    return wrapper;
}

// Badge merah (jumlah tanda tanggal ini) membuka panel daftar; badge kuning (jumlah tanggal lain) membuka panel lainnya.
const bukaDaftar = (wrapper) => wrapper.findAll('button').find((b) => b.find('.badge-danger').exists())?.trigger('click');
const bukaLainnya = (wrapper) => wrapper.findAll('button').find((b) => b.find('.badge-warning').exists())?.trigger('click');
const tombolPeriksa = (wrapper) => wrapper.findAll('button').filter((b) => b.text().includes('Periksa'));

describe('TandaMotoris', () => {
    beforeEach(() => {
        daftar = tanda;
        lainnya = [];
        pasangFetch();
    });

    afterEach(() => {
        document.head.innerHTML = '';
        document.body.innerHTML = '';
    });

    it('memuat tanda tanggal itu dan menampilkan counter, tapi daftar tersembunyi sampai diklik', async () => {
        const wrapper = await siapkan();

        expect(fetch.mock.calls[0][0]).toBe(`${URL}?sales_id=11&tanggal=2026-10-24`);
        expect(wrapper.text()).toContain('Tanda tanggal ini');
        expect(wrapper.find('.tanda-motoris-panel').exists()).toBe(false);

        await bukaDaftar(wrapper);

        expect(wrapper.findAll('.tanda-motoris-item')).toHaveLength(2);
        expect(wrapper.text()).toContain('Perlu dicek');
        expect(wrapper.text()).toContain('Terbukti');
    });

    it('counter tanda tidak tampil bila belum ada tanda', async () => {
        daftar = [];

        expect((await siapkan()).text()).not.toContain('Tanda tanggal ini');
    });

    it('tidak punya tombol Tandai: tanda hanya dibuat sistem', async () => {
        const wrapper = await siapkan();
        await bukaDaftar(wrapper);

        expect(wrapper.findAll('button').map((b) => b.text())).not.toContain('Tandai motoris');
        expect(wrapper.findAll('button').map((b) => b.text())).not.toContain('Tambah tanda');
        expect(wrapper.find('select').exists()).toBe(false);
    });

    it('tombol Periksa hanya pada tanda Perlu dicek yang boleh diperiksa akun', async () => {
        const wrapper = await siapkan();
        await bukaDaftar(wrapper);

        expect(tombolPeriksa(wrapper)).toHaveLength(1);

        daftar = tanda.map((t) => ({ ...t, bisa_periksa: false }));
        const tanpaHak = await siapkan();
        await bukaDaftar(tanpaHak);
        expect(tombolPeriksa(tanpaHak)).toHaveLength(0);
    });

    it('menampilkan alasan dan hasil cek hanya pada tanda yang punya', async () => {
        const wrapper = await siapkan();
        await bukaDaftar(wrapper);

        const item = wrapper.findAll('.tanda-motoris-item');
        expect(item[0].text()).toContain('Mock location pada 3 titik GPS.');
        expect(item[0].text()).not.toContain('Hasil cek');
        expect(item[1].text()).toContain('Hasil cek (koor): Benar, HP tester.');
    });

    it('Periksa membuka form hasil cek yang mengirim ke endpoint periksa tanda itu', async () => {
        const wrapper = await siapkan();
        await bukaDaftar(wrapper);

        await tombolPeriksa(wrapper)[0].trigger('click');

        const form = wrapper.findComponent({ name: 'PeriksaTanda' });
        expect(form.exists()).toBe(true);
        expect(form.props('url')).toBe(`${URL}/5/periksa`);
    });

    it('setelah pemeriksaan selesai menutup form, memuat ulang dan memberi tahu induknya', async () => {
        const wrapper = await siapkan();
        await bukaDaftar(wrapper);
        await tombolPeriksa(wrapper)[0].trigger('click');
        fetch.mockClear();
        daftar = [tanda[1]];

        wrapper.findComponent({ name: 'PeriksaTanda' }).vm.$emit('selesai', {});
        await flushPromises();

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(wrapper.emitted('berubah')).toEqual([[1]]);
        expect(wrapper.findComponent({ name: 'PeriksaTanda' }).exists()).toBe(false);
    });

    it('memuat ulang tanda dan menutup form saat tanggal drawer diganti', async () => {
        const wrapper = await siapkan();
        await bukaDaftar(wrapper);
        await tombolPeriksa(wrapper)[0].trigger('click');

        await wrapper.setProps({ tanggal: '2026-10-25' });
        await flushPromises();

        expect(fetch.mock.calls.at(-1)[0]).toBe(`${URL}?sales_id=11&tanggal=2026-10-25`);
        expect(wrapper.findComponent({ name: 'PeriksaTanda' }).exists()).toBe(false);
        expect(wrapper.find('.tanda-motoris-panel').exists()).toBe(false);
    });

    it('menampilkan pesan bila daftar gagal dimuat', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) }));

        expect((await siapkan()).text()).toContain('Gagal memuat tanda motoris.');
    });

    describe('tanda di tanggal lain', () => {
        const lain = [
            { ...dasar, id: 9, tanggal: '2026-10-22', kategori: 'lokasi_palsu', kategori_label: 'Diduga lokasi / GPS palsu', status: 'perlu_dicek', status_label: 'Perlu dicek' },
            { ...dasar, id: 8, tanggal: '2026-10-14', kategori: 'lokasi_palsu', kategori_label: 'Diduga lokasi / GPS palsu', status: 'terbukti', status_label: 'Terbukti' },
        ];

        beforeEach(() => {
            lainnya = lain;
        });

        it('tidak menampilkan counter bila tidak ada tanda di tanggal lain', async () => {
            lainnya = [];

            expect((await siapkan()).text()).not.toContain('Tanda tanggal lain');
        });

        it('menampilkan jumlah dan menyembunyikan daftarnya sampai dibuka, lengkap dengan statusnya', async () => {
            const wrapper = await siapkan();

            expect(wrapper.text()).toContain('Tanda tanggal lain');
            expect(wrapper.find('.tanda-motoris-lain').exists()).toBe(false);

            await bukaLainnya(wrapper);

            const baris = wrapper.findAll('.tanda-motoris-lain');
            expect(baris).toHaveLength(2);
            expect(baris[0].text()).toContain('22 Okt 2026');
            expect(baris[0].text()).toContain('Perlu dicek');
            expect(baris[1].text()).toContain('Terbukti');
        });

        it('mengklik satu baris mengirim tanggalnya agar drawer berpindah', async () => {
            const wrapper = await siapkan();
            await bukaLainnya(wrapper);

            await wrapper.findAll('.tanda-motoris-lain')[0].trigger('click');

            expect(wrapper.emitted('pilih-tanggal')).toEqual([['2026-10-22']]);
        });

        it('membuka panel lainnya menutup panel daftar tanda, dan sebaliknya', async () => {
            const wrapper = await siapkan();

            await bukaDaftar(wrapper);
            expect(wrapper.findAll('.tanda-motoris-panel')).toHaveLength(1);
            await bukaLainnya(wrapper);
            expect(wrapper.find('.tanda-motoris-lain').exists()).toBe(true);
            expect(wrapper.findAll('.tanda-motoris-item')).toHaveLength(0);

            await bukaDaftar(wrapper);
            expect(wrapper.findAll('.tanda-motoris-item')).toHaveLength(2);
            expect(wrapper.find('.tanda-motoris-lain').exists()).toBe(false);
        });

        it('klik di luar menutup panel yang sedang terbuka, walau elemen yang diklik menghentikan propagasi (mis. peta)', async () => {
            const wrapper = await siapkan();
            await bukaLainnya(wrapper);
            expect(wrapper.find('.tanda-motoris-lain').exists()).toBe(true);

            const peta = document.createElement('div');
            peta.addEventListener('click', (e) => e.stopPropagation());
            document.body.appendChild(peta);
            peta.click();
            await flushPromises();

            expect(wrapper.find('.tanda-motoris-lain').exists()).toBe(false);
        });
    });

    describe('mode drawer', () => {
        // Tanda tanggal yang dilihat (24 Okt) dan tanggal lain, dengan bisa_periksa dari backend (bukan panel tersembunyi).
        const lain = [
            { ...dasar, id: 9, tanggal: '2026-10-22', kategori: 'lokasi_palsu', kategori_label: 'Diduga lokasi / GPS palsu', catatan: null, waktu: '2026-10-22 08:00', status: 'perlu_dicek', status_label: 'Perlu dicek', bisa_periksa: true },
            { ...dasar, id: 8, tanggal: '2026-10-14', kategori: 'lokasi_palsu', kategori_label: 'Diduga lokasi / GPS palsu', catatan: 'Mock location', waktu: '2026-10-14 07:30', status: 'terbukti', status_label: 'Terbukti', bisa_periksa: false },
        ];

        it('menggabung tanda tanggal ini dan tanggal lain jadi satu daftar, terurut tanggal kejadian terbaru dulu', async () => {
            lainnya = lain;
            const wrapper = await siapkan({ drawer: true });

            expect(wrapper.text()).not.toContain('Tanda tanggal ini');
            expect(wrapper.find('[role="tab"]').text()).toBe('Tanda motoris (4)');

            const item = wrapper.findAll('.tanda-motoris-item');
            expect(item).toHaveLength(4);
            expect(item.map((i) => i.text().match(/(\d+ [A-Z][a-z]+ 20\d\d)/)[1])).toEqual([
                '24 Okt 2026', '24 Okt 2026', '22 Okt 2026', '14 Okt 2026',
            ]);
        });

        it('menampilkan status, pembuat (Sistem) dan hasil cek tiap tanda', async () => {
            const wrapper = await siapkan({ drawer: true });

            const item = wrapper.findAll('.tanda-motoris-item');
            expect(item[0].text()).toContain('oleh Sistem');
            expect(item.map((i) => i.text()).join(' ')).toContain('Hasil cek (koor · 2026-10-26 08:00): Benar, HP tester.');
        });

        it('Periksa hanya pada tanda yang bisa_periksa dan membuka form untuk tanda itu', async () => {
            lainnya = lain;
            const wrapper = await siapkan({ drawer: true });

            const periksa = tombolPeriksa(wrapper);
            expect(periksa).toHaveLength(2);

            await periksa[1].trigger('click');
            expect(wrapper.findComponent({ name: 'PeriksaTanda' }).props('url')).toBe(`${URL}/9/periksa`);
        });

        it('tidak menampilkan tombol Tambah tanda, Ubah catatan, maupun Cabut', async () => {
            const wrapper = await siapkan({ drawer: true });
            const teks = wrapper.findAll('button').map((b) => b.text()).join('|');

            expect(teks).not.toContain('Tambah tanda');
            expect(teks).not.toContain('Ubah catatan');
            expect(teks).not.toContain('Cabut');
        });

        it('memisahkan tanda outlet dari tanda motoris dalam tab, terbaru dulu, tanpa tombol periksa di sini', async () => {
            const outlet = [
                { id: 1, outlet_id: 70, outlet: 'Toko Lama', kategori_label: 'Diduga outlet fiktif', catatan: null, oleh: 'Sistem', waktu: '2026-10-20 08:00', tanggal: '2026-10-20' },
                { id: 2, outlet_id: 71, outlet: 'Toko Baru', didaftarkan: '2026-10-01 08:15', latitude: -7.45, longitude: 112.71, kategori_label: 'Diduga outlet fiktif', catatan: '4 outlet di koordinat yang sama', oleh: 'Sistem', waktu: '2026-10-26 09:30', tanggal: '2026-10-26' },
            ];
            global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ tanda: [tanda[0]], lainnya: [], outlet }) }));
            const wrapper = await siapkan({ drawer: true });
            const tab = () => wrapper.findAll('[role="tab"]');

            expect(tab().map((t) => t.text())).toEqual(['Tanda motoris (1)', 'Tanda outlet (2)']);
            expect(wrapper.findAll('.tanda-motoris-item')).toHaveLength(1);
            expect(wrapper.text()).not.toContain('Toko');

            await tab()[1].trigger('click');
            const item = wrapper.findAll('.tanda-motoris-item');
            expect(item).toHaveLength(2);
            expect(item[0].text()).toContain('Toko Baru');
            expect(item[1].text()).toContain('Toko Lama');
            expect(item[0].text()).toContain('Ditandai 26 Okt 2026 oleh Sistem');
            expect(item[0].text()).toContain('Didaftarkan (NOO) 1 Okt 2026 · 08:15');
            expect(item[0].text()).toContain('4 outlet di koordinat yang sama');
            expect(tombolPeriksa(wrapper)).toHaveLength(0);

            // Tombol Lihat peta hanya untuk outlet yang punya koordinat, dan mengirim titiknya.
            const peta = wrapper.findAll('button').filter((b) => b.text() === 'Lihat peta');
            expect(peta).toHaveLength(1);
            await peta[0].trigger('click');
            expect(wrapper.emitted('lihat-peta')).toEqual([[{ id: 71, nama: 'Toko Baru', latitude: -7.45, longitude: 112.71 }]]);
        });

        it('membuka tab outlet bila motoris tidak punya tanda motoris tapi punya tanda outlet', async () => {
            const outlet = [{ id: 1, outlet_id: 70, outlet: 'Toko X', kategori_label: 'Diduga outlet fiktif', catatan: null, oleh: 'Sistem', waktu: '2026-10-20 08:00', tanggal: '2026-10-20' }];
            global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ tanda: [], lainnya: [], outlet }) }));
            const wrapper = await siapkan({ drawer: true });

            expect(wrapper.find('[role="tab"][aria-selected="true"]').text()).toContain('Tanda outlet');
            expect(wrapper.text()).toContain('Toko X');
        });

        it('setelah pemeriksaan selesai memuat ulang dan memberi tahu induknya', async () => {
            const wrapper = await siapkan({ drawer: true });
            await tombolPeriksa(wrapper)[0].trigger('click');
            fetch.mockClear();

            wrapper.findComponent({ name: 'PeriksaTanda' }).vm.$emit('selesai', {});
            await flushPromises();

            expect(fetch).toHaveBeenCalledTimes(1);
            expect(wrapper.emitted('berubah')).toHaveLength(1);
        });
    });
});
