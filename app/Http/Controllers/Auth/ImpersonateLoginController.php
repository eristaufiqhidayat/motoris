<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// "Impersonate koordinator": masuk tanpa password sebagai akun koordinator sungguhan (karyawan_id
// asli, bukan akun demo). Hanya bila config auth.impersonate_login aktif (lokal saja).
class ImpersonateLoginController extends Controller
{
    public function store(Request $request, User $user): RedirectResponse
    {
        abort_unless(config('auth.impersonate_login') && $user->kategori === 'koordinator' && $user->aktif, 404);

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['login_terakhir_pada' => now()])->save();

        return redirect()->intended('/home');
    }
}
