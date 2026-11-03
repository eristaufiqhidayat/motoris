<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Akses\Akses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

// Login session berbasis username (akun hasil migrasi Marketama umumnya tanpa email).
class LoginController extends Controller
{
    private const MAKS_PERCOBAAN = 5;

    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            // Tombol demo hanya dikirim bila fitur demo aktif (config auth.demo_login).
            'demoPeran' => config('auth.demo_login') ? config('auth.demo_peran') : null,
            // Daftar koordinator sungguhan hanya dikirim bila config auth.impersonate_login aktif.
            'koordinatorImpersonasi' => config('auth.impersonate_login') ? $this->koordinatorImpersonasi() : null,
        ]);
    }

    /** @return array<int, array{id: int, name: string, username: string, depo_nama: ?string}> */
    private function koordinatorImpersonasi(): array
    {
        return User::query()
            ->where('users.kategori', 'koordinator')
            ->where('users.aktif', true)
            ->leftJoin('mk_karyawan', 'mk_karyawan.id', '=', 'users.karyawan_id')
            ->leftJoin('mk_depo', 'mk_depo.id', '=', 'mk_karyawan.depo_id')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.username', 'mk_depo.nama as depo_nama'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'depo_nama' => $u->depo_nama])
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [], ['username' => 'username', 'password' => 'password']);

        $kunci = Str::lower($data['username']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($kunci, self::MAKS_PERCOBAAN)) {
            throw ValidationException::withMessages([
                'username' => 'Terlalu banyak percobaan login. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.',
            ]);
        }

        // Username tidak membedakan huruf besar/kecil (sama seperti aturan migrasi akun).
        $user = User::whereRaw('lower(username) = ?', [Str::lower($data['username'])])->first();

        if (! $user || ! $user->aktif || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($kunci, 60);

            throw ValidationException::withMessages(['username' => 'Username atau password salah.']);
        }

        RateLimiter::clear($kunci);

        if (! Akses::bolehWeb($user)) {
            throw ValidationException::withMessages(['username' => 'Akun ini hanya untuk aplikasi HP, tidak bisa membuka web Enesis.']);
        }

        Auth::login($user, $request->boolean('ingat'));
        $request->session()->regenerate();
        $user->forceFill(['login_terakhir_pada' => now()])->save();

        return redirect()->intended('/home');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
