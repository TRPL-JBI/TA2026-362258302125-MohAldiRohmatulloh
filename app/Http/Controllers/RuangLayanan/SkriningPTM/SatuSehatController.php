<?php

namespace App\Http\Controllers\RuangLayanan\SkriningPTM;

use App\Http\Controllers\Controller;
use App\Models\RuangLayanan\SimpusDataDiagnosa;
use App\Models\RuangLayanan\SimpusTindakan;
use App\Services\SatuSehatPTM\KankerServiksObservationService;
use Illuminate\Http\Request;
use App\Services\SatuSehatPTM\EncounterService;
use App\Services\SatuSehatPTM\ObservationService;
use App\Services\SatuSehatPTM\QuestionnaireResponseService;
use App\Services\SatuSehatPTM\RiwayatPTMService;
use App\Services\SatuSehatPTM\HipertensiObservationService;
use App\Services\SatuSehatPTM\HipertensiConditionService;
use App\Services\SatuSehatPTM\ObesitasObservationService;
use App\Services\SatuSehatPTM\ObesitasConditionService;
use App\Services\SatuSehatPTM\DiabetesObservationService;
use App\Services\SatuSehatPTM\AsamUratObservationService;
use App\Services\SatuSehatPTM\EkgObservationService;
use App\Services\SatuSehatPTM\GangguanPendengaranObservationService;
use App\Services\SatuSehatPTM\GangguanPenglihatanObservationService;
use App\Services\SatuSehatPTM\KankerParuQuestionnaireService;
use App\Services\SatuSehatPTM\KolorektalQuestionnaireService;
use App\Services\SatuSehatPTM\ProfilLipidObservationService;
use App\Services\SatuSehatPTM\ThalasemiaObservationService;
use App\Services\SatuSehatPTM\DiagnosisConditionService;
use App\Services\SatuSehatPTM\EdukasiProcedureService;
use App\Services\SatuSehatPTM\TindakanProcedureService;
use App\Services\SatuSehatPTM\MedicationRequestService;
use App\Services\SatuSehatPTM\StatusPasienService;

class SatuSehatController extends Controller
{
    public function __construct(
        private EncounterService $encounterService,
        private ObservationService $observationService,
        private QuestionnaireResponseService $questionnaireResponseService,
        private RiwayatPTMService $riwayatPTMService,
        private HipertensiObservationService $hipertensiObservationService,
        private HipertensiConditionService $hipertensiConditionService,
        private ObesitasObservationService $obesitasObservationService,
        private ObesitasConditionService $obesitasConditionService,
        private DiabetesObservationService $diabetesObservationService,
        private AsamUratObservationService $asamUratObservationService,
        private ProfilLipidObservationService $profilLipidObservationService,
        private GangguanPendengaranObservationService $gangguanPendengaranObservationService,
        private GangguanPenglihatanObservationService $gangguanPenglihatanObservationService,
        private EkgObservationService $ekgObservationService,
        private KankerParuQuestionnaireService $kankerParuQuestionnaireService,
        private KolorektalQuestionnaireService $kolorektalQuestionnaireService,
        private KankerServiksObservationService $kankerServiksObservationService,
        private ThalasemiaObservationService $thalasemiaObservationService,
        private DiagnosisConditionService $diagnosisConditionService,
        private EdukasiProcedureService $edukasiProcedureService,
        private TindakanProcedureService $tindakanProcedureService,
        private MedicationRequestService $medicationRequestService,
        private StatusPasienService $statusPasienService,
    ) {}

    public function sendEncounter(string $idSkrining)
    {
        try {
            $result = $this->encounterService->kirimEncounter($idSkrining);
            return redirect()->back()->with([
                'success'      => true,
                'data' => [
                    'encounterId' => $result['encounterId'],
                    // 'accessToken' => $result['accessToken'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function sendRiskFactor(string $idSkrining)
    {
        try {
            $observationId   = $this->observationService->sendSmokingStatus($idSkrining);
            $this->questionnaireResponseService->sendFaktorRisiko($idSkrining, $observationId);
            $conditionResult = $this->riwayatPTMService->sendRiwayat($idSkrining);

            return redirect()->back()->with([
                'message' => 'Data Faktor Risiko berhasil dikirim',
                'data'    => $conditionResult,
            ]);
        } catch (\Exception $e) {
            return response()->json([   // ← response(), bukan redirect()
                'message' => $e->getMessage(),
            ], 422);
        }
    }
    public function sendHipertensi(string $idSkrining)
    {
        try {
            $observationResult = $this->hipertensiObservationService->sendBloodPressure($idSkrining);
            $conditionResult   = $this->hipertensiConditionService->sendCondition($idSkrining);

            return redirect()->back()->with([
                'message'        => 'Data hipertensi berhasil dikirim',
                'data' => [
                    'observation_id' => $observationResult['observation_id'],
                    'condition_id'   => $conditionResult['condition_id'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
    public function sendObesitas(string $idSkrining)
    {
        try {
            $antropometri = $this->obesitasObservationService->sendAntropometri($idSkrining);
            $condition    = $this->obesitasConditionService->sendCondition($idSkrining);

            return redirect()->back()->with([
                'message'          => 'Data obesitas berhasil dikirim',
                'data' => [
                    'observation_id'   => $antropometri['observation_id'],
                    'condition_imt_id' => $condition['condition_imt_id'],
                    'condition_lp_id'  => $condition['condition_lp_id'],
                ],
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with(['message' => $e->getMessage()], 500);
        }
    }
    public function sendDiabetes(string $idSkrining)
    {
        try {
            $diabetes = $this->diabetesObservationService->sendDiabetes($idSkrining);

            return redirect()->back()->with([
                'message' => 'Data Diabetes berhasil dikirim',
                'data' => [
                    'observation_id' => $diabetes['observation_id'],
                    'condition_id'   => $diabetes['condition_id'],
                ],
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with(['message' => $e->getMessage()], 500);
        }
    }
    public function sendAsamUrat(string $idSkrining)
    {
        try {
            $asamUrat = $this->asamUratObservationService->sendAsamUrat($idSkrining);

            return redirect()->back()->with([
                'message'          => 'Data Asam Urat berhasil dikirim',
                'data' => [
                    'observation_id'   => $asamUrat['observation_id'],
                    'condition_id' => $asamUrat['condition_id'],
                ],
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with(['message' => $e->getMessage()], 500);
        }
    }
    public function sendProfilLipid(string $idSkrining)
    {
        try {
            $lipid = $this->profilLipidObservationService->sendProfilLipid($idSkrining);

            return redirect()->back()->with([
                'message' => 'Data Profil Lipid berhasil dikirim',
                'data' => [
                    'condition_id' => $lipid['condition_id'],
                ],
            ]);
        } catch (\Exception $e) {
            return redirect()->json(['message' => $e->getMessage()], 500);
        }
    }
    public function sendGangguanPendengaran(string $idSkrining)
    {
        try {
            $pendengaran = $this->gangguanPendengaranObservationService->sendGangguanPendengaran($idSkrining);

            return redirect()->back()->with([
                'message' => 'Data Gangguan Pendengaran berhasil dikirim',
                'data' => [
                    'observationId' => $pendengaran['observationId'],
                ],
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with(['message' => $e->getMessage()], 500);
        }
    }
    public function sendGangguanPenglihatan(string $idSkrining)
    {
        try {
            $penglihatan = $this->gangguanPenglihatanObservationService->sendGangguanPenglihatan($idSkrining);

            return redirect()->back()->with([
                'message' => 'Data Gangguan Penglihatan berhasil dikirim',
                'data' => [
                    'results' => $penglihatan,
                ]
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with(['message' => $e->getMessage()], 500);
        }
    }
    public function sendKankerParu(string $idSkrining)
    {
        try {
            $paru = $this->kankerParuQuestionnaireService->sendKankerParu($idSkrining);

            return redirect()->back()->with([
                'message' => 'Data Kanker Paru berhasil dikirim',
                'data' => [
                    'qr_id' => $paru['questionnaire_response_id'],
                    'condition_id' => $paru['condition_id']
                ]
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with(['message' => $e->getMessage()], 500);
        }
    }
    public function sendKolorektal(string $idSkrining)
    {
        try {
            $kolorektal = $this->kolorektalQuestionnaireService->sendKolorektal($idSkrining);

            return redirect()->back()->with([
                'message' => 'Data Kolorektal berhasil dikirim',
                'data' => [
                    'questionnaire_response_id'  => $kolorektal['questionnaire_response_id'],
                    'darah_samar_observation_id' => $kolorektal['darah_samar_observation_id'],
                ]
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with(['message' => $e->getMessage()], 500);
        }
    }
    public function sendThalasemia(string $idSkrining)
    {
        try {
            $result = $this->thalasemiaObservationService->sendThalasemia($idSkrining);

            return redirect()->back()->with([
                'message' => 'Data Thalasemia berhasil dikirim',
                'data'    => [
                    'thalasemia' => $result,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
    public function sendEKG(string $idSkrining)
    {
        try {
            $ekg = $this->ekgObservationService->sendEkg($idSkrining);

            return redirect()->back()->with([
                'message' => 'Data EKG berhasil dikirim',
                'data' => [
                    'observation_id' => $ekg['observation_id'],
                    'condition_id' => $ekg['condition_id'],
                ]
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with(['message' => $e->getMessage()], 500);
        }
    }
    public function sendKankerServiks(string $idSkrining)
    {
        try {
            $kankerServiks = $this->kankerServiksObservationService->sendIvaServiks($idSkrining);

            return redirect()->back()->with([
                'message' => 'Data Kanker Serviks berhasil dikirim',
                'data' => ['id' => $kankerServiks,]
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
    public function sendDiagnosis(string $pelayananId)
    {
        try {
            // Ambil semua diagnosis dari pelayanan ini
            $diagnosas = SimpusDataDiagnosa::where('pelayananId', $pelayananId)->get();

            if ($diagnosas->isEmpty()) {
                return response()->json(['message' => 'Tidak ada diagnosis'], 422);
            }

            $results = $this->diagnosisConditionService->sendDiagnosis($pelayananId, $diagnosas);

            return redirect()->back()->with([
                'message' => 'Diagnosis berhasil dikirim',
                'data'    => ['results' => $results],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
    public function sendTindakan(string $pelayananId)
    {
        try {

            $results = $this->tindakanProcedureService->sendTindakan($pelayananId);

            return redirect()->back()->with([
                'message' => 'Tindakan berhasil dikirim',
                'data'    => ['results' => $results],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
    public function sendEdukasi(string $idSkrining)
    {
        try {
            // Ambil semua diagnosis dari pelayanan ini
            $results = $this->edukasiProcedureService->sendEdukasi($idSkrining);

            return redirect()->back()->with([
                'message' => 'Edukasi berhasil dikirim',
                'data'    => ['results' => $results],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function sendResepObat(string $idPelayanan)
    {
        try {
            $results = $this->medicationRequestService->sendResepObat($idPelayanan);

            return redirect()->back()->with([
                'message' => 'Resep obat berhasil dikirim',
                'data'    => ['results' => $results],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function sendStatusPasien(string $idSkrining)
    {
        try {
            $results = $this->statusPasienService->sendStatusPasien($idSkrining);

            return redirect()->back()->with([
                'message' => 'Status pasien berhasil dikirim ke SATUSEHAT',
                'data'    => ['results' => $results],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
