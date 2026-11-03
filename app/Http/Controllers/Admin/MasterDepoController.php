<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Akses\Akses;
use App\Support\Master\MasterDepo;
use App\Support\Mobile\Pengaturan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// Form "Tambah Warehouse / Depo" (meniru /master_depo/add di Marketama), menyimpan ke mk_depo (tabel master
// Marketama di Enesis, dibaca juga oleh menu daftar lewat App\Support\Menu\SumberMasterDepo dan aplikasi HP
// lewat App\Support\Mobile\Pengaturan::depo()) — lihat App\Support\Master\MasterDepo. Depo baru lewat form
// ini langsung tampil di daftar utama, dan juga di kartu "Depo terbaru" di halaman ini sendiri.
class MasterDepoController extends Controller
{
    private const DISK = 'local';

    public function create(Request $request): Response
    {
        return Inertia::render('MasterDepo/Tambah', [
            'field' => $this->fieldUntukForm(),
            'nilai' => ['aktif' => '1', 'multi_polygon_sales' => '0'],
            'provinsiList' => MasterDepo::provinsiList(),
            'terbaru' => $this->terbaru(),
            'bisaTambah' => Akses::bisa($request->user(), 'master_depo', 'tambah'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(Akses::bisa($request->user(), 'master_depo', 'tambah'), 403);

        $data = $this->validasi($request);
        $id = MasterDepo::idBaru();
        $foto = $this->simpanFoto($request, $id);

        DB::table(MasterDepo::TABEL)->insert(['id' => $id] + $data + ['foto' => $foto] + $this->jejak($request));
        Akses::catat($request->user(), 'master_depo', $id, 'dibuat', 'Depo dibuat: '.$data['nama']);

        return redirect()->route('master-depo.tambah')->with('sukses', "Depo {$data['nama']} ditambahkan ke data master (mk_depo).");
    }

    /** Kota/kabupaten untuk provinsi terpilih (dropdown berjenjang). */
    public function wilayahKota(Request $request): JsonResponse
    {
        $provinsi = trim((string) $request->query('provinsi'));

        return response()->json($provinsi === '' ? [] : MasterDepo::kotaList($provinsi));
    }

    public function wilayahKecamatan(Request $request): JsonResponse
    {
        $provinsi = trim((string) $request->query('provinsi'));
        $kota = trim((string) $request->query('kota_kabupaten'));

        return response()->json($provinsi === '' || $kota === '' ? [] : MasterDepo::kecamatanList($provinsi, $kota));
    }

    public function wilayahKelurahan(Request $request): JsonResponse
    {
        $provinsi = trim((string) $request->query('provinsi'));
        $kota = trim((string) $request->query('kota_kabupaten'));
        $kecamatan = trim((string) $request->query('kecamatan'));

        return response()->json($provinsi === '' || $kota === '' || $kecamatan === '' ? [] : MasterDepo::kelurahanList($provinsi, $kota, $kecamatan));
    }

    public function foto(Request $request, int $id)
    {
        $path = DB::table(MasterDepo::TABEL)->where('id', $id)->value('foto');

        abort_unless($path && Storage::disk(self::DISK)->exists($path), 404);

        return Storage::disk(self::DISK)->response($path);
    }

    /** @return array<string, mixed> field siap dikirim ke Vue (opsi pilihan sudah dilengkapi). */
    private function fieldUntukForm(): array
    {
        return array_map(function ($f) {
            [$nama, $label, $jenis, $wajib, $maks, $bantuan] = $f;
            $sumber = str_starts_with($jenis, 'pilihan:') ? substr($jenis, 8) : null;

            return [
                'nama' => $nama, 'label' => $label, 'jenis' => $sumber ? 'pilihan' : $jenis, 'wajib' => $wajib, 'maks' => $maks, 'bantuan' => $bantuan,
                'opsi' => $sumber ? MasterDepo::opsi($sumber) : null,
            ];
        }, MasterDepo::field());
    }

    private function terbaru(): array
    {
        return MasterDepo::terbaru()->map(fn ($r) => [
            'id' => (int) $r->id, 'kode' => $r->kode, 'nama' => $r->nama,
            'wilayah' => trim(collect([$r->kota_kabupaten, $r->provinsi])->filter()->implode(', ')) ?: '-',
            'target_call' => $r->target_call, 'target_ec' => $r->target_ec,
            'aktif' => $r->aktif ? 'Aktif' : 'Tidak aktif',
            'foto' => $r->foto ? "/master_depo/baru/{$r->id}/foto?v=".substr(md5((string) $r->foto), 0, 8) : null,
            'diubah' => trim(($r->diubah_oleh ?? '').' · '.$this->waktu($r->diubah_pada), ' ·'),
        ])->all();
    }

    private function waktu(mixed $waktu): string
    {
        return $waktu ? CarbonImmutable::parse($waktu, 'UTC')->setTimezone(Pengaturan::zona())->locale('id')->translatedFormat('j M Y H:i') : '-';
    }

    /** @return array<string, mixed> data siap simpan (tanpa foto) */
    private function validasi(Request $request): array
    {
        $aturan = ['kode' => ['required', 'string', 'max:50', Rule::unique(MasterDepo::TABEL, 'kode')]];
        $label = ['kode' => 'kode warehouse'];

        // Radio Status / Multi Area tidak selalu dikirim (mis. form standar HTML tanpa opsi terpilih): default
        // status Aktif dan Multi Area/Polygon "Tidak" sama seperti nilai awal yang dikirim ke halaman form.
        $request->mergeIfMissing(['aktif' => '1', 'multi_polygon_sales' => '0']);

        // Angka Rupiah boleh diketik dengan titik ribuan ("35.000").
        $request->merge(collect(MasterDepo::field())->filter(fn ($f) => $f[2] === 'rupiah' && $request->filled($f[0]))
            ->mapWithKeys(fn ($f) => [$f[0] => str_replace(['.', ' ', 'Rp'], '', (string) $request->input($f[0]))])->all());

        foreach (MasterDepo::field() as [$nama, $teks, $jenis, $wajib, $maks]) {
            if ($nama === 'kode') {
                continue;
            }

            $label[$nama] = mb_strtolower($teks);
            $dasar = [$wajib ? 'required' : 'nullable'];

            $aturan[$nama] = match (true) {
                $jenis === 'text', $jenis === 'textarea', $jenis === 'wilayah' => [...$dasar, 'string', 'max:'.($maks ?? 255)],
                $jenis === 'koordinat' => [...$dasar, 'numeric', 'between:-90,90'],
                $jenis === 'angka' => [...$dasar, 'integer', 'min:0', 'max:999999'],
                $jenis === 'rupiah' => [...$dasar, 'numeric', 'min:0', 'max:9999999999'],
                $jenis === 'foto' => [$wajib ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
                str_starts_with($jenis, 'pilihan:') => [...$dasar, Rule::in(MasterDepo::idOpsi(substr($jenis, 8)))],
            };
        }
        // Longitude beda rentang dari latitude (keduanya "koordinat" di atas); -180..180.
        $aturan['longitude'] = ['required', 'numeric', 'between:-180,180'];

        $data = $request->validate($aturan, [
            'unique' => ':Attribute ini sudah dipakai.',
            'in' => 'Pilih :attribute dari daftar.',
        ], $label);

        unset($data['foto']);

        foreach (MasterDepo::field() as [$nama, , $jenis]) {
            if (! array_key_exists($nama, $data)) {
                continue;
            }
            $v = $data[$nama];
            $data[$nama] = match (true) {
                $v === null || $v === '' => $nama === 'aktif' ? true : ($nama === 'multi_polygon_sales' ? false : null),
                $nama === 'aktif' || $nama === 'multi_polygon_sales' => (bool) (int) $v,
                str_starts_with($jenis, 'pilihan:') => (int) $v,
                default => is_string($v) ? trim($v) : $v,
            };
        }

        return $data;
    }

    private function simpanFoto(Request $request, int $id): ?string
    {
        $file = $request->file('foto');

        return $file instanceof UploadedFile
            ? $file->storeAs('master-depo', $id.'-'.now()->format('YmdHis').'.'.strtolower($file->extension()), self::DISK)
            : null;
    }

    private function jejak(Request $request): array
    {
        return ['diubah_oleh' => $request->user()?->username ?? $request->user()?->name, 'diubah_pada' => now()];
    }
}
