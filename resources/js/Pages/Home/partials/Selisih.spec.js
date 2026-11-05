import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Selisih from './Selisih.vue';

const teks = (props) => mount(Selisih, { props }).text();
const kelas = (props) => mount(Selisih, { props }).classes();

describe('Selisih', () => {
    it('menampilkan kenaikan relatif dengan ▲ dan kelas naik', () => {
        expect(teks({ sekarang: 120, sebelum: 100 })).toBe('▲ 20%');
        expect(kelas({ sekarang: 120, sebelum: 100 })).toContain('selisih-naik');
    });

    it('menampilkan penurunan relatif dengan ▼ dan kelas turun', () => {
        expect(teks({ sekarang: 75, sebelum: 100 })).toBe('▼ 25%');
        expect(kelas({ sekarang: 75, sebelum: 100 })).toContain('selisih-turun');
    });

    it('tidak menghitung persen bila periode sebelumnya 0', () => {
        expect(teks({ sekarang: 5, sebelum: 0 })).toBe('belum ada pembanding');
    });

    it('mode poin memakai selisih langsung, juga saat periode sebelumnya 0', () => {
        expect(teks({ sekarang: 45, sebelum: 40, mode: 'poin' })).toBe('▲ 5 poin');
        expect(teks({ sekarang: 10, sebelum: 0, mode: 'poin' })).toBe('▲ 10 poin');
    });

    it('turunBaik: turun hijau, naik merah, panah tetap mengikuti arah angka', () => {
        expect(kelas({ sekarang: 8, sebelum: 10, turunBaik: true })).toContain('selisih-naik');
        expect(teks({ sekarang: 8, sebelum: 10, turunBaik: true })).toBe('▼ 20%');
        expect(kelas({ sekarang: 12, sebelum: 10, turunBaik: true })).toContain('selisih-turun');
        expect(teks({ sekarang: 12, sebelum: 10, turunBaik: true })).toBe('▲ 20%');
    });

    it('perubahan yang dibulatkan ke 0 dianggap tetap', () => {
        expect(teks({ sekarang: 100.01, sebelum: 100 })).toBe('– 0%');
        expect(kelas({ sekarang: 100.01, sebelum: 100 })).toContain('selisih-tetap');
    });
});
