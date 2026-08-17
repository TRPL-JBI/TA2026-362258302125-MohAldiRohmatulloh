<?php

namespace App\Http\Controllers\RuangLayanan;

use App\Http\Controllers\Controller;
use App\Models\RuangLayanan\DataMasterUnitDetail;
use App\Models\RuangLayanan\KIA\Alergi;
use App\Models\RuangLayanan\KIA\KunjunganAnc;
use App\Models\RuangLayanan\MasterDiagnosaKasus;
use App\Models\RuangLayanan\MasterAlergi;
use App\Models\RuangLayanan\MasterObstetri;
use App\Models\RuangLayanan\MasterRiwayat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Models\RuangLayanan\SimpusDataDiagnosa;
use App\Models\RuangLayanan\SimpusDiagnosa;
use App\Models\RuangLayanan\SimpusDiagnosaaa;
use App\Models\RuangLayanan\SimpusLoket;
use App\Models\RuangLayanan\SimpusTindakan;
use App\Models\RuangLayanan\tindakan;
use Illuminate\Support\Facades\Request as FacadesRequest;
use Inertia\Inertia;
class PNCController extends Controller
{
    public function index()
    {
        $userAuth = Auth::user();
        $DataUnit = DataMasterUnitDetail::with('DataMasterUnit')
            ->where('id_unit', $userAuth->unit)
            ->orderBy('id_kategori')
            ->get();

        // dd($DataUnit);

        $DataPasien = DB::table('simpus_pelayanan as pel')
            ->join('simpus_loket as l', 'pel.loketId', '=', 'l.idLoket')
            ->join('simpus_pasien as p', 'l.pasienId', '=', 'p.ID')
            ->join('simpus_poli_fktp as poli', 'poli.kdPoli', '=', 'l.kdPoli')

            ->leftJoin('setup_kel as kel', function ($join) {
                $join->on('p.NO_KEL', '=', 'kel.NO_KEL')
                    ->on('p.NO_KEC', '=', 'kel.NO_KEC')
                    ->on('p.NO_KAB', '=', 'kel.NO_KAB')
                    ->on('p.NO_PROP', '=', 'kel.NO_PROP');
            })

            ->leftJoin('setup_kec as kec', function ($join) {
                $join->on('p.NO_KEC', '=', 'kec.NO_KEC')
                    ->on('p.NO_KAB', '=', 'kec.NO_KAB')
                    ->on('p.NO_PROP', '=', 'kec.NO_PROP');
            })

            ->leftJoin('setup_kab as kab', function ($join) {
                $join->on('p.NO_KAB', '=', 'kab.NO_KAB')
                    ->on('p.NO_PROP', '=', 'kab.NO_PROP');
            })

            ->leftJoin('setup_prop as prop', 'p.NO_PROP', '=', 'prop.NO_PROP')

            ->where('l.kdPoli', '003')

            ->select(
                'pel.idpelayanan',
                'pel.tglPelayanan',
                'pel.sudahDilayani',
                'p.NO_MR',
                'p.NAMA_LGKP',
                'p.NIK',
                'kel.nama_kel',
                'kec.nama_kec',
                'kab.nama_kab',
                'prop.nama_prop',
                'poli.nmPoli',
                'p.alamat',
                'p.no_rt',
                'p.no_rw',
                'l.tglKunjungan',
                'l.idLoket',
                'l.kdPoli'
            )
            ->get();




        // dd($DataPasien);

        return Inertia::render('Ruang_Layanan/KIA/PNC/Index', [
            'DataUnit' => $DataUnit,
            'DataPasien' => $DataPasien,
        ]);
    }

    public function pelayanan($id, $idPoli, $idPelayanan)
    {
        $DataPasien = DB::table('simpus_loket as l')
            ->join('simpus_pasien as p', 'l.pasienId', '=', 'p.ID')
            ->join('simpus_pelayanan as pel', 'l.idLoket', '=', 'pel.loketId')
            ->join('simpus_poli_fktp as poli', 'poli.kdPoli', '=', 'l.kdPoli')
            ->leftJoin('setup_kel as kel', function ($join) {
                $join->on('p.NO_KEL', '=', 'kel.NO_KEL')
                    ->on('p.NO_KEC', '=', 'kel.NO_KEC')
                    ->on('p.NO_KAB', '=', 'kel.NO_KAB')
                    ->on('p.NO_PROP', '=', 'kel.NO_PROP');
            })
            ->leftJoin('setup_kec as kec', function ($join) {
                $join->on('p.NO_KEC', '=', 'kec.NO_KEC')
                    ->on('p.NO_KAB', '=', 'kec.NO_KAB')
                    ->on('p.NO_PROP', '=', 'kec.NO_PROP');
            })
            ->leftJoin('setup_kab as kab', function ($join) {
                $join->on('p.NO_KAB', '=', 'kab.NO_KAB')
                    ->on('p.NO_PROP', '=', 'kab.NO_PROP');
            })
            ->leftJoin('setup_prop as prop', 'p.NO_PROP', '=', 'prop.NO_PROP')
            ->where('l.kdPoli', $idPoli)
            ->where('l.idLoket', $id)
            ->select(
                'p.ID',
                'p.NO_MR',
                'p.NAMA_LGKP',
                'p.NIK',
                'kel.nama_kel',
                'kec.nama_kec',
                'kab.nama_kab',
                'prop.nama_prop',
                'poli.nmPoli',
                'l.kdPoli',
                'p.alamat',
                'p.no_rt',
                'p.no_rw',
                'p.jenis_klmin',
                'l.umur',
                'l.umur_bulan',
                'l.umur_hari',
                'l.tglKunjungan',
                'l.idLoket',
                'pel.idPelayanan'
            )
            ->get();

        // dd($DataPasien);
        // ← WAJIB, AGAR TIDAK UNDEFINED

        $diagnosa = SimpusDiagnosaaa::whereNotNull('F3')->get();
        $diagnosaKeperawatan = SimpusDiagnosa::where('kategori', 1)->get();
        $tindakan = tindakan::where('kdPoli', 003)->get();
        $riwayat = MasterRiwayat::all();
        $AlergiMakanan = Alergi::where('category', 1)->get();
        $AlergiObat = Alergi::where('category', 2)->get();
        $KunjunganAnc = KunjunganAnc::all();
        $DataDiagnosa = SimpusDataDiagnosa::all();
        // dd($AlergiObat);
        // dd($riwayat);
        // dd($DataPasien);
        // dd($DiagnosaKeperawatan);
        // dd($diagnosa);
        return Inertia::render('Ruang_Layanan/KIA/PNC/Pelayanan', [
            'DataPasien' => $DataPasien,
            'idPelayanan' => $idPelayanan,
            'idPoli' => $idPoli,
            'diagnosa' => $diagnosa,
            'tindakan' => $tindakan,
            'riwayat' => $riwayat,
            'diagnosaKeperawatan' => $diagnosaKeperawatan,
            'AlergiMakanan' => $AlergiMakanan,
            'AlergiObat' => $AlergiObat,
            'KunjunganAnc' => $KunjunganAnc,
            'DataDiagnosa' => $DataDiagnosa,
            // 'DataAnamnesa' => $DataAnamnesa,
            // 'DataKesadaran' => $DataKesadaran,
            // 'DiagnosaKasus' => $DiagnosaKasus,
            // 'MasterAlergi' => $MasterAlergi,
            // 'SimpusDataDiagnosa' => $SimpusDataDiagnosa,
            // 'SimpusTindakan' => $SimpusTindakan
        ]);
    }

    public function setDataDiagnosa(Request $request)
    {
        $diagnosaBaru = SimpusDataDiagnosa::create([
            'kdDiagnosa' => $request->kode_diagnosa,
            'nmDiagnosa' => $request->nama_diagnosa,
            'diagnosaKasus' => $request->kunjungan_khusus,
            'keterangan' => $request->keterangan,
            'kdPoli' => $request->kdPoli,
            'loketId' => $request->loketId,
            'pelayananId' => $request->pelayananId,
        ]);

        return response()->json([
            'success' => true,
            'data' => $diagnosaBaru
        ]);
    }

    public function setDataDiagnosaKep(Request $request)
    {
        $data = SimpusDataDiagnosa::create([
            'kdDiagnosa' => $request->kode_diagnosa,
            'nmDiagnosa' => $request->diagnosaKep,
            'kdPoli' => $request->kdPoli,
            'loketId' => $request->loketId,
            'pelayananId' => $request->pelayananId,
        ]);
        // dd($data);
        return redirect()->back();
    }
    public function hapusDataDiagnosa($id)
    {
        $data = SimpusDataDiagnosa::find($id);

        if (!$data) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan'
            ], 404);
        }

        $data->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil dihapus'
        ]);
    }

}
