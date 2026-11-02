import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DetailMotoris from './DetailMotoris.vue';

const detail = (lain = {}) => ({
    profil: { id: 1, nama: 'Rudi Hartono', no_hp: '0812', depo: 'Sidoarjo', koordinator: 'Koor Satu', area: ['Sidoarjo Kota'], tanggal_masuk: '2024-08-01', masa_kerja: '2 tahun 3 bulan', status: 'Aktif', foto: null },
    periode: { dari: '2026-11-01', sampai: '2026-11-15' },
    ringkasan: { hari_kerja: 12, target_call: 480, target_ec: 300, ipt: 2, target_ipt: 3, hadir: 2, terlambat: 1, kunjungan: 3, transaksi: 1, ec: 1, penjualan: 150000, qty: 5, rata_penjualan: 75000 },
    harian: [{ tanggal: '2026-11-03', masuk: '08:15', pulang: '17:05', terlambat: true, kunjungan: 2, ec: 1, ipt: 2, transaksi: 1, penjualan: 150000 }],
    produk: [{ nama: 'Kopi Sachet', qty: 3, nilai: 90000 }],
    target: { call: 40, ec: 25, ipt: 3 },
    tanda_motoris: [{ id: 5, tanggal: '2026-11-03', kategori_label: 'Penjualan fiktif', catatan: 'Nota janggal', oleh: 'koor', waktu: '2026-11-04 09:00', dicabut: false }],
    tanda_outlet: [{ id: 8, outlet_id: 70, outlet: 'Toko Makmur', kategori_label: 'Outlet fiktif', catatan: null, oleh: 'admin', waktu: '2026-11-05 09:00' }],
    ...lain,
});

let balasan;
let transaksiBalasan;
let pilihanBalasan;

describe('DetailMotoris', () => {
    beforeEach(() => {
        balasan = { ok: true, status: 200, json: () => Promise.resolve(detail()) };
        transaksiBalasan = { ok: true, status: 200, json: () => Promise.resolve({ tanggal: '2026-11-03', transaksi: [{ kode: 'PJ-1', waktu: '2026-11-03 10:30', outlet: 'Toko Makmur', sku: 2, qty: 5, total: 150000, diskon: 1000, status: 'Sah' }] }) };
        pilihanBalasan = { ok: true, status: 200, json: () => Promise.resolve({ motoris: [{ value: '1', label: 'Rudi Hartono · Sidoarjo' }, { value: '3', label: 'Tegar Mahendra · Sidoarjo' }] }) };
        global.fetch = vi.fn((url) => Promise.resolve(String(url).includes('/detail/transaksi') ? transaksiBalasan : (String(url).includes('pilihan-motoris') ? pilihanBalasan : balasan)));
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    async function siapkan() {
        const wrapper = mount(DetailMotoris, { props: { salesId: 1 }, attachTo: document.body });
        await flushPromises();

        return wrapper;
    }

    it('memuat detail tanpa periode lalu menampilkan profil dan ringkasan', async () => {
        const wrapper = await siapkan();

        expect(fetch).toHaveBeenCalledWith('/tracking_sales/1/detail', expect.anything());
        expect(wrapper.text()).toContain('Rudi Hartono');
        expect(wrapper.text()).toContain('2 tahun 3 bulan');
        expect(wrapper.text()).toContain('Sidoarjo Kota');
        expect(wrapper.find('[data-kpi="hadir"]').text()).toContain('2/ 12 HK');
        expect(wrapper.find('[data-kpi="ec"]').text()).not.toContain('dari call');
        expect(wrapper.text()).toContain('08:15');
    });

    it('menampilkan jam pulang serta persen Call dan EC terhadap target, di ringkasan dan per hari', async () => {
        const wrapper = await siapkan();

        expect(wrapper.text()).toContain('Jam Pulang');
        expect(wrapper.text()).toContain('17:05');
        // Ringkasan: 3 dari 480 call = 1%, 1 dari 300 EC = 0%.
        expect(wrapper.find('[data-kpi="kunjungan"]').text()).toContain('/ 480');
        expect(wrapper.find('[data-kpi="kunjungan"]').text()).toContain('1%');
        expect(wrapper.find('[data-kpi="ec"]').text()).toContain('/ 300');
        // Harian: 2 dari 40 call = 5%, 1 dari 25 EC = 4%.
        const baris = wrapper.find('tbody tr').text();
        expect(baris).toContain('5%');
        expect(baris).toContain('4%');
    });

    it('baris ketiga KPI: bar progress Call dan EC terhadap target, dan tidak ada lagi kartu KPI Penjualan', async () => {
        const wrapper = await siapkan();
        const bar = (kunci) => wrapper.find(`[data-kpi="${kunci}"] [role="progressbar"]`);

        // Bar berada di wadah baris ketiga (kpi-baris-bar).
        expect(bar('kunjungan').element.parentElement.classList).toContain('kpi-baris-bar');
        expect(bar('ec').element.parentElement.classList).toContain('kpi-baris-bar');

        // 3 dari 480 call = 1%, 1 dari 300 EC = 0%.
        expect(bar('kunjungan').attributes('aria-valuenow')).toBe('1');
        expect(bar('kunjungan').find('.kpi-bar-isi').attributes('style')).toContain('width: 1%');
        expect(bar('ec').attributes('aria-valuenow')).toBe('0');

        expect(wrapper.find('[data-kpi="penjualan"]').exists()).toBe(false);
        expect(wrapper.findAll('.kpi-item').map((k) => k.attributes('data-kpi'))).toEqual(['hadir', 'terlambat', 'kunjungan', 'ec', 'ipt', 'rata']);
    });

    it('bar progress Call penuh dan hijau saat target tercapai, tanpa bar bila target kosong', async () => {
        balasan = { ok: true, status: 200, json: () => Promise.resolve(detail({ ringkasan: { ...detail().ringkasan, kunjungan: 500, target_call: 480, ec: 10, target_ec: 0 } })) };
        const wrapper = await siapkan();

        const isi = wrapper.find('[data-kpi="kunjungan"] .kpi-bar-isi');
        expect(isi.attributes('style')).toContain('width: 100%');
        expect(isi.classes()).toContain('kpi-bar-isi-capai');
        expect(wrapper.find('[data-kpi="ec"] [role="progressbar"]').exists()).toBe(false);
    });

    it('KPI IPT dan kolom IPT di tabel menampilkan nilai, target, persen, dan bar', async () => {
        const wrapper = await siapkan();
        const kpi = wrapper.find('[data-kpi="ipt"]');

        // 2,0 dari target 3,0 = 67%.
        expect(kpi.text()).toContain('2,0');
        expect(kpi.text()).toContain('/ 3,0');
        expect(kpi.text()).toContain('67%');
        expect(kpi.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('67');
        expect(kpi.find('[role="progressbar"]').element.parentElement.classList).toContain('kpi-baris-bar');

        expect(wrapper.text()).toContain('IPT');
        expect(wrapper.find('tbody tr').text()).toContain('2,0');
    });

    it('kolom IPT per hari kosong (-) bila tidak ada transaksi aktif', async () => {
        balasan = { ok: true, status: 200, json: () => Promise.resolve(detail({ harian: [{ tanggal: '2026-11-04', masuk: '08:00', pulang: null, terlambat: false, kunjungan: 1, ec: 0, ipt: 0, transaksi: 0, penjualan: 0 }] })) };
        const wrapper = await siapkan();

        expect(wrapper.find('tbody tr').findAll('td')[5].text()).toBe('-');
    });

    it('grafik garis menampilkan dua seri: Call dan Effective Call per tanggal', async () => {
        const wrapper = await siapkan();
        const grafik = wrapper.find('svg[aria-label="Call dan Effective Call per hari"]');

        expect(grafik.findAll('polyline')).toHaveLength(2);
        const legenda = wrapper.find('[aria-label="Legenda grafik"]').text();
        expect(legenda).toContain('Call');
        expect(legenda).toContain('Effective Call');
        // Satu tanggal di fixture: Call 2 dan EC 1.
        expect(grafik.findAll('circle title').map((t) => t.text())).toEqual(['03/11 · Call: 2', '03/11 · Effective Call: 1']);
    });

    it('badge persen di tabel Harian: kuning di bawah target, hijau tepat 100%, biru muda bila melebihi target', async () => {
        const harian = (tanggal, kunjungan, ec) => ({ tanggal, masuk: '08:00', pulang: '17:00', terlambat: false, kunjungan, ec, transaksi: 0, penjualan: 0 });
        // Target harian 40 call dan 25 EC.
        balasan = { ok: true, status: 200, json: () => Promise.resolve(detail({ harian: [harian('2026-11-03', 20, 25), harian('2026-11-04', 40, 30)] })) };
        const wrapper = await siapkan();
        const warna = (baris, kolom) => wrapper.findAll('tbody tr')[baris].findAll('td')[kolom].find('.badge').classes();
        const KOLOM_CALL = 3;
        const KOLOM_EC = 4;

        expect(warna(0, KOLOM_CALL)).toContain('badge-warning');
        expect(warna(0, KOLOM_EC)).toContain('badge-success');
        expect(warna(1, KOLOM_CALL)).toContain('badge-success');
        expect(warna(1, KOLOM_EC)).toContain('badge-info');
    });

    // fetch yang baru selesai saat selesai() dipanggil, untuk memeriksa tampilan selama menunggu.
    function tahan(cocok) {
        let selesai;
        const tertahan = new Promise((resolve) => { selesai = resolve; });
        const asli = global.fetch;
        global.fetch = vi.fn((url, opsi) => (cocok(String(url)) ? tertahan : asli(url, opsi)));

        return (respon) => selesai(respon);
    }

    it('pemuatan hanya ditandai spinner di tombol Tampilkan (pemuatan pertama dan ganti periode)', async () => {
        const selesai = tahan((url) => !url.includes('pilihan-motoris'));
        const wrapper = mount(DetailMotoris, { props: { salesId: 1 }, attachTo: document.body });
        await flushPromises();
        const tampilkan = wrapper.findAll('button').find((b) => b.text() === 'Tampilkan');

        // Pemuatan pertama: spinner hanya di tombol, tidak ada spinner atau teks pemuatan lain di halaman.
        expect(tampilkan.classes()).toContain('btn-loading');
        expect(wrapper.findAll('.spinner')).toHaveLength(0);
        expect(wrapper.text()).not.toContain('Memuat');

        selesai(balasan);
        await flushPromises();
        expect(tampilkan.classes()).not.toContain('btn-loading');
        expect(wrapper.text()).toContain('Rudi Hartono');

        // Ganti periode: tombol berputar, data lama tetap tampil, tidak ada indikator lain.
        wrapper.vm.$.setupState.dari = '2026-10-01';
        await wrapper.vm.$nextTick();
        const selesai2 = tahan((url) => !url.includes('pilihan-motoris'));
        await tampilkan.trigger('click');

        expect(tampilkan.classes()).toContain('btn-loading');
        expect(tampilkan.attributes('aria-busy')).toBe('true');
        expect(wrapper.text()).toContain('Rudi Hartono');
        expect(wrapper.text()).not.toContain('Memuat');

        selesai2(balasan);
        await flushPromises();
        expect(tampilkan.classes()).not.toContain('btn-loading');
    });

    it('dialog transaksi menampilkan spinner selama transaksi tanggal itu diambil', async () => {
        const wrapper = await siapkan();
        const selesai = tahan((url) => url.includes('/detail/transaksi'));

        await wrapper.find('tbody tr').trigger('click');
        await flushPromises();

        expect(dialog().textContent).toContain('Memuat transaksi...');
        expect(dialog().querySelector('.spinner')).not.toBeNull();

        selesai(transaksiBalasan);
        await flushPromises();

        expect(dialog().querySelector('.spinner')).toBeNull();
        expect(dialog().textContent).toContain('PJ-1');
    });

    it('pemilih motoris memuat daftar motoris dan menampilkan yang sedang dibuka', async () => {
        const wrapper = await siapkan();

        expect(fetch).toHaveBeenCalledWith('/tracking_sales/pilihan-motoris?id=1', expect.anything());
        expect(wrapper.find('input[aria-label="Pilih motoris"]').element.value).toBe('Rudi Hartono · Sidoarjo');
    });

    it('memilih motoris lain memberi tahu induk (id dan nama), dan salesId baru mengambil ulang dengan periode yang sama', async () => {
        const wrapper = await siapkan();

        wrapper.vm.$.setupState.gantiMotoris('3');
        expect(wrapper.emitted('ganti')).toEqual([[{ id: 3, nama: 'Tegar Mahendra' }]]);

        // Memilih motoris yang sama tidak memicu apa-apa.
        wrapper.vm.$.setupState.gantiMotoris('1');
        expect(wrapper.emitted('ganti')).toHaveLength(1);

        await wrapper.setProps({ salesId: 3 });
        await flushPromises();

        expect(fetch).toHaveBeenLastCalledWith('/tracking_sales/3/detail?dari=2026-11-01&sampai=2026-11-15', expect.anything());
    });

    it('ganti motoris menutup dialog transaksi yang sedang terbuka', async () => {
        const wrapper = await siapkan();
        await wrapper.find('tbody tr').trigger('click');
        await flushPromises();
        expect(dialog()).not.toBeNull();

        await wrapper.setProps({ salesId: 3 });
        await flushPromises();

        expect(dialog()).toBeNull();
    });

    it('tab Ditandai memuat tanda motoris dan tanda outlet motoris ini', async () => {
        const wrapper = await siapkan();
        const tab = wrapper.findAll('[role="tab"]').find((b) => b.text() === 'Ditandai (2)');

        await tab.trigger('click');
        expect(wrapper.text()).toContain('Nota janggal');
        expect(wrapper.text()).toContain('Outlet fiktif');
        expect(wrapper.text()).toContain('Toko Makmur');
    });

    it('tidak ada tab Transaksi dan transaksi tidak diambil sebelum tanggal diklik', async () => {
        const wrapper = await siapkan();

        expect(wrapper.findAll('[role="tab"]').map((b) => b.text())).not.toContain('Transaksi');
        expect(fetch).toHaveBeenCalledTimes(2); // detail + daftar pilihan motoris
        expect(dialog()).toBeNull();
    });

    // Dialog transaksi di-teleport ke body, jadi dicari lewat document.
    const dialog = () => document.body.querySelector('[data-panel="transaksi-hari"]');

    it('klik satu tanggal di tabel Harian membuka dialog berisi transaksi tanggal itu', async () => {
        const wrapper = await siapkan();
        expect(dialog()).toBeNull();

        await wrapper.find('tbody tr').trigger('click');
        await flushPromises();

        expect(wrapper.find('tbody tr').classes()).toContain('baris-aktif');
        expect(fetch).toHaveBeenLastCalledWith('/tracking_sales/1/detail/transaksi?tanggal=2026-11-03', expect.anything());
        expect(document.body.querySelector('[role="dialog"][aria-label="Transaksi 3 Nov 2026"]')).not.toBeNull();
        expect(dialog().textContent).toContain('PJ-1');
        expect(dialog().textContent).toContain('Toko Makmur');

        document.body.querySelector('[aria-label="Tutup"]').click();
        await flushPromises();
        expect(dialog()).toBeNull();
        expect(wrapper.find('tbody tr').classes()).not.toContain('baris-aktif');
    });

    it('menampilkan pesan di dialog bila transaksi satu tanggal gagal dimuat', async () => {
        const wrapper = await siapkan();
        transaksiBalasan = { ok: false, status: 500, json: () => Promise.resolve({}) };

        await wrapper.find('tbody tr').trigger('click');
        await flushPromises();

        expect(dialog().textContent).toContain('Transaksi belum bisa dimuat');
    });

    it('tab Produk Terlaris menampilkan produknya', async () => {
        const wrapper = await siapkan();

        await wrapper.findAll('[role="tab"]').find((b) => b.text() === 'Produk Terlaris').trigger('click');
        expect(wrapper.text()).toContain('Kopi Sachet');
    });

    it('Tampilkan mengambil ulang dengan periode yang dipilih', async () => {
        const wrapper = await siapkan();
        const tampilkan = wrapper.findAll('button').find((b) => b.text() === 'Tampilkan');
        expect(tampilkan.attributes('disabled')).toBeDefined();
        expect(tampilkan.find('svg').exists()).toBe(true);

        wrapper.vm.$.setupState.dari = '2026-10-01';
        await wrapper.vm.$nextTick();
        expect(tampilkan.attributes('disabled')).toBeUndefined();

        await tampilkan.trigger('click');
        await flushPromises();

        expect(fetch).toHaveBeenLastCalledWith('/tracking_sales/1/detail?dari=2026-10-01&sampai=2026-11-15', expect.anything());
    });

    it('menampilkan pesan validasi periode dari server', async () => {
        balasan = { ok: false, status: 422, json: () => Promise.resolve({ errors: { sampai: ['Periode paling panjang 366 hari.'] } }) };
        const wrapper = await siapkan();

        expect(wrapper.text()).toContain('Periode paling panjang 366 hari.');
    });
});
