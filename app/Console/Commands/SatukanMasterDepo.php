<?php

namespace App\Console\Commands;

use App\Support\Master\MasterDepo;
use Illuminate\Console\Command;

class SatukanMasterDepo extends Command
{
    protected $signature = 'enesis:satukan-master-depo';

    protected $description = 'Isi kolom target call/EC, kuota, lokasi, dll. di mk_depo dari data admin "master_depo" (tabel impor CSV lama) untuk depo hasil marketama:tarik';

    public function handle(): int
    {
        $jumlah = MasterDepo::satukanDariMasterDepo();

        $this->info(number_format($jumlah, 0, ',', '.').' depo diisi dari master_depo.');

        return self::SUCCESS;
    }
}
