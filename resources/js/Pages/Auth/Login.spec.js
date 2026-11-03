import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Login from './Login.vue';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    useForm: () => ({ username: '', password: '', ingat: false, errors: {}, post: vi.fn(), reset: vi.fn() }),
    usePage: () => ({ props: { errors: {} } }),
    router: { post },
}));

vi.mock('@/Layouts/AuthLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const koordinatorImpersonasi = [
    { id: 5, name: 'Budi Koordinator', username: 'budi', depo_nama: 'Depo Jakarta' },
    { id: 7, name: 'Siti Koordinator', username: 'siti', depo_nama: null },
];

describe('Login - impersonate koordinator', () => {
    it('tidak merender bagian impersonate bila prop null', () => {
        const wrapper = mount(Login, { props: { demoPeran: null, koordinatorImpersonasi: null } });

        expect(wrapper.text()).not.toContain('Impersonate koordinator');
    });

    it('tidak merender bagian impersonate bila daftar kosong', () => {
        const wrapper = mount(Login, { props: { demoPeran: null, koordinatorImpersonasi: [] } });

        expect(wrapper.text()).not.toContain('Impersonate koordinator');
    });

    it('menampilkan opsi dengan label nama + depo/username, dan mengirim id yang dipilih saat tombol ditekan', async () => {
        post.mockClear();
        const wrapper = mount(Login, { props: { demoPeran: null, koordinatorImpersonasi } });

        expect(wrapper.text()).toContain('Impersonate koordinator');

        const select = wrapper.find('select');
        const options = select.findAll('option');
        expect(options.map((o) => o.text())).toEqual(
            expect.arrayContaining(['Budi Koordinator (Depo Jakarta)', 'Siti Koordinator (siti)'])
        );

        await select.setValue('7');
        await wrapper.find('button.btn-outline-secondary').trigger('click');

        expect(post).toHaveBeenCalledWith('/login/impersonate/7');
    });
});
