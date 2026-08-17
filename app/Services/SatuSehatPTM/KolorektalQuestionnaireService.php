<?php

namespace App\Services\SatuSehatPTM;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\RuangLayanan\SkriningPTM\KunjunganPTM;
use App\Models\RuangLayanan\SkriningPTM\SimpusKolorektal;
use App\Models\RuangLayanan\SkriningPTM\SatuSehatLog;

class KolorektalQuestionnaireService
{
    /**
     * Questionnaire ID resmi SATUSEHAT untuk Kanker Kolorektal
     */
    private const QUESTIONNAIRE_ID = 'https://fhir.kemkes.go.id/Questionnaire/Q0020';

    /**
     * Kode LOINC untuk masing-masing Observation
     */
    private const LOINC_COLOK_DUBUR  = '32457-3'; // Digital rectal exam
    private const LOINC_DARAH_SAMAR  = '14563-1'; // Hemoglobin [Presence] in Stool (FOBT)

    /**
     */
    private array $riskConditionMap = [
        'Low'      => ['code' => '723505004', 'display' => 'Encounter for screening for malignant neoplasm of colon'],
        'Moderate' => ['code' => '25594002',  'display' => 'Family history of malignant neoplasm of digestive organs'],
        'High'     => ['code' => '723509005','display' => 'Personal history of other malignant neoplasm of large intestine'],
    ];

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

    private function findExistingQuestionnaireResponse(string $encounterId): ?string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->get(config('services.satusehat.fhir_url') . '/QuestionnaireResponse', [
                'encounter'     => $encounterId,
                'questionnaire' => self::QUESTIONNAIRE_ID,
            ]);

        if (!$response->successful()) return null;

        $entries = $response->json('entry') ?? [];
        return !empty($entries) ? ($entries[0]['resource']['id'] ?? null) : null;
    }

    private function findExistingObservation(string $encounterId, string $loincCode): ?string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->get(config('services.satusehat.fhir_url') . '/Observation', [
                'encounter' => $encounterId,
                'code'      => $loincCode,
            ]);

        if (!$response->successful()) return null;

        $entries = $response->json('entry') ?? [];
        return !empty($entries) ? ($entries[0]['resource']['id'] ?? null) : null;
    }

    private function findExistingCondition(string $encounterId, string $icdCode): ?string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->get(config('services.satusehat.fhir_url') . '/Condition', [
                'encounter' => $encounterId,
                'code'      => "http://hl7.org/fhir/sid/icd-10|{$icdCode}",
            ]);

        if (!$response->successful()) return null;

        $entries = $response->json('entry') ?? [];
        return !empty($entries) ? ($entries[0]['resource']['id'] ?? null) : null;
    }

    private function createQuestionnaireResponse(array $payload, ?string $idPelayanan): string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->post(config('services.satusehat.fhir_url') . '/QuestionnaireResponse', $payload);

        $terima = $response->json() ?? $response->body();
        $qrId = is_array($terima) ? ($terima['id'] ?? null) : null;

        $this->simpanLog(
            idPelayanan: $idPelayanan,
            resource: 'QuestionnaireResponse-Kolorektal',
            idResponse: $qrId,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
        );

        if (!$response->successful()) {
            Log::error('SatuSehat: gagal membuat QuestionnaireResponse Kolorektal', [
                'idPelayanan' => $idPelayanan,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \Exception('Gagal membuat QuestionnaireResponse Kolorektal: ' . $response->body());
        }

        return $qrId;
    }

    private function createObservation(array $payload, ?string $idPelayanan, string $label): string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->post(config('services.satusehat.fhir_url') . '/Observation', $payload);

        $terima = $response->json() ?? $response->body();
        $observationId = is_array($terima) ? ($terima['id'] ?? null) : null;

        $this->simpanLog(
            idPelayanan: $idPelayanan,
            resource: "Observation",
            idResponse: $observationId,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
        );

        if (!$response->successful()) {
            Log::error('SatuSehat: gagal membuat Observation Kolorektal', [
                'idPelayanan' => $idPelayanan,
                'label'       => $label,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \Exception('Gagal membuat Observation Kolorektal: ' . $response->body());
        }

        return $observationId;
    }

    private function createCondition(array $payload, ?string $idPelayanan): string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->post(config('services.satusehat.fhir_url') . '/Condition', $payload);

        $terima = $response->json() ?? $response->body();
        $conditionId = is_array($terima) ? ($terima['id'] ?? null) : null;

        $this->simpanLog(
            idPelayanan: $idPelayanan,
            resource: 'Condition-Kolorektal',
            idResponse: $conditionId,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
        );

        if (!$response->successful()) {
            Log::error('SatuSehat: gagal membuat Condition Kolorektal', [
                'idPelayanan' => $idPelayanan,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \Exception('Gagal membuat Condition Kolorektal: ' . $response->body());
        }

        return $conditionId;
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

    /**
     * Observation colok dubur — valueCodeableConcept Normal/Curiga
     * Nilai DB: 'normal' | 'curiga'
     */
    private function buildColokDuburObservation(string $patientId, string $encounterId, string $nilai, string $practitionerId): array
    {
        $valueMap = [
            'normal' => ['code' => '300870000', 'display' => 'No mass present', 'system' => 'http://snomed.info/sct'],
            'curiga' => ['code' => '248523006', 'display' => 'Rectal mass', 'system' => 'http://snomed.info/sct'],
        ];

        $valueCoding = $valueMap[strtolower($nilai)] ?? $valueMap['curiga'];

        return [
            'resourceType'         => 'Observation',
            'status'               => 'final',
            'category'             => [[
                'coding' => [[
                    'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
                    'code'    => 'procedure',
                    'display' => 'Procedure',
                ]],
            ]],
            'code'                 => [
                'coding' => [[
                    'system'  => 'http://snomed.info/sct',
                    'code'    => '410007005',
                    'display' => 'Digital rectal exam',
                ]],
            ],
            'subject'              => ['reference' => "Patient/{$patientId}"],
            'encounter'            => ['reference' => "Encounter/{$encounterId}"],
            'effectiveDateTime'    => now()->toIso8601String(),
            'performer'            => [[
                'reference' => 'Practitioner/' . $practitionerId,
            ]],
            'valueCodeableConcept' => [
                'coding' => [[
                    'system'  => $valueCoding['system'],
                    'code'    => $valueCoding['code'],
                    'display' => $valueCoding['display'],
                ]],
            ],
        ];
    }

    /**
     * Observation darah samar feses (FOBT) — valueCodeableConcept Positif/Negatif
     * Nilai DB: 'positif' | 'negatif'
     */
    private function buildDarahSamarObservation(string $patientId, string $encounterId, string $nilai, string $practitionerId): array
    {
        $valueMap = [
            'negatif' => ['code' => '167667006', 'display' => 'Occult blood not detected in feces', 'system' => 'http://snomed.info/sct'],
            'positif' => ['code' => '59614000',  'display' => 'Occult blood detected in feces', 'system' => 'http://snomed.info/sct'],
        ];

        $valueCoding = $valueMap[strtolower($nilai)] ?? $valueMap['negatif'];

        return [
            'resourceType'         => 'Observation',
            'status'               => 'final',
            'category'             => [[
                'coding' => [[
                    'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
                    'code'    => 'laboratory',
                    'display' => 'Laboratory',
                ]],
            ]],
            'code'                 => [
                'coding' => [[
                    'system'  => 'http://loinc.org',
                    'code'    => self::LOINC_DARAH_SAMAR,
                    'display' => 'Hemoglobin [Presence] in Stool',
                ]],
            ],
            'subject'              => ['reference' => "Patient/{$patientId}"],
            'encounter'            => ['reference' => "Encounter/{$encounterId}"],
            'effectiveDateTime'    => now()->toIso8601String(),
            'performer'            => [[
                'reference' => 'Practitioner/' . $practitionerId,
            ]],
            'valueCodeableConcept' => [
                'coding' => [[
                    'system'  => $valueCoding['system'],
                    'code'    => $valueCoding['code'],
                    'display' => $valueCoding['display'],
                ]],
            ],
        ];
    }

    public function sendKolorektal(string $idSkrining): array
    {
        $skrining   = KunjunganPTM::where('idSkrining', $idSkrining)->firstOrFail();
        $kolorektal = SimpusKolorektal::where('skriningID', $idSkrining)->firstOrFail();

        $patientId      = $skrining->patient_id;
        $encounterId    = $skrining->encounter_id;
        $idPelayanan    = $skrining->idPelayanan;
        $practitionerId = $skrining->id_petugas;

        // ─── QuestionnaireResponse ────────────────────────────────────
        $questionnaireResponseId = null;
        $existingQrId            = $this->findExistingQuestionnaireResponse($encounterId);

        if ($existingQrId) {
            Log::info('QuestionnaireResponse Kolorektal sudah ada, skip', ['qr_id' => $existingQrId]);
            $questionnaireResponseId = $existingQrId;
        } else {
            // Nilai kkr1/kkr2 dari form: 'true' | 'false' (string)
            $items = [
                [
                    'linkId' => 'kkr1',
                    'text'   => 'Riwayat keluarga generasi pertama kanker kolorektal?',
                    'answer' => [[
                        'valueBoolean' => filter_var($kolorektal->kuesioner1, FILTER_VALIDATE_BOOLEAN),
                    ]],
                ],
                [
                    'linkId' => 'kkr2',
                    'text'   => 'Apakah peserta merokok?',
                    'answer' => [[
                        'valueBoolean' => filter_var($kolorektal->kuesioner2, FILTER_VALIDATE_BOOLEAN),
                    ]],
                ],
                [
                    'linkId' => 'hasil',
                    'text'   => 'Hasil Skoring Kolorektal',
                    'answer' => [[
                        'valueString' => $kolorektal->hasil_kuesioner,
                    ]],
                ],
            ];

            $questionnaireResponseId = $this->createQuestionnaireResponse([
                'resourceType'  => 'QuestionnaireResponse',
                'questionnaire' => self::QUESTIONNAIRE_ID,
                'status'        => 'completed',
                'subject'       => ['reference' => "Patient/{$patientId}"],
                'encounter'     => ['reference' => "Encounter/{$encounterId}"],
                'authored'      => now()->toIso8601String(),
                'author'        => [
                    'reference' => 'Practitioner/' . $practitionerId,
                ],
                'source'        => [
                    'reference' => "Patient/{$patientId}",
                ],
                'item'          => $items,
            ], $idPelayanan);

            Log::info('QuestionnaireResponse Kolorektal berhasil', ['qr_id' => $questionnaireResponseId]);
        }

        // ─── Observation Colok Dubur ──────────────────────────────────
        $colokDuburObsId = null;

        if (!empty($kolorektal->colok_dbr)) {
            $existingColokId = $this->findExistingObservation($encounterId, self::LOINC_COLOK_DUBUR);

            if ($existingColokId) {
                Log::info('Observation Colok Dubur sudah ada, skip', ['observation_id' => $existingColokId]);
                $colokDuburObsId = $existingColokId;
            } else {
                $colokDuburObsId = $this->createObservation(
                    $this->buildColokDuburObservation($patientId, $encounterId, $kolorektal->colok_dbr, $practitionerId),
                    $idPelayanan,
                    'ColokDubur',
                );
                Log::info('Observation Colok Dubur berhasil', ['observation_id' => $colokDuburObsId]);
            }
        } else {
            Log::info('Observation Colok Dubur skip, tidak dilakukan');
        }

        // ─── Observation Darah Samar ──────────────────────────────────
        $darahSamarObsId = null;

        if (!empty($kolorektal->darah_samar)) {
            $existingDarahId = $this->findExistingObservation($encounterId, self::LOINC_DARAH_SAMAR);

            if ($existingDarahId) {
                Log::info('Observation Darah Samar sudah ada, skip', ['observation_id' => $existingDarahId]);
                $darahSamarObsId = $existingDarahId;
            } else {
                $darahSamarObsId = $this->createObservation(
                    $this->buildDarahSamarObservation($patientId, $encounterId, $kolorektal->darah_samar, $practitionerId),
                    $idPelayanan,
                    'DarahSamar',
                );
                Log::info('Observation Darah Samar berhasil', ['observation_id' => $darahSamarObsId]);
            }
        } else {
            Log::info('Observation Darah Samar skip, tidak dilakukan');
        }

        //  Condition berdasarkan hasil kuesioner 
        $conditionId    = null;
        $hasilKuesioner = $kolorektal->hasil_kuesioner ?? '';

        if (!empty($hasilKuesioner) && isset($this->riskConditionMap[$hasilKuesioner])) {
            $icd                 = $this->riskConditionMap[$hasilKuesioner];
            $existingConditionId = $this->findExistingCondition($encounterId, $icd['code']);

            if ($existingConditionId) {
                Log::info('Condition Kolorektal sudah ada, skip', ['condition_id' => $existingConditionId]);
                $conditionId = $existingConditionId;
            } else {
                $conditionId = $this->createCondition([
                    'resourceType'       => 'Condition',
                    'clinicalStatus'     => [
                        'coding' => [[
                            'system'  => 'http://terminology.hl7.org/CodeSystem/condition-clinical',
                            'code'    => 'active',
                            'display' => 'Active',
                        ]],
                    ],
                    'verificationStatus' => [
                        'coding' => [[
                            'system'  => 'http://terminology.hl7.org/CodeSystem/condition-ver-status',
                            'code'    => 'confirmed',
                            'display' => 'Confirmed',
                        ]],
                    ],
                    'category'           => [[
                        'coding' => [[
                            'system'  => 'http://terminology.hl7.org/CodeSystem/condition-category',
                            'code'    => 'encounter-diagnosis',
                            'display' => 'Encounter Diagnosis',
                        ]],
                    ]],
                    'code'               => [
                        'coding' => [[
                            'system'  => 'http://snomed.info/sct',
                            'code'    => $icd['code'],
                            'display' => $icd['display'],
                        ]],
                    ],
                    'subject'            => ['reference' => "Patient/{$patientId}"],
                    'encounter'          => ['reference' => "Encounter/{$encounterId}"],
                    'onsetDateTime'      => now()->toIso8601String(),
                    'recorder'           => [
                        'reference' => 'Practitioner/' . $practitionerId,
                    ],
                    'note'               => [[
                        'text' => "Hasil skrining kolorektal: {$hasilKuesioner}",
                    ]],
                ], $idPelayanan);
                Log::info('Condition Kolorektal berhasil', ['condition_id' => $conditionId]);
            }
        } else {
            Log::info('Condition Kolorektal skip, hasil kuesioner kosong atau tidak dikenali', [
                'hasil_kuesioner' => $hasilKuesioner,
            ]);
        }

        $kolorektal->update([
            'questionnaire_response_id' => $questionnaireResponseId,
            'colok_dubur_observation_id' => $colokDuburObsId,
            'darah_samar_observation_id' => $darahSamarObsId,
            'condition_id'               => $conditionId,
            'sent_at'                    => now(),
        ]);

        return [
            'questionnaire_response_id'  => $questionnaireResponseId,
            'colok_dubur_observation_id' => $colokDuburObsId,
            'darah_samar_observation_id' => $darahSamarObsId,
            'condition_id'               => $conditionId,
        ];
    }
}