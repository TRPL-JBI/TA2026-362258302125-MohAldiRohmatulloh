<?php

namespace App\Services\SatuSehatPTM;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Models\RuangLayanan\SkriningPTM\SimpusSkriningPTM;
use App\Models\RuangLayanan\SkriningPTM\FaktorRisiko;
use App\Models\RuangLayanan\SkriningPTM\KunjunganPTM;
use App\Models\RuangLayanan\SkriningPTM\SatuSehatLog;
use Illuminate\Support\Facades\Log;

class ObservationService
{
    public function __construct(
        private EncounterService $encounterService,
    ) {}

    private ?string $cachedToken = null;

    private function getToken(): string
    {
        if (!$this->cachedToken) {
            $this->cachedToken = $this->encounterService->getAccessToken();
        }
        return $this->cachedToken;
    }


    /**
     * Kirim status merokok sebagai Observation (LOINC 72166-2)
     * Return observation ID untuk dipakai di QuestionnaireResponse (linkId 1.4)
     */
    private function createObservation(array $payload, ?string $idPelayanan): string
    {
        $token = $this->encounterService->getAccessToken();

        $response = Http::withToken($token)
            ->acceptJson()
            ->post(
                config('services.satusehat.fhir_url') . '/Observation',
                $payload
            );

        $terima = $response->json() ?? $response->body();
        $observationId = is_array($terima) ? ($terima['id'] ?? null) : null;

        $this->simpanLog(
            idPelayanan: $idPelayanan,
            resource: 'Observation',
            idResponse: $observationId,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
        );

        if (!$response->successful()) {
            Log::error('SatuSehat: gagal membuat Observation status merokok', [
                'idPelayanan' => $idPelayanan,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \Exception(
                'Gagal membuat Observation: ' . $response->body()
            );
        }

        return $observationId;
    }

    protected function simpanLog(
        ?string $idPelayanan,
        string $resource,
        ?string $idResponse,
        string $method,
        mixed $kirim,
        mixed $terima,
    ): void {
        $data = [
            'idPelayanan' => $idPelayanan,
            'tanggal'     => now(),
            'puskId'      => Auth::user()->unit,
            'resource'    => $resource,
            'idResponse'  => $idResponse,
            'method'      => $method,
            'kirim'       => json_encode($kirim),
            'terima'      => json_encode($terima),
            'userId'      => Auth::id(),
        ];

        try {
            $log = SatuSehatLog::create($data);

            Log::info('SatuSehat: log tersimpan ke satu_sehat_log', [
                'id'          => $log->id ?? null,
                'idPelayanan' => $idPelayanan,
                'resource'    => $resource,
            ]);
        } catch (\Throwable $e) {
            Log::error('SatuSehat: GAGAL menyimpan ke satu_sehat_log', [
                'message'        => $e->getMessage(),
                'idPelayanan'    => $idPelayanan,
                'resource'       => $resource,
                'userId'         => Auth::id(),
                'panjang_kirim'  => strlen((string) $data['kirim']),
                'panjang_terima' => strlen((string) $data['terima']),
                'trace'          => $e->getTraceAsString(),
            ]);
        }
    }

    public function sendSmokingStatus(string $idSkrining): string
    {
        $skrining     = KunjunganPTM::where('idSkrining', $idSkrining)->firstOrFail();
        $faktorRisiko = FaktorRisiko::where('skriningID', $idSkrining)->firstOrFail();

        $patientId      = $skrining->patient_id;
        $encounterId    = $skrining->encounter_id;
        $idPelayanan    = $skrining->idPelayanan;
        $practitionerId = $skrining->id_petugas;

        $existingId = $this->findExistingObservation($encounterId);
        if ($existingId) {
            Log::info('Observation sudah ada, skip kirim', [
                'observation_id' => $existingId,
            ]);
            return $existingId;
        }

        $smokingStatusMap = [
            'perokok_aktif'  => ['code' => '77176002', 'display' => 'Smoker'],
            'mantan_perokok' => ['code' => '8517006',  'display' => 'Ex-smoker'],
            'tidak_pernah'   => ['code' => '8392000',  'display' => 'Non-smoker'],
        ];

        $statusValue = strtolower($faktorRisiko->status_merokok ?? '');

        if (!isset($smokingStatusMap[$statusValue])) {
            Log::warning('status_merokok tidak dikenali, fallback ke Non-smoker', [
                'status_merokok' => $statusValue,
                'idSkrining'     => $idSkrining,
            ]);
        }

        $snomed = $smokingStatusMap[$statusValue] ?? ['code' => '8392000', 'display' => 'Non-smoker'];

        $payload = [
            'resourceType' => 'Observation',
            'status'       => 'final',

            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
                            'code'    => 'social-history',
                            'display' => 'Social History',
                        ],
                    ],
                ],
            ],

            'code' => [
                'coding' => [
                    [
                        'system'  => 'http://loinc.org',
                        'code'    => '72166-2',
                        'display' => 'Tobacco smoking status',
                    ],
                ],
                'text' => 'Tobacco smoking status',
            ],

            'subject'   => ['reference' => "Patient/{$patientId}"],
            'encounter' => ['reference' => "Encounter/{$encounterId}"],
            'performer' => [['reference' => "Practitioner/" . $practitionerId]],

            'valueCodeableConcept' => [
                'coding' => [
                    [
                        'system'  => 'http://snomed.info/sct',
                        'code'    => $snomed['code'],
                        'display' => $snomed['display'],
                    ],
                ],
            ],
        ];

        try {
            $observationId = $this->createObservation($payload, $idPelayanan);
            Log::info('Observation status merokok berhasil', [
                'observation_id' => $observationId,
            ]);
            return $observationId;
        } catch (\Exception $e) {
            Log::error('Observation status merokok gagal', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function findExistingObservation(string $encounterId): ?string
    {

        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->get(config('services.satusehat.fhir_url') . '/Observation', [
                'encounter' => $encounterId,
                'code'      => '72166-2', // LOINC status merokok
            ]);

        if (!$response->successful()) {
            return null;
        }

        $entries = $response->json('entry') ?? [];

        if (!empty($entries)) {
            return $entries[0]['resource']['id'] ?? null;
        }

        return null;
    }
}