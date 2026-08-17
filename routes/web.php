<?php

use App\Http\Controllers\Filter\FilterController;
use App\Http\Controllers\RuangLayanan\indexController;
use App\Http\Controllers\RuangLayanan\PoliBpUmumController;
use App\Http\Controllers\RuangLayanan\PoliGigiController;
use App\Http\Controllers\RuangLayananController;
use App\Http\Controllers\RuangLayanan\PoliKIAController;
use App\Http\Controllers\RuangLayanan\KematianController;
use App\Http\Controllers\RuangLayanan\TumbuhKembangController;
use App\Http\Controllers\RuangLayanan\PNCController;
use App\Http\Controllers\RuangLayanan\INCController;
use App\Http\Controllers\RuangLayanan\NeonatusController;
use App\Http\Controllers\RuangLayanan\AncController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\LoketController;
use Inertia\Inertia;
use App\Http\Controllers\Laporan\LaporanLoketController;
use App\Http\Controllers\Pasien\PasienController;
use App\Http\Controllers\Laporan\Rujukan\RujukanController;
use App\Http\Controllers\MalSehat\PTMController;
use App\Http\Controllers\Laporan\Kb\KbController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Laporan\Sanitasi\SanitasiController;
use App\Http\Controllers\Laporan\Ugd\UgdController;
use App\Http\Controllers\Home\HomeController;
use App\Http\Controllers\RuangLayanan\KunjOnline\KunjOnlineController;
use App\Http\Controllers\RuangLayanan\LaboratoriumController;
use App\Http\Controllers\Owner\OwnerController;
use App\Http\Controllers\Owner\PanelController;
use App\Http\Controllers\Auth\PasswordForceController;
use App\Http\Controllers\Owner\OwnerLogController;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Farmasi\MasterObatController;
use App\Http\Controllers\Farmasi\PelayananResepController;
use App\Http\Controllers\Farmasi\PengeluaranLangsungController;
use App\Http\Controllers\Farmasi\PengeluaranLangsungDetailController;
use App\Http\Controllers\RuangLayanan\SkriningPTM\SkriningPTMController;
use App\Http\Requests\SimpanTindakanRequest;
use App\Http\Controllers\RuangLayanan\SkriningPTM\SatuSehatController;
use App\Http\Controllers\RuangLayanan\SkriningPTM\DashboardPTMController;
use App\Http\Controllers\RuangLayanan\SkriningPTM\LaporanPTMController;
use App\Http\Controllers\RuangLayanan\SkriningPTM\RegisterExportController;
use App\Http\Controllers\RuangLayanan\SkriningPTM\RiwayatPTMController;
use App\Http\Controllers\RuangLayanan\SkriningPTM\RegisterPTMController;
use App\Http\Controllers\RuangLayanan\SkriningPTM\SkriningPTMExportController;



Route::middleware(['auth'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'index'])
        ->name('profile.index');
});

// Protect home (wajib login)
Route::get('/', function () {
    return Inertia::render('Templete/Index');
})->middleware('auth')->name('home');

// Login (rate-limited untuk mencegah brute force)
Route::get('/login', fn() => Inertia::render('Auth/Login'))->name('login');
Route::post('/login', [LoginController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('login.store');

Route::middleware(['auth', \App\Http\Middleware\CheckRole::class . ':owner,kapus'])
    ->get('/dashboard', fn() => Inertia::render('Dashboard'))
    ->name('dashboard');

Route::middleware(['auth', \App\Http\Middleware\CheckRole::class . ':owner,pelayanan,admin,loket'])
    ->get('/loket', fn() => Inertia::render('Loket/Index'))
    ->name('loket.index');

Route::middleware(['auth', \App\Http\Middleware\CheckRole::class . ':pelayanan,owner,admin'])
    ->get('/ruang-layanan/poli', fn() => Inertia::render('RuangLayanan/Poli'))
    ->name('ruang-layanan.poli.alt');

Route::middleware(['auth', \App\Http\Middleware\CheckRole::class . ':laborat,pelayanan,owner'])
    ->get('/laborat', fn() => Inertia::render('Laborat/Index'))
    ->name('laborat.index');

// NOTE: definisi '/dashboard' tanpa role sudah DIHAPUS (dulu menimpa definisi di atas).

// LOGOUT (satu-satunya definisi; duplikat kedua di bawah sudah dihapus)
Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    // Paksa full redirect (hard navigation) agar state client direset
    return Inertia::location(route('login'));
})->middleware('auth')->name('logout');

Route::prefix('home')->middleware('auth')->group(function () {
    Route::get('/', [HomeController::class, 'index'])
        ->name('home.home');
});

Route::get('/dashboard-ptm', [DashboardPTMController::class, 'dashboardPTM'])
    ->name('ptm.dashboard')
    ->middleware('auth');

Route::prefix('wilayah')->group(function () {
    Route::get('/kabupaten/{provinsi}', [PasienController::class, 'getKabupaten'])->name('wilayah.kabupaten');
    Route::get('/kecamatan/{provinsi}/{kabupaten}', [PasienController::class, 'getKecamatan'])->name('wilayah.kecamatan');
    Route::get('/kelurahan/{provinsi}/{kabupaten}/{kecamatan}', [PasienController::class, 'getKelurahan'])->name('wilayah.kelurahan');
});
// Data wilayah administratif (provinsi/kabupaten/kecamatan/kelurahan) dibiarkan publik
// karena bukan data pasien -- ini data referensi umum. Jika ingin lebih ketat,
// tambahkan ->middleware('auth') di atas seperti grup lain.

/*
|--------------------------------------------------------------------------
| KIA / ANC / Neonatus / PNC / INC / Kematian / Tumbuh Kembang
|--------------------------------------------------------------------------
| Sebelumnya TIDAK dibungkus auth sama sekali (berisi data diagnosa,
| obstetri, dan riwayat kunjungan). Sekarang wajib login + role pelayanan.
*/
Route::middleware(['auth', \App\Http\Middleware\CheckRole::class . ':pelayanan,owner,kapus'])->group(function () {
    Route::get('/simpus/kia', [PoliKIAController::class, 'index'])->name('ruang-layanan.kia');

    // ANC
    Route::get('/simpus/kia/anc', [AncController::class, 'index'])->name('ruang-layanan.anc');
    Route::get('/simpus/kia/anc/pelayanan/{id}/{idPoli}/{idPelayanan}', [AncController::class, 'pelayanan'])->name('ruang-layanan-anc.pelayanan');
    Route::post('simpus/kia/anc/pelayanan/', [AncController::class, 'setKunjunganANC'])->name('ruang-layanan-anc.kunjunganANC');
    Route::post('simpus/kia/anc/pelayanan/obstetri', [AncController::class, 'setObstetri'])->name('ruang-layanan-anc.obstetri');
    Route::post('simpus/kia/anc/pelayanan/DataDiagnosa', [AncController::class, 'setDataDiagnosa'])->name('ruang-layanan-anc.dataDiagnosa');
    Route::delete('simpus/kia/anc/pelayanan/DataDiagnosa/{id}', [AncController::class, 'hapusDataDiagnosa'])->name('diagnosa.destroy');
    Route::post('simpus/kia/anc/pelayanan/diagnosaKep', [AncController::class, 'setDataDiagnosaKep'])->name('ruang-layanan-anc.diagnosaKep');

    Route::get('/simpus/kia/pelayanan/{id}/{idPoli}/{idPelayanan}', [PoliKIAController::class, 'pelayanan'])->name('ruang-layanan-kia.pelayanan');

    // Kematian Maternal dan Perinatal
    Route::get('/simpus/kia/kematian', [KematianController::class, 'index'])->name('ruang-layanan.kematian');
    Route::get('/simpus/kia/kematian/pelayanan/{id}/{idPoli}/{idPelayanan}', [KematianController::class, 'pelayanan'])->name('ruang-layanan-kematian.pelayanan');

    Route::get('/simpus/kia/neonatus', [NeonatusController::class, 'index'])->name('ruang-layanan.neonatus');
    Route::get('/simpus/kia/neonatus/pelayanan/{id}/{idPoli}/{idPelayanan}', [NeonatusController::class, 'pelayanan'])->name('ruang-layanan-neonatus.pelayanan');

    Route::get('/simpus/kia/pnc', [PNCController::class, 'index'])->name('ruang-layanan.pnc');
    Route::get('/simpus/kia/pnc/pelayanan/{id}/{idPoli}/{idPelayanan}', [PNCController::class, 'pelayanan'])->name('ruang-layanan-pnc.pelayanan');

    Route::get('/simpus/kia/inc', [INCController::class, 'index'])->name('ruang-layanan.inc');
    Route::get('/simpus/kia/inc/pelayanan/{id}/{idPoli}/{idPelayanan}', [INCController::class, 'pelayanan'])->name('ruang-layanan-inc.pelayanan');

    Route::get('/simpus/kia/tumbuhkembang', [TumbuhKembangController::class, 'index'])->name('ruang-layanan.tkembang');
    Route::get('/simpus/kia/tumbuhkembang/pelayanan/{id}/{idPoli}/{idPelayanan}', [TumbuhKembangController::class, 'pelayanan'])->name('ruang-layanan-tkembang.pelayanan');
});

// Grup Admin
Route::prefix('admin')->middleware(['auth', \App\Http\Middleware\CheckRole::class . ':owner,admin'])->group(function () {
    Route::get('/', fn() => Inertia::render('Admin/Index'))->name('admin.index');
});

/*
|--------------------------------------------------------------------------
| Grup Farmasi
|--------------------------------------------------------------------------
| Sebelumnya publik total, termasuk POST /master-obat (celah stored XSS)
| dan seluruh endpoint pengeluaran-langsung (stok obat). Sekarang wajib
| login + role farmasi.
*/
Route::prefix('farmasi')->middleware(['auth', \App\Http\Middleware\CheckRole::class . ':owner,kapus'])->group(function () {
    Route::get('/', fn() => Inertia::render('Farmasi/Index'))->name('farmasi.index');
    Route::get('/laporan', fn() => Inertia::render('Farmasi/LaporanFarmasi'))->name('farmasi.laporan');

    // master-obat
    Route::get('/master-obat', [MasterObatController::class, 'index'])->name('farmasi.master-obat.data');
    Route::get('/master-obat/tambah', [MasterObatController::class, 'create'])->name('master-obat.create');
    Route::post('/master-obat', [MasterObatController::class, 'store'])->name('master-obat.store');

    // pengeluaran-langsung
    Route::get('/units', [PengeluaranLangsungController::class, 'getUnits']);
    Route::get('/get-sub-units', [PengeluaranLangsungController::class, 'getSubUnits']);
    Route::get('/pengeluaran-langsung', [PengeluaranLangsungController::class, 'index'])->name('farmasi.pengeluaran-langsung');
    Route::post('/pengeluaran-langsung', [PengeluaranLangsungController::class, 'store'])->name('farmasi.pengeluaran-langsung.store');
    Route::get('/pengeluaran-langsung/{id}', [PengeluaranLangsungController::class, 'show'])->name('farmasi.pengeluaran-langsung.show');
    Route::put('/pengeluaran-langsung/{id}', [PengeluaranLangsungController::class, 'update'])->name('farmasi.pengeluaran-langsung.update');
    Route::delete('/pengeluaran-langsung/{id}', [PengeluaranLangsungController::class, 'destroy'])->name('farmasi.pengeluaran-langsung.destroy');
    Route::post('/pengeluaran-langsung/filter', [PengeluaranLangsungController::class, 'filter'])->name('farmasi.pengeluaran-langsung.filter');
    Route::get('/pengeluaran-langsung/{id}/detail', [PengeluaranLangsungController::class, 'detail'])->name('pengeluaran-langsung.detail');
    Route::get('/pengeluaran-langsung/{id}/detail/items', [PengeluaranLangsungDetailController::class, 'index'])->name('pengeluaran.detail.index');
    Route::get('/pengeluaran-langsung/{id}/detail/data', [PengeluaranLangsungDetailController::class, 'getData'])->name('pengeluaran.detail.data');
    Route::post('/pengeluaran-langsung/{id}/detail', [PengeluaranLangsungDetailController::class, 'store'])->name('pengeluaran.detail.store');
    Route::delete('/pengeluaran-langsung/{id}/detail/{detailId}', [PengeluaranLangsungDetailController::class, 'destroy'])->name('pengeluaran.detail.destroy');

    // pelayanan-resep
    Route::get('/pelayanan-resep', [PelayananResepController::class, 'index'])->name('farmasi.pelayanan-resep.index');
    Route::get('/pelayanan-resep/data', [PelayananResepController::class, 'getData']);
    Route::get('/get-units', [PelayananResepController::class, 'getUnits']);
    Route::get('/get-sub-units', [PelayananResepController::class, 'getSubUnits']);
});

// Grup Filter
Route::prefix('filter')->middleware(['auth', \App\Http\Middleware\CheckRole::class . ':owner,admin'])->controller(FilterController::class)->group(function () {
    Route::get('/', 'index')->name('filter');
});

/*
|--------------------------------------------------------------------------
| Grup Loket
|--------------------------------------------------------------------------
| Sebelumnya prefix('loket') didaftarkan TANPA middleware sama sekali,
| menimpa proteksi role pada '/loket' di atas. Sekarang seluruh grup
| memakai middleware ['auth', role] yang sama.
*/
Route::prefix('loket')->middleware(['auth', \App\Http\Middleware\Auth\CheckRole::class . ':owner,pelayanan,kapus'])
    ->group(function () {
        Route::get('/', [LoketController::class, 'index'])->name('loket.index');
        Route::get('/data', [LoketController::class, 'ajaxList'])->name('loket.data');
        Route::get('/pasien', [LoketController::class, 'create'])->name('loket.pasien');
        Route::post('/pasien', [LoketController::class, 'store'])->name('loket.pasien.store');
        Route::get('/search', [PasienController::class, 'index'])->name('loket.search');
        Route::get('/edit/{id}', [PasienController::class, 'edit'])->name('loket.edit');
        Route::post('/update/{id}', [PasienController::class, 'update'])->name('pasien.update');
        Route::post('/register', [LoketController::class, 'register'])->name('loket.register');

        // API untuk master data wilayah
        Route::get('/api/provinsi', [LoketController::class, 'getProvinsiList'])->name('loket.api.provinsi');
        Route::get('/api/kabupaten', [LoketController::class, 'getKabupatenByProvinsi'])->name('loket.api.kabupaten');
        Route::get('/api/kecamatan', [LoketController::class, 'getKecamatanList'])->name('loket.api.kecamatan');
        Route::get('/api/kelurahan', [LoketController::class, 'getKelurahanByKecamatan'])->name('loket.api.kelurahan');
        Route::get('/api/poli-by-jenis', [LoketController::class, 'getPoliByJenisKunjungan'])->name('loket.api.poli-by-jenis');

        // API untuk master data unit
        Route::get('/api/kategori-unit', [LoketController::class, 'getKategoriUnit'])->name('loket.api.kategori-unit');
        Route::get('/api/unit-list/{kategoriUnitId}', [LoketController::class, 'unitList'])->name('loket.api.unit-list');
        Route::get('/api/unit/{id}', [LoketController::class, 'getDataUnitById'])->name('loket.api.unit');
        Route::get('/api/wilayah', [LoketController::class, 'getWilayah'])->name('loket.api.wilayah');
        Route::get('/api/puskesmas', [LoketController::class, 'getPuskesmas'])->name('loket.api.puskesmas');
        Route::get('/api/provider', [LoketController::class, 'getProvider'])->name('loket.api.provider');
        Route::get('/api/poli', [LoketController::class, 'getPoli'])->name('loket.api.poli');

        // API untuk pencarian pasien
        Route::get('/api/pasien/search', [LoketController::class, 'apiSearch'])->name('api.pasien.search');
        Route::get('/api/check-jenis-pengunjung', [LoketController::class, 'checkJenisPengunjung'])->name('loket.api.check-jenis-pengunjung');
        Route::get('/api/wilayah-otomatis', [LoketController::class, 'getWilayahOtomatis'])->name('loket.api.wilayah-otomatis');

        // Route untuk halaman pasien
        Route::get('/pasien/{id}', [LoketController::class, 'pasien'])->name('loket.pasien.show');
        Route::get('/cetak-kartu/{id}', [LoketController::class, 'cetak_kartu'])->name('loket.cetak_kartu');
        Route::get('/gen-barcode/{NO_MR}', [LoketController::class, 'gen_barcode'])->name('loket.gen_barcode');

        // Route untuk operasi CRUD
        Route::post('/simpan', [LoketController::class, 'simpan'])->name('loket.simpan');
        Route::post('/update', [LoketController::class, 'update'])->name('loket.update');
        Route::delete('/hapus/{id}', [LoketController::class, 'hapus'])->name('loket.hapus');

        // Route untuk validasi
        Route::get('/cekrapid/{pasienId}/{tglKunjungan}', [LoketController::class, 'cekrapid'])->name('loket.cekrapid');
        Route::get('/cek_beda_provider/{pasienId}/{tglKunjungan}', [LoketController::class, 'cek_beda_provider'])->name('loket.cek_beda_provider');

        Route::get('/lap_reg_kunj_pas/{is_html}/{unit}/{unit_details}/{tgl_awal}/{tgl_akhir}/{kel?}/{pusk?}', [LoketController::class, 'lap_reg_kunj_pas'])->name('loket.report.reg_kunj');
    });

// Grup Templete (halaman contoh komponen UI -- tidak berisi data pasien, tapi tetap dikunci login)
Route::prefix('templete')->middleware(['auth', 'role:owner,loket,pelayanan,kapus'])->group(function () {
    Route::get('/button', fn() => Inertia::render('Templete/Button'))->name('templete.button');
    Route::get('/form', fn() => Inertia::render('Templete/Form'))->name('templete.form');
    Route::get('/table', fn() => Inertia::render('Templete/Table'))->name('templete.table');
    Route::get('/card', fn() => Inertia::render('Templete/Card'))->name('templete.card');
    Route::get('/pagination', fn() => Inertia::render('Templete/Pagination'))->name('templete.pagination');
});

// Grup Laporan (sudah punya middleware yang benar di file asli -- dipertahankan)
Route::prefix('laporan')
    ->middleware(['auth', 'role:owner,loket,pelayanan,kapus'])
    ->group(function () {
        Route::get('loket', [LaporanLoketController::class, 'index'])->name('laporan.loket');
        Route::get('loket/tampilkan', [LaporanLoketController::class, 'tampil'])->name('laporan.loket.tampilkan-laporan-loket');

        Route::get('/rujukan', [RujukanController::class, 'index'])->name('laporan.rujukan');
        Route::inertia('umum', 'Laporan/Umum/Umum')->name('laporan.umum');
        Route::inertia('gigi', 'Laporan/Gigi/Gigi')->name('laporan.gigi');
        Route::inertia('kia', 'Laporan/Kia/Kia')->name('laporan.kia');
        Route::inertia('lab', 'Laporan/Lab/Lab')->name('laporan.lab');
        Route::match(['get', 'post'], '/laporan/kb', [KbController::class, 'index'])->name('laporan.kb');
        Route::get('/ugd', [UgdController::class, 'index'])->name('laporan.ugd');
        Route::inertia('rawat-inap', 'Laporan/Rawat-inap/Rawat-inap')->name('laporan.rawat-inap');
        Route::inertia('kunjungan-sehat', 'Laporan/KunjunganSehat/Index')->name('laporan.kunjungan-sehat');
        Route::get('/sanitasi', [SanitasiController::class, 'index'])->name('laporan.sanitasi');
        Route::get('/sanitasi/register', [SanitasiController::class, 'registerSanitasi'])->name('laporan.sanitasi.register');
        Route::get('/sanitasi/sanitasi', [SanitasiController::class, 'laporanSanitasi'])->name('laporan.sanitasi.laporan');
        Route::get('/sanitasi/kasus', [SanitasiController::class, 'laporanKasus'])->name('laporan.sanitasi.kasus');
    });


Route::prefix('mal-sehat')->name('mal-sehat.')->middleware(['auth', \App\Http\Middleware\Auth\CheckRole::class . ':laborat,owner,pelayanan'])->group(function () {
    Route::inertia('/', 'MalSehat/Index')->name('index');

    // Kesling
    Route::prefix('kesling')->name('kesling.')->group(function () {
        Route::inertia('/', 'MalSehat/Kesling/Index')->name('index');
        Route::inertia('konseling-sanitasi', 'MalSehat/Kesling/KonselingSanitasi')->name('konseling');
        Route::inertia('pengukuran-kebugaran-haji', 'MalSehat/Kesling/PengukuranKebugaranHaji')->name('haji');
        Route::inertia('pengukuran-kebugaran-anak', 'MalSehat/Kesling/PengukuranKebugaranAnak')->name('anak');
        Route::inertia('detail/{noUrut}', 'MalSehat/Kesling/DetailKonselingSanitasi')->name('detail');
    });

    // Kesga
    Route::prefix('kesga')->name('kesga.')->group(function () {
        Route::inertia('/', 'MalSehat/Kesga/Index')->name('index');
        Route::inertia('konselingcatin', 'MalSehat/Kesga/KonselingCatin')->name('konselingcatin');
        Route::inertia('konselinghaji', 'MalSehat/Kesga/KonselingHaji')->name('konselinghaji');
        Route::inertia('konselingimunisasi', 'MalSehat/Kesga/KonselingImunisasi')->name('konselingimunisasi');
        Route::inertia('konselinganak', 'MalSehat/Kesga/KonselingAnak')->name('konselinganak');
        Route::inertia('konselingibu', 'MalSehat/Kesga/KonselingIbu')->name('konselingibu');
        Route::inertia('konselingkb', 'MalSehat/Kesga/KonselingKB')->name('konselingkb');
        Route::inertia('konsultasigizi', 'MalSehat/Kesga/KonsultasiGizi')->name('konsultasigizi');
        Route::inertia('konsultasilansia', 'MalSehat/Kesga/KonsultasiLansia')->name('konsultasilansia');
    });

    // PTM
    Route::prefix('ptm')->name('ptm.')->group(function () {
        Route::inertia('/', 'MalSehat/PTM/Index')->name('index');
        Route::inertia('dashboard', 'MalSehat/PTM/Dashboard')->name('dashboard');
        Route::inertia('konselingberhentimerokok', 'MalSehat/PTM/KonselingBerhentiMerokok')->name('konselingberhentimerokok');
        Route::inertia('skriningfaktorrisiko', 'MalSehat/PTM/SkriningFaktorRisiko')->name('skriningfaktorrisiko');
    });

    // P3M
    Route::prefix('p3m')->name('p3m.')->group(function () {
        Route::inertia('/', 'MalSehat/P3M/Index')->name('index');
        Route::inertia('konselinghivaids', 'MalSehat/P3M/KonselingHivAids')->name('konselinghivaids');
        Route::inertia('konselinglroa', 'MalSehat/P3M/KonselingLROA')->name('konselinglroa');
        Route::inertia('konselingtb', 'MalSehat/P3M/KonselingPenyakitTB')->name('konselingtb');
    });

    // Yankes Primer
    Route::prefix('yankes-primer')->name('yankes-primer.')->group(function () {
        Route::inertia('/', 'MalSehat/YankesPrimer/Index')->name('index');
    });

    // Farmasi
    Route::prefix('farmasi')->name('farmasi.')->group(function () {
        Route::inertia('/', 'MalSehat/Farmasi/Index')->name('index');
    });

    // Biakes
    Route::prefix('biakes')->name('biakes.')->group(function () {
        Route::inertia('/', 'MalSehat/Biakes/Index')->name('index');
        Route::get('pembiayaanjaminansehat', [\App\Http\Controllers\MalSehat\BiakesController::class, 'pembiayaanJaminanSehat'])
            ->name('pembiayaanjaminansehat');
    });

    // Promkes
    Route::prefix('promkes')->name('promkes.')->group(function () {
        Route::inertia('/', 'MalSehat/Promkes/Index')->name('index');
        Route::get('kesehatanpeduliremaja', [\App\Http\Controllers\MalSehat\PromkesController::class, 'kesehatanPeduliRemaja'])
            ->name('kesehatanpeduliremaja');
        Route::get('kesehatanpeduliremaja/pelayanan/{no_mr}', [\App\Http\Controllers\MalSehat\PromkesController::class, 'pelayanan'])
            ->name('kesehatanpeduliremaja.pelayanan');
        Route::get('diagnosa/list', [\App\Http\Controllers\MalSehat\PromkesController::class, 'getDiagnosa'])
            ->name('diagnosa.list');
        Route::get('tindakan/list', [\App\Http\Controllers\MalSehat\PromkesController::class, 'getTindakan'])
            ->name('tindakan.list');
        Route::get('surat-keterangan/{no_mr}', [\App\Http\Controllers\MalSehat\PromkesController::class, 'suratKeterangan'])
            ->name('suratketerangan');
        Route::get('surat-rujukan/{no_mr}', [\App\Http\Controllers\MalSehat\PromkesController::class, 'suratRujukan'])
            ->name('suratrujukan');
        Route::get('promkes/providers', [\App\Http\Controllers\MalSehat\PromkesController::class, 'getProviders'])
            ->name('promkes.providers');
        Route::get('riwayat-pasien/{no_mr}', [\App\Http\Controllers\MalSehat\PromkesController::class, 'riwayatPasien'])
            ->name('riwayatpasien');
    });

    // Surat Keterangan API
    Route::prefix('promkes/surat-keterangan')->group(function () {
        Route::get('/list/{no_mr}', [\App\Http\Controllers\MalSehat\PromkesController::class, 'getSuratKeterangan']);
        Route::post('/store', [\App\Http\Controllers\MalSehat\PromkesController::class, 'storeSuratKeterangan']);
        Route::put('/update/{id}', [\App\Http\Controllers\MalSehat\PromkesController::class, 'updateSuratKeterangan']);
        Route::delete('/delete/{id}', [\App\Http\Controllers\MalSehat\PromkesController::class, 'deleteSuratKeterangan']);
    });

    // Lain-lain
    Route::inertia('home-visit', 'MalSehat/HomeVisit/Index')->name('home-visit');
    Route::inertia('sehat', 'MalSehat/Sehat/Index')->name('sehat');
    Route::inertia('sehat/pelayanan', 'MalSehat/Sehat/Pelayanan')->name('sehat.pelayanan');
    Route::inertia('rapid-test', 'MalSehat/RapidTest/Index')->name('rapid-test');
});

Route::prefix('ruang_layanan/simpus/kunjungan-online')
    ->name('kunj-online.')
    ->middleware(['auth', \App\Http\Middleware\Auth\CheckRole::class . ':laborat,owner,pelayanan'])
    ->group(function () {
        Route::get('/{idPoli?}/{klaster?}', [KunjOnlineController::class, 'index'])->name('index');
        Route::get('/pelayanan/{id}/{idPoli}/{idPelayanan}', [KunjOnlineController::class, 'pelayanan'])->name('pelayanan');
        Route::post('/pelayanan/anamnesa', [KunjOnlineController::class, 'setAnamnesa'])->name('setAnamnesa');
        Route::post('/pelayanan/anamnesa/objective', [KunjOnlineController::class, 'setAnamnesaObjective'])->name('setAnamnesaObjective');
        Route::post('/pelayanan/mulaiPelayanan', [KunjOnlineController::class, 'mulaiPemeriksaanPasien'])->name('mulai-pemeriksaan-pasien');
        Route::post('/pelayanan/diagnosa-medis', [KunjOnlineController::class, 'setDiagnosaMedis'])->name('diagnosa-medis');
        Route::get('/surat-rujukan/{id}', [KunjOnlineController::class, 'suratRujukan'])->name('surat-rujukan');
        Route::get('/{id}/surat-rujukan/create', [KunjOnlineController::class, 'createSuratRujukan'])->name('surat-rujukan.create');
        Route::post('/{id}/surat-rujukan', [KunjOnlineController::class, 'storeSuratRujukan'])->name('surat-rujukan.store');
        Route::get('/{id}/riwayat-kesehatan', [KunjOnlineController::class, 'riwayatKesehatan'])->name('riwayat-kesehatan');
        Route::get('/{id}/cppt', [KunjOnlineController::class, 'cppt'])->name('cppt');
    });

Route::prefix('ruang_layanan')->middleware(['auth', \App\Http\Middleware\Auth\CheckRole::class . ':owner,pelayanan,kapus'])
    ->group(function () {
        Route::get('simpus/popUpFormRujukLanjut', [PoliBpUmumController::class, 'popUpFormRujukLanjut'])->name('ruang-layanan.popUpFormRujukLanjut');

        Route::get('/ruang-layanan/export-excel', [SkriningPTMExportController::class, 'export'])
            ->name('ruang-layanan.export-excel');
        Route::get('simpus/register-ptm/', [RegisterPTMController::class, 'index'])->name('ruang-layanan.register-ptm');

        Route::get('/ruang-layanan/export-register', [RegisterExportController::class, 'exportRegister'])
            ->name('ptm.export-register');
        Route::get('/ptm/export-laporanPTM', [LaporanPTMController::class, 'download'])
            ->name('ptm.export-laporanPTM');

        // Skrining PTM
        Route::get('/simpus/skriningptm', [SkriningPTMController::class, 'index'])->name('ruang-layanan.ptm');
        Route::get('/simpus/skrining-ptm/pelayanan/{id}/{idPoli}/{idPelayanan}', [SkriningPTMController::class, 'pelayanan'])->name('ruang-layanan.skrining-ptm');
        Route::post('/simpus/skrining-ptm/update-status', [SkriningPTMController::class, 'updateStatus'])
            ->name('pelayanan.update-status');
        Route::post('/simpus/skrining-ptm/akhiri-pelayanan', [SkriningPTMController::class, 'akhirPelayanan'])
            ->name('pelayanan.akhiri-pelayanan');
        Route::post('/skrining-ptm/tindakan/simpan', [SkriningPTMController::class, 'simpanTindakan'])
            ->name('ptm.tindakan-simpan');
        Route::post('/skrining-ptm/edukasi/simpan', [SkriningPTMController::class, 'addEdukasi'])
            ->name('ptm.edukasi-simpan');
        Route::delete('/ptm/tindakan/{id}', [SkriningPTMController::class, 'tindakanHapus'])
            ->name('ptm.tindakan-hapus');
        Route::delete('/ptm/resep-hapus/{id}', [SkriningPTMController::class, 'deleteResep'])
            ->name('pelayanan.hapus-resep-ptm');
        Route::post('/simpus/skrining-ptm/tambah-kunjungan', [SkriningPTMController::class, 'tambahKunjunganPTM'])
            ->name('pelayanan.tambah-kunjungan-ptm');
        Route::post('/simpus/skrining-ptm/simpan-risiko', [SkriningPTMController::class, 'addFaktorRisiko'])->name('pelayanan.simpan-risiko-ptm');
        Route::post('/simpus/skrining-ptm/simpan-obesitas', [SkriningPTMController::class, 'addPemeriksaanObesitas'])
            ->name('pelayanan.simpan-obesitas');
        Route::post('/simpus/skrining-ptm/simpan-hipertensi', [SkriningPTMController::class, 'addPemeriksaanHipertensi'])
            ->name('pelayanan.simpan-hipertensi');
        Route::post('/simpus/skrining-ptm/simpan-diabetes', [SkriningPTMController::class, 'addPemeriksaanDiabetes'])
            ->name('pelayanan.simpan-diabetes');
        Route::post('/simpus/skrining-ptm/simpan-profilLipid', [SkriningPTMController::class, 'addPemeriksaanLipid'])
            ->name('pelayanan.simpan-profilLipid');
        Route::post('/simpus/skrining-ptm/simpan-asamUrat', [SkriningPTMController::class, 'addPemeriksaanAsamUrat'])
            ->name('pelayanan.simpan-asamUrat');
        Route::post('/simpus/skrining-ptm/simpan-pemeriksaan', [SkriningPTMController::class, 'addPemeriksaanIndera'])
            ->name('pelayanan.simpan-gangguan-indera');
        Route::post('/simpus/skrining-ptm/simpan-resep', [SkriningPTMController::class, 'simpanResepObat'])
            ->name('pelayanan.simpan-resep-ptm');
        Route::post('/simpus/skrining-ptm/simpan-status', [SkriningPTMController::class, 'addStatusPasien'])
            ->name('pelayanan.status-pasien-ptm');
        Route::post('/simpus/skrining-ptm/simpan-serviks', [SkriningPTMController::class, 'addPemeriksaanKanker'])
            ->name('pelayanan.simpan-serviks');
        Route::post('/simpus/skrining-ptm/simpan-paru', [SkriningPTMController::class, 'addPemeriksaanParu'])
            ->name('pelayanan.simpan-paru');
        Route::post('/simpus/skrining-ptm/simpan-thalasemia', [SkriningPTMController::class, 'addPemeriksaanThalasemia'])
            ->name('pelayanan.simpan-thalasemia');
        Route::post('/simpus/skrining-ptm/simpan-kolorektal', [SkriningPTMController::class, 'addPemeriksaanKolorektal'])
            ->name('pelayanan.simpan-kolorektal');
        Route::post('/simpus/skrining-ptm/simpan-ekg', [SkriningPTMController::class, 'addPemeriksaanEKG'])
            ->name('pelayanan.simpan-ekg');

        Route::get('simpus/riwayat-ptm', [RiwayatPTMController::class, 'index'])->name('ruang-layanan.riwayat-ptm');
        Route::get('simpus/laporan-ptm/', [DashboardPTMController::class, 'laporanPTM'])->name('ruang-layanan.laporan-ptm');
        Route::get('simpus/riwayat-ptm/download/register-ptm', [RegisterPTMController::class, 'downloadRegisterPTM'])
            ->name('ruang-layanan.download-register');
        Route::get('simpus/riwayat-ptm/download/pdf', [RiwayatPTMController::class, 'downloadPDF'])->name('ruang-layanan.riwayat-ptm-pdf');
        Route::get('simpus/riwayat-ptm/download/lembar-ptm', [RiwayatPTMController::class, 'downloadLembarPTM'])
            ->name('ruang-layanan.lembar-ptm');

        Route::post('/satusehat/encounter/{idSkrining}', [SatuSehatController::class, 'sendEncounter'])
            ->name('satusehat.encounter');
        Route::post('/satusehat/observation/{idSkrining}', [SatuSehatController::class, 'sendRiskFactor'])
            ->name('satusehat.observation');
        Route::post('/satusehat/hipertensi/{idSkrining}', [SatuSehatController::class, 'sendHipertensi'])
            ->name('satusehat.hipertensi');
        Route::post('/satusehat/obesitas/{idSkrining}', [SatuSehatController::class, 'sendObesitas'])
            ->name('satusehat.obesitas');
        Route::post('/satusehat/asam-urat/{idSkrining}', [SatuSehatController::class, 'sendAsamUrat'])
            ->name('satusehat.asam-urat');
        Route::post('/satusehat/diabetes/{idSkrining}', [SatuSehatController::class, 'sendDiabetes'])
            ->name('satusehat.diabetes');
        Route::post('/satusehat/profil-lipid/{idSkrining}', [SatuSehatController::class, 'sendProfilLipid'])
            ->name('satusehat.profil-lipid');
        Route::post('/satusehat/pendengaran/{idSkrining}', [SatuSehatController::class, 'sendGangguanPendengaran'])
            ->name('satusehat.gangguan-pendengaran');
        Route::post('/satusehat/penglihatan/{idSkrining}', [SatuSehatController::class, 'sendGangguanPenglihatan'])
            ->name('satusehat.gangguan-penglihatan');
        Route::post('/satusehat/ekg/{idSkrining}', [SatuSehatController::class, 'sendEKG'])
            ->name('satusehat.send-ekg');
        Route::post('/satusehat/paru/{idSkrining}', [SatuSehatController::class, 'sendKankerParu'])
            ->name('satusehat.send-paru');
        Route::post('/satusehat/kolorektal/{idSkrining}', [SatuSehatController::class, 'sendKolorektal'])
            ->name('satusehat.send-kolorektal');
        Route::post('/satusehat/serviks/{idSkrining}', [SatuSehatController::class, 'sendKankerServiks'])
            ->name('satusehat.kanker-serviks');
        Route::post('/satusehat/thalasemia/{idSkrining}', [SatuSehatController::class, 'sendThalasemia'])
            ->name('satusehat.thalasemia');
        Route::post('/satusehat/diagnosis/{pelayananId}', [SatuSehatController::class, 'sendDiagnosis'])
            ->name('satusehat.diagnosis');
        Route::post('/satusehat/tindakan/{pelayananId}', [SatuSehatController::class, 'sendTindakan'])
            ->name('satusehat.tindakan');
        Route::post('/satusehat/edukasi/{idSkrining}', [SatuSehatController::class, 'sendEdukasi'])
            ->name('satusehat.edukasi');
        Route::post('/satusehat/pengobatan/{idPelayanan}', [SatuSehatController::class, 'sendResepObat'])
            ->name('satusehat.pengobatan');
        Route::post('/satusehat/status-pasien/{idSkrining}', [SatuSehatController::class, 'sendStatusPasien'])
            ->name('satusehat.status-pasien');

        // Simpan rujuk
        Route::post('simpus/pelayanan/simpan-rujuk/{idLoket}/{idPelayanan}', [PoliBpUmumController::class, 'simpanRujukan'])->name('ruang-layanan.simpanRujukan');
        Route::get('simpus/get-pelayanan/{idLoket}/{idPelayanan}', [PoliBpUmumController::class, 'getPelayanan'])->name('ruang-layanan.ambilPelayanan');
        Route::delete('simpus/pelayanan/hapus-rujuk/{idpelayanan}', [PoliBpUmumController::class, 'hapusRujuk'])->name('ruang-layanan.hapusRujuk');
        Route::get('simpus/pelayanan/batal-berobat-jalan/{idLoket}/{idpelayanan}', [PoliBpUmumController::class, 'batalBerobatJalan'])->name('ruang-layanan.batal-berobat-jalan');

        // Menampilkan halaman poli
        Route::get('/simpus/poli', [indexController::class, 'listPoli'])->name('ruang-layanan.poli');
        Route::get('/simpus/poli/{kluster}', [indexController::class, 'listPoliKluster'])->name('ruang-layanan.poli-kluster');
        Route::get('simpus/getJumlahPasienKlusterPoli', [indexController::class, 'totalPasienKlusterAndPoli'])->name('ruang-layanan.totalPasienKlusterAndPoli');

        // Umum
        Route::get('/simpus/{idPoli?}/{kluster?}', [PoliBpUmumController::class, 'index'])->name('ruang-layanan.index');
        Route::get('/simpus/pelayanan/{id}/{idPoli}/{idPelayanan}/{kluster?}', [PoliBpUmumController::class, 'pelayanan'])->name('ruang-layanan.pelayanan');

        // Surat
        Route::get('/simpus/pelayananDetail/surat-rujuk/{idPoli}/{idPelayanan}', [PoliBpUmumController::class, 'suratRujukList'])->name('ruang-layanan.surat-rujuk');
        Route::get('/simpus/pelayananDetail/surat-rujuk-form/{idPoli}/{idPelayanan}', [PoliBpUmumController::class, 'suratRujukForm'])->name('ruang-layanan.surat-rujuk-form');
        Route::post('/simpus/pelayananDetail/simpan-rujukan/{idPoli}', [PoliBpUmumController::class, 'simpanSuratRujuk'])->name('ruang-layanan.simpan-rujukan');
        Route::get('simpus/pelayananDetail/cetak-rujukan/{idSurat}', [PoliBpUmumController::class, 'cetakRujukan'])->name('ruang-layanan.cetak-rujukan');
        Route::get('simpus/pelayananDetail/surat-rujukan-form-edit/{idPoli}/{idPelayanan}/{idSurat}', [PoliBpUmumController::class, 'suratRujukForm'])->name('ruang-layanan.surat-rujuk-form-edit');
        Route::post('simpus/pelayananDetail/update-surat-rujukan/{idPoli}/{idSurat}', [PoliBpUmumController::class, 'simpanSuratRujuk'])->name('ruang-layanan.update-rujukan');
        Route::post('simpus/pelayananDetail/hapus-surat-rujukan/{idSurat}', [PoliBpUmumController::class, 'hapusSuratRujukan'])->name('ruang-layanan.hapus-surat-rujukan');

        Route::inertia('/simpus/umum/form-surat-keterangan', 'Ruang_Layanan/Umum/form_surat_keterangan')->name('ruang-layanan-umum.form-surat-keterangan');

        // Anamnesa
        Route::post('simpus/pelayanan/set-anamnesa-subjective/{idLoket}', [PoliBpUmumController::class, 'setAnamnesaSubjective'])->name('ruang-layanan.setAnamnesaSubjective');
        Route::post('simpus/umum/pelayanan/set-anamnesa-objective/{idAnam}', [PoliBpUmumController::class, 'setAnamnesaObjective'])->name('ruang-layanan.setAnamnesaObjective');

        // Tindakan
        Route::post('simpus/pelayanan/tindakan/{idPoli}/{idLoket}/{idPelayanan}', [PoliBpUmumController::class, 'setTindakan'])->name('ruang-layanan.simpan-Tindakan');
        Route::get('/master-tindakan', [indexController::class, 'paginasiSimpusTindakan'])->name('ruang-layanan.master-tindakan');
        Route::post('simpus/umum/pelayanan/mulaiPelayanan', [PoliBpUmumController::class, 'mulaiPemeriksaanPasien'])->name('ruang-layanan-umum.mulai-pemeriksaan-pasien');

        // Diagnosa
        Route::post('simpus/pelayanan/diagnosa-medis/{idLoket}/{idPelayanan}', [PoliBpUmumController::class, 'setDiagnosaMedis'])->name('ruang-layanan.set-diagnosa-medis');
        Route::post('simpus/diagnosa/diagnosa-keperawatan-simpan/{idLoket}/{idPelayanan}', [PoliBpUmumController::class, 'setDiagnosaKeperawatan'])->name('ruang-layanan.diagnosa-keperawatan');
        Route::get('/api/diagnosa-medis', [PoliBpUmumController::class, 'paginasi'])->name('api.diagnosa-medis');
        Route::delete('simpus/pelayanan/diagnosa-medis/{idDiagnosa}', [PoliBpUmumController::class, 'removeDiagnosaMedis'])->name('ruang-layanan.remove-diagnosa-medis');
        Route::delete('simpus/pelayanan/diagnosa-keperawatan{idDiagnosa}', [PoliBpUmumController::class, 'removeDiagnosaKeperawatan'])->name('ruang-layanan.remove-diagnosa-keperawatan');

        // Gizi
        Route::post('simpus/pelayanan/simpan-gizi/{idLoket}', [PoliBpUmumController::class, 'simpanGizi'])->name('ruang-layanan.simpan-gizi');

        // Simpan Sanitasi
        Route::post('simpus/pelayanan/simpan-sanitasi/{idPelayanan}', [PoliBpUmumController::class, 'simpanSanitasi'])->name('ruang-layanan.simpan-sanitasi');

        // Resep obat (Pengobatan Pasien)
        Route::post('simpus/pelayanan/resep-obat/{idLoket}/{idPelayanan}', [PoliBpUmumController::class, 'setResepObat'])->name('ruang-layanan.set-resep-obat');
        Route::post('simpus/pelayanan/detail-resep-obat/{idResep}/{idObat}', [PoliBpUmumController::class, 'setDetailResepObat'])->name('ruang-layanan.set-detail-resep');
        Route::delete('simpus/hapus-resep-obat/{idResepObat}', [PoliBpUmumController::class, 'hapusResepObat'])->name('ruang-layanan.hapus-resep-obat');
        Route::post('simpus/hapus-detail-resep-obat/{idDetailResepObat}', [PoliBpUmumController::class, 'hapusDetailResepObat'])->name('ruang-layanan.hapus-detail-resep-obat');

        // Suket
        Route::get('simpus/pelayananDetail/surat-keterangan-list/{idPoli}/{idPelayanan}', [PoliBpUmumController::class, 'suketList'])->name('ruang-layanan.surat-keterangan-list');
        Route::get('simpus/pelayananDetail/create-surat-keterangan/{idPoli}/{idPelayanan}', [PoliBpUmumController::class, 'createSuratKeterangan'])->name('ruang-layanan.create-surat-keterangan');
        Route::post('simpus/pelayananDetail/simpan-surat-keterangan/{idPoli}', [PoliBpUmumController::class, 'simpanSuket'])->name('ruang-layanan.simpanSuket');
        Route::get('simpus/pelayananDetail/cetak-surat-keterangan/{idSurat}', [PoliBpUmumController::class, 'cetakSuket'])->name('ruang-layanan.cetak-suket');
        Route::post('simpus/pelayananDetail/hapus-surat-keterangan/{idSurat}', [PoliBpUmumController::class, 'hapusSuket'])->name('ruang-layanan.hapus-suket');
        Route::get('simpus/pelayananDetail/edit-surat-keterangan//{idPoli}/{idPelayanan}/{idSurat}', [PoliBpUmumController::class, 'editSuket'])->name('ruang-layanan.edit-suket');

        Route::post('simpus/update-surat-keterangan', [PoliBpUmumController::class, 'updateSuket'])->name('ruang-layanan.update-suket');
        Route::get('simpus/laborat/{idPoli}/{idLoket}/{idPelayanan}', [PoliBpUmumController::class, 'formLaborat'])->name('ruang-layanan.form-laborat');

        Route::post('simpus/umum/laborat/simpan-permohonan-lab/{idLoket}', [PoliBpUmumController::class, 'simpanPermohonanLab'])->name('ruang-layanan.simpan-permohonan-lab');
        Route::get('simpus/laborat/list-permohonan/{idLoket}', [indexController::class, 'getPermohonanLaborat'])->name('ruang-layanan.getPermonanLab');

        // riwayat pasien
        Route::get('simpus/riwayat-pasien/{idPoli}/{idPasien}', [PoliBpUmumController::class, 'riwayatPasien'])->name('ruang-layanan.riwayat-pasien');

        // UKK
        Route::get('simpus/pop-up/get-ukk/{idLoket}', [PoliBpUmumController::class, 'getUKK'])->name('ruang-layanan.get-ukk');
        Route::post('simpus/simpan-ukk/{idLoket}', [PoliBpUmumController::class, 'simpanUkk'])->name('ruang-layanan.simpan-ukk');

        // CPPT
        Route::get('simpus/cppt/{idPoli}/{idPasien}', [PoliBpUmumController::class, 'getCppt'])->name('ruang-layanan.cppt');

        // Gigi
        Route::get('/simpus/gigi', [PoliGigiController::class, 'index'])->name('ruang-layanan.gigi');
        Route::get('/simpus/gigi/pelayanan/{id}', [PoliGigiController::class, 'pelayanan'])->name('ruang-layanan-gigi.pelayanan');
        Route::post('simpus/gigi/pelayanan/anamnesa-subjective', [PoliGigiController::class, 'setAnamnesaSubjective'])->name('ruang-layanan-gigi.setAnamnesaSubjective');
        Route::post('simpus/gigi/pelayanan/anamnesa-objective', [PoliGigiController::class, 'setAnamnesaObjective'])->name('ruang-layanan-gigi.setAnamnesaObjective');

        // Diagnosa
        Route::post('simpus/gigi/pelayanan/diagnosa-medis', [PoliGigiController::class, 'setDiagnosaMedis'])->name('ruang-layanan-gigi.diagnosa-medis');
        Route::delete('simpus/gigi/pelayanan/diagnosa-medis/{id}', [PoliGigiController::class, 'removeDiagnosaMedis'])->name('ruang-layanan-gigi.remove-diagnosa-medis');

        Route::post('simpus/gigi/pelayanan/planning-tindakan', [PoliGigiController::class, 'setPlanningTindakan'])->name('ruang-layanan-gigi.set-PlanningTindakan');
        Route::delete('simpus/gigi/pelayanan/planning-tindakan/{id}', [PoliGigiController::class, 'removePlanningTindakan'])->name('ruang-layanan-gigi.remove-data-tindakan');
        Route::post('simpus/gigi/pelayanan/planning-pengobatan', [PoliGigiController::class, 'setPlanningPengobatan'])->name('ruang-layanan-gigi.set-PlanningPengobatan');
        Route::post('simpus/gigi/pelayanan/planning-pengobatan-detail', [PoliGigiController::class, 'setPlanningPengobatandetail'])->name('ruang-layanan-gigi.set-PlanningPengobatanDetail');
        Route::get('/master-obat', [indexController::class, 'MasterObat'])->name('ruang-layanan.master-obat');

        // Ranap
        Route::inertia('/simpus/ranap', 'Ruang_Layanan/UGD/pasien_poli')->name('ruang-layanan.ranap');

        // Sanitasi
        Route::inertia('/simpus/sanitasi', 'Ruang_Layanan/Sanitasi/pasien_poli')->name('ruang-layanan.sanitasi');
        Route::inertia('/simpus/sanitasi/pelayanan', 'Ruang_Layanan/Sanitasi/pelayanan')->name('ruang-layanan.sanitasi.pelayanan');

        // Gizi
        Route::inertia('/simpus/gizi', 'Ruang_Layanan/Gizi/pasien_poli')->name('ruang-layanan.gizi');
        Route::inertia('/simpus/gizi/pelayanan', 'Ruang_Layanan/Gizi/pelayanan')->name('ruang-layanan.gizi.pelayanan');

        // Rawat Inap
        Route::inertia('/simpus/rawat-inap', 'Ruang_Layanan/RawatInap/index')->name('ruang-layanan.rawat-inap');
        Route::inertia('/simpus/rawat-inap/penerimaan-pasien', 'Ruang_Layanan/RawatInap/PenerimaanPasien/pasien_poli')->name('ruang-layanan.rawat-inap.penerimaan-pasien');
        Route::inertia('/simpus/rawat-inap/perawatan', 'Ruang_Layanan/RawatInap/DataKeperawatan/DataRanapKeperawatan')->name('ruang-layanan.rawat-inap.perawatan');
        Route::inertia('/simpus/rawat-inap/pengeluaran', 'Ruang_Layanan/RawatInap/PasienKeluar/DataPasienKeluar')->name('ruang-layanan.rawat-inap.pengeluaran');
    });

/*
|--------------------------------------------------------------------------
| Laborat
|--------------------------------------------------------------------------
| Route-route ini sudah punya auth+role di file asli. Duplikat definisi
| (paramSubheaders, paramSimpan, hapusTindakan, param.browse,
| param.simpanTerpilih muncul dua kali) sudah di-dedupe. Dua endpoint yang
| tadinya TANPA middleware (hapusSemuaTindakan, param.categories) sekarang
| ikut dikunci.
*/
Route::middleware(['auth', \App\Http\Middleware\Auth\CheckRole::class . ':laborat,owner'])->group(function () {
    Route::get('/simpus/laborat', [LaboratoriumController::class, 'index'])->name('ruang-layanan.laborat');
    Route::get('/simpus/laborat/pemeriksaan/{loketId}', [LaboratoriumController::class, 'pemeriksaan'])->name('ruang-layanan.laborat.pemeriksaan');
    Route::post('/simpus/laborat/set-waktu-sample', [LaboratoriumController::class, 'setWaktuSample'])->name('ruang-layanan.laborat.setWaktuSample');
    Route::post('/simpus/laborat/update-nilai', [LaboratoriumController::class, 'updateNilaiLab'])->name('ruang-layanan.laborat.updateNilaiLab');

    // === endpoint untuk modal master/paket & cetak ===
    Route::get('/simpus/laborat/paginasi-master-pemeriksaan', [LaboratoriumController::class, 'paginasiMasterPemeriksaan'])->name('ruang-layanan.laborat.paginasiMasterPemeriksaan');
    Route::post('/simpus/laborat/permohonan/simpan', [LaboratoriumController::class, 'simpanPermohonan'])->name('ruang-layanan.laborat.simpanPermohonan');
    Route::post('/simpus/laborat/pemeriksaan/simpan', [LaboratoriumController::class, 'pemeriksaanSimpan'])->name('ruang-layanan.laborat.pemeriksaanSimpan');
    Route::post('/simpus/laborat/pemeriksaan/paket/{paket}', [LaboratoriumController::class, 'paketPemeriksaanSimpan'])->name('ruang-layanan.laborat.paketPemeriksaanSimpan');
    Route::get('/simpus/laborat/detail/{idPermohonan}', [LaboratoriumController::class, 'detail'])->name('ruang-layanan.laborat.detail');

    // Paket dari parameter_uji
    Route::get('/simpus/laborat/param/headers', [LaboratoriumController::class, 'paramHeaders'])->name('ruang-layanan.laborat.param.headers');
    Route::get('/simpus/laborat/param/{header}/subheaders', [LaboratoriumController::class, 'paramSubheaders'])->whereNumber('header')->name('ruang-layanan.laborat.param.subheaders');
    Route::post('/simpus/laborat/param/{header}/simpan', [LaboratoriumController::class, 'paramSimpan'])->whereNumber('header')->name('ruang-layanan.laborat.param.simpan');
    Route::post('/simpus/laborat/tindakan/hapus', [LaboratoriumController::class, 'hapusTindakan'])->name('ruang-layanan.laborat.hapusTindakan');

    // LIST semua parameter_uji (bisa search + filter paket) — paginated
    Route::get('/simpus/laborat/param/browse', [LaboratoriumController::class, 'paramBrowse'])->name('ruang-layanan.laborat.param.browse');

    // Simpan pilihan manual (by id_parameter[])
    Route::post('/simpus/laborat/param/simpan-terpilih', [LaboratoriumController::class, 'paramSimpanTerpilih'])->name('ruang-layanan.laborat.param.simpanTerpilih');

    // GET: daftar kategori + jumlah item per kategori
    Route::get('/ruang_layanan/laborat/param/categories', [LaboratoriumController::class, 'paramCategories'])->name('ruang-layanan.laborat.param.categories');

    Route::post('/ruang-layanan/laborat/hapus-semua', [LaboratoriumController::class, 'hapusSemuaTindakan'])->name('ruang-layanan.laborat.hapusSemuaTindakan');
});

/*
|--------------------------------------------------------------------------
| Owner
|--------------------------------------------------------------------------
| api/owner dan auth/password/force-update tadinya didaftarkan DUA KALI
| dengan isi identik -- duplikat kedua sudah dihapus. Endpoint destroyUser
| tadinya tidak punya middleware auth/role sama sekali (siapa pun bisa
| menghapus user) -- sekarang wajib login + role owner/kapus.
*/
Route::get('/owner', [PanelController::class, 'index'])
    ->middleware(['auth', \App\Http\Middleware\Auth\CheckRole::class . ':owner'])
    ->name('owner.panel');

Route::prefix('api/owner')
    ->middleware(['auth', \App\Http\Middleware\Auth\CheckRole::class . ':owner'])
    ->group(function () {
        Route::get('/roles', [OwnerController::class, 'roles']);
        Route::get('/puskesmas', [OwnerController::class, 'puskesmas']);
        Route::get('/users', [OwnerController::class, 'users']);
        Route::post('/users', [OwnerController::class, 'storeUser']); // tambah user
        Route::patch('/users/{id}/roles', [OwnerController::class, 'updateRoles']);
        Route::post('/users/{id}/force-logout', [OwnerController::class, 'forceLogout']);
        Route::get('/online-users', [OwnerController::class, 'onlineUsers']); // opsional
    });

Route::post('/auth/password/force-update', [PasswordForceController::class, 'update'])
    ->middleware(['auth'])
    ->name('auth.password.force-update');

// =================== JSON + PAGE: Log Hapus Pasien (Loket) ===================
Route::middleware(['auth', \App\Http\Middleware\Auth\CheckRole::class . ':owner,kapus'])
    ->group(function () {
        // JSON endpoint (dipakai halaman khusus log)
        Route::get('/owner/loket-delete-logs', [OwnerLogController::class, 'loketDeletes'])
            ->middleware('throttle:60,1')
            ->name('owner.loket-delete-logs');

        // Halaman khusus log (Inertia)
        Route::get('/owner/logs/loket-delete', function () {
            return Inertia::render('Owner/LoketDeleteLogs');
        })->name('owner.logs.loket');

        Route::patch('/users/{id}/password-changed', [OwnerController::class, 'updatePasswordChanged'])
            ->name('owner.users.password_changed');

        // Sebelumnya endpoint ini TIDAK punya middleware auth/role sama sekali --
        // siapa pun tanpa login bisa menghapus role, sesi, dan akun pengguna lain.
        // Sekarang wajib login + role owner/kapus (masuk ke grup middleware di atas).
        Route::delete('/users/{id}', [OwnerController::class, 'destroyUser'])
            ->name('owner.users.destroy');
    });

Route::match(['GET', 'POST'], '/_csrf-debug', function (Request $r) {
    return response()->json([
        'method' => $r->method(),
        'has_cookie_xsrf' => $r->cookies->has('XSRF-TOKEN'),
        'has_cookie_sess' => $r->cookies->has(config('session.cookie')),
        'token_input' => $r->input('_token') ? 'YES' : 'NO',
        'token_header' => $r->header('X-CSRF-TOKEN') ? 'YES' : 'NO',
        'token_x_xsrf' => $r->header('X-XSRF-TOKEN') ? 'YES' : 'NO',
        'session_driver' => config('session.driver'),
        'session_id' => $r->session()->getId(),
        'host' => $r->getHost(),
        'origin' => $r->headers->get('Origin'),
        'referer' => $r->headers->get('Referer'),
    ]);
})->middleware('web');
