<?php

namespace App\Support\Akses;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

// Satu jawaban untuk "data karyawan mana yang boleh dilihat akun ini": filter query, daftar depo,
// pengecekan satu karyawan, kunci cache, dan mode tim. Mode berasal dari role akun (Akses::CAKUPAN):
// semua | depo | downline | diri. Akun tanpa karyawan_id pada mode selain semua tidak melihat apa pun.
final class Cakupan
{
    /** @var array<int>|null */
    private ?array $depo = null;

    private function __construct(private readonly ?User $user, private readonly string $mode) {}

    public static function untuk(?User $user): self
    {
        return new self($user, Akses::penuh($user) ? 'semua' : (Akses::role($user)->cakupan ?: 'semua'));
    }

    /** Pengecualian bernama: tanpa pembatasan. $alasan wajib agar tiap pengecualian bisa dicari dan dipertanggungjawabkan. */
    public static function tanpa(string $alasan): self
    {
        return new self(null, 'semua');
    }

    /** Mode cakupan role: semua | depo | downline | diri. */
    public function mode(): string
    {
        return $this->mode;
    }

    public function semua(): bool
    {
        return $this->mode === 'semua';
    }

    /** Mode downline: akun hanya melihat dirinya dan timnya. */
    public function tim(): bool
    {
        return $this->mode === 'downline';
    }

    /** Query mk_karyawan (alias $k) yang sudah dibatasi cakupan akun. */
    public function karyawan(string $k = 'k'): Builder
    {
        return $this->batasi(DB::table("mk_karyawan as {$k}"), $k);
    }

    /** Batasi query yang memuat tabel mk_karyawan dengan alias $k. */
    public function batasi(Builder $q, string $k = 'k'): Builder
    {
        $karyawan = $this->user?->karyawan_id;

        return match ($this->mode) {
            'depo' => $q->where("{$k}.depo_id", DB::table('mk_karyawan')->where('id', $karyawan ?? 0)->value('depo_id') ?? -1),
            'downline' => $q->where(fn ($w) => $w->where("{$k}.koordinator_id", $karyawan ?? -1)->orWhere("{$k}.id", $karyawan ?? -1)),
            'diri' => $q->where("{$k}.id", $karyawan ?? -1),
            default => $q,
        };
    }

    /** Karyawan $id ada dalam cakupan akun? Mode semua tidak membatasi, termasuk id yang belum ada di mk_karyawan (data lama). */
    public function mencakup(int $id): bool
    {
        return $this->semua() || $this->karyawan('k')->where('k.id', $id)->exists();
    }

    /** @return array<int>|null id depo dalam cakupan; null = semua depo */
    public function depoIds(): ?array
    {
        if ($this->semua()) {
            return null;
        }

        return $this->depo ??= $this->karyawan('k')->whereNotNull('k.depo_id')
            ->distinct()->pluck('k.depo_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Halaman menu $slug boleh dibuka akun ini? Mode semua: ya. Selain itu hanya halaman yang jelas cakupannya:
     * tanpa tabel atau punya rute sendiri (controllernya membatasi sendiri), ditandai 'cakupan' => 'master' di
     * config marketama.pages, atau punya tabel ber-'sumber' (SumberTabel menerima $user). Selebihnya tertutup.
     */
    public function halamanTerbuka(string $slug): bool
    {
        $halaman = config("marketama.pages.{$slug}");

        if ($this->semua() || $halaman === null || self::punyaRuteSendiri($slug)) {
            return true;
        }

        return ($halaman['cakupan'] ?? null) === 'master'
            || self::adaSumber($halaman['tables'] ?? []);
    }

    /** Tabel $tabel di halaman $slug boleh ditampilkan akun ini? (halaman campuran: tabel mentah tanpa sumber disembunyikan) */
    public function tabelTerbuka(string $slug, string $tabel): bool
    {
        return $this->semua()
            || config("marketama.pages.{$slug}.cakupan") === 'master'
            || (bool) config("marketama.tables.{$tabel}.sumber");
    }

    /** Halaman tanpa tabel atau dengan sedikitnya satu tabel ber-sumber. */
    private static function adaSumber(array $tabel): bool
    {
        $nama = [];
        $kumpul = function (array $item) use (&$kumpul, &$nama) {
            foreach ($item as $i) {
                if (is_array($i) && isset($i['tables'])) {
                    $kumpul($i['tables']);
                } else {
                    $nama[] = is_array($i) ? $i['table'] : $i;
                }
            }
        };
        $kumpul($tabel);

        if ($nama === []) {
            return true;
        }

        foreach ($nama as $n) {
            if (config("marketama.tables.{$n}.sumber")) {
                return true;
            }
        }

        return false;
    }

    /** Halaman punya rute GET sendiri (bukan rute umum /{slug} di MenuController)? */
    private static function punyaRuteSendiri(string $slug): bool
    {
        static $uri = null;

        $uri ??= array_flip(array_map(
            fn ($r) => $r->uri(),
            app('router')->getRoutes()->get('GET')
        ));

        return isset($uri[$slug]);
    }

    /** Kunci cache: 'semua' dibagi bersama, selain itu per akun. */
    public function kunci(): string
    {
        return $this->semua() ? 'semua' : 'user_'.($this->user?->id ?? 0);
    }
}
