<?php

namespace App\Services\SatuSehatPTM;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\RuangLayanan\SkriningPTM\KunjunganPTM;
use App\Models\RuangLayanan\SkriningPTM\SimpusKankerIva;
use App\Models\RuangLayanan\SkriningPTM\SatuSehatLog;

class KankerServiksObservationService
{
    // ────────────────────────────────────────────────────────────────
    // KODE SNOMED referensi tindak lanjut IVA positif
    // ────────────────────────────────────────────────────────────────
    private const TINDAK_LANJUT = [
        'krioterapi' => ['code' => '26782000',  'display' => 'Cryotherapy'],
        'thermal'    => ['code' => '407609002', 'display' => 'Endometrial thermal ablation'],
        'tca'        => ['code' => '78151001',  'display' => 'Trichloroacetic acid'],
    ];

    // ────────────────────────────────────────────────────────────────
    // Mapping nilai DB → SNOMED untuk Observation IVA (Lampiran 18)
    // ────────────────────────────────────────────────────────────────
    private array $ivaValueMap = [
        'negatif'       => ['code' => '260385009', 'display' => 'Negative'],
        'positif'       => ['code' => '10828004',  'display' => 'Positive'],
        'curiga_kanker' => ['code' => '415068001', 'display' => 'Suspected malignant neoplasm'],
    ];

    // ────────────────────────────────────────────────────────────────
    // Mapping nilai DB → SNOMED untuk Observation Inspekulo
    // ────────────────────────────────────────────────────────────────
    private array $inspekValueMap = [
        'Suspected cervical cancer'  => ['code' => '315266007', 'display' => 'Suspected cervical cancer'],
        'No evidence of cancer found' => ['code' => '395100000', 'display' => 'No evidence of cancer found'],
    ];

    // ────────────────────────────────────────────────────────────────
    // Mapping nilai DB → SNOMED untuk Observation SADANIS
    // ────────────────────────────────────────────────────────────────
    private array $sadanisValueMap = [
        'normal' => ['code' => '290084006', 'display' => 'Breast normal'],
        'curiga' => ['code' => '134405005', 'display' => 'Suspected breast cancer'],
    ];

    // ────────────────────────────────────────────────────────────────
    // Mapping nilai DB → SNOMED untuk Observation USG Payudara
    // ────────────────────────────────────────────────────────────────
    private array $usgValueMap = [
        'normal' => ['code' => '290084006', 'display' => 'Breast normal'],
        'curiga' => ['code' => '367644007', 'display' => 'Simple cyst'],
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

    // ────────────────────────────────────────────────────────────────
    // HELPERS: cek existing resource
    // ────────────────────────────────────────────────────────────────

    private function findExistingObservation(string $encounterId, string $code): ?string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->get(config('services.satusehat.fhir_url') . '/Observation', [
                'encounter' => $encounterId,
                'code'      => $code,
            ]);

        if (!$response->successful()) return null;
        $entries = $response->json('entry') ?? [];
        return !empty($entries) ? ($entries[0]['resource']['id'] ?? null) : null;
    }

    private function findExistingProcedure(string $encounterId, string $snomedCode): ?string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->get(config('services.satusehat.fhir_url') . '/Procedure', [
                'encounter' => $encounterId,
                'code'      => $snomedCode,
            ]);

        if (!$response->successful()) return null;
        $entries = $response->json('entry') ?? [];
        return !empty($entries) ? ($entries[0]['resource']['id'] ?? null) : null;
    }

    private function findExistingServiceRequest(string $encounterId, string $snomedCode): ?string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->get(config('services.satusehat.fhir_url') . '/ServiceRequest', [
                'encounter' => $encounterId,
                'code'      => $snomedCode,
            ]);

        if (!$response->successful()) return null;
        $entries = $response->json('entry') ?? [];
        return !empty($entries) ? ($entries[0]['resource']['id'] ?? null) : null;
    }

    private function extractResourceId(array|string|null $terima, $response): ?string
    {
        if (is_array($terima) && !empty($terima['id'])) {
            return $terima['id'];
        }

        // Fallback: parse from Location header, e.g.
        // https://.../ServiceRequest/1234-5678/_history/1
        $location = $response->header('Location') ?? ($terima['location'] ?? null);
        if ($location) {
            $parts = explode('/', $location);
            // find the segment right after the resource type
            return $parts[1] ?? null;
        }

        return null;
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
            resource: "Observation-KankerServiks-{$label}",
            idResponse: $observationId,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
        );

        if (!$response->successful()) {
            Log::error('SatuSehat: gagal membuat Observation IVA/Serviks', [
                'idPelayanan' => $idPelayanan,
                'label'       => $label,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \Exception('Gagal membuat Observation IVA/Serviks: ' . $response->body());
        }
        return $observationId;
    }

    private function createProcedure(array $payload, ?string $idPelayanan, string $label): string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->post(config('services.satusehat.fhir_url') . '/Procedure', $payload);

        $terima = $response->json() ?? $response->body();
        $procedureId = is_array($terima) ? ($terima['id'] ?? null) : null;

        $this->simpanLog(
            idPelayanan: $idPelayanan,
            resource: "Procedure-{$label}",
            idResponse: $procedureId,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
        );

        if (!$response->successful()) {
            Log::error('SatuSehat: gagal membuat Procedure IVA/Serviks', [
                'idPelayanan' => $idPelayanan,
                'label'       => $label,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \Exception('Gagal membuat Procedure IVA/Serviks: ' . $response->body());
        }
        return $procedureId;
    }

    private function createServiceRequest(array $payload, ?string $idPelayanan, string $label): string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->post(config('services.satusehat.fhir_url') . '/ServiceRequest', $payload);

        $terima = $response->json() ?? $response->body();
        $serviceRequestId = $this->extractResourceId(is_array($terima) ? $terima : null, $response);

        $this->simpanLog(
            idPelayanan: $idPelayanan,
            resource: "ServiceRequest",
            idResponse: $serviceRequestId,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
        );

        if (!$response->successful()) {
            Log::error('SatuSehat: gagal membuat ServiceRequest IVA/Serviks', [
                'idPelayanan' => $idPelayanan,
                'label'       => $label,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \Exception('Gagal membuat ServiceRequest IVA/Serviks: ' . $response->body());
        }
        return $serviceRequestId;
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

    private function baseProcedurePayload(
        string $patientId,
        string $encounterId,
        string $categorySnomedCode,
        string $categoryDisplay,
        string $codeSnomedCode,
        string $codeDisplay,
        string $status,
        string $practitionerId,
    ): array {
        $payload = [
            'resourceType' => 'Procedure',
            'status'       => $status,
            'category'     => [
                'coding' => [[
                    'system'  => 'http://snomed.info/sct',
                    'code'    => $categorySnomedCode,
                    'display' => $categoryDisplay,
                ]],
            ],
            'code'         => [
                'coding' => [[
                    'system'  => 'http://snomed.info/sct',
                    'code'    => $codeSnomedCode,
                    'display' => $codeDisplay,
                ]],
            ],
            'subject'      => ['reference' => "Patient/{$patientId}"],
            'encounter'    => ['reference' => "Encounter/{$encounterId}"],
            'performedDateTime' => now()->toIso8601String(),
            'performer'    => [[
                'actor' => ['reference' => 'Practitioner/' . $practitionerId],
            ]],
        ];

        return $payload;
    }

    private function baseObservationPayload(
        string $patientId,
        string $encounterId,
        string $categoryCode,
        string $categoryDisplay,
        string $codeSystem,
        string $codeCode,
        string $codeDisplay,
        array  $valueCoding,
        string $practitionerId,
    ): array {
        return [
            'resourceType'         => 'Observation',
            'status'               => 'final',
            'category'             => [[
                'coding' => [[
                    'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
                    'code'    => $categoryCode,
                    'display' => $categoryDisplay,
                ]],
            ]],
            'code'                 => [
                'coding' => [[
                    'system'  => $codeSystem,
                    'code'    => $codeCode,
                    'display' => $codeDisplay,
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
                    'system'  => 'http://snomed.info/sct',
                    'code'    => $valueCoding['code'],
                    'display' => $valueCoding['display'],
                ]],
            ],
        ];
    }



    public function sendIvaServiks(string $idSkrining): array
    {
        $skrining = KunjunganPTM::where('idSkrining', $idSkrining)->firstOrFail();
        $iva      = SimpusKankerIva::where('skriningID', $idSkrining)->firstOrFail();

        $patientId      = $skrining->patient_id;
        $encounterId    = $skrining->encounter_id;
        $idPelayanan    = $skrining->idPelayanan;
        $practitionerId = $skrining->id_petugas;
        $result         = [];

        // Procedure Inspekulo
        // Kode SNOMED Inspekulo: 451024007
        $inspekStatus  = ($iva->inspekulo === 'Tidak Dilakukan') ? 'not-done' : 'completed';
        $existingProcInspek = $this->findExistingProcedure($encounterId, '451024007');

        if ($existingProcInspek) {
            Log::info('Procedure Inspekulo sudah ada, skip', ['id' => $existingProcInspek]);
            $result['procedure_inspekulo_id'] = $existingProcInspek;
        } else {
            $procInspekId = $this->createProcedure(
                $this->baseProcedurePayload(
                    $patientId,
                    $encounterId,
                    '103693007',
                    'Diagnostic procedure',
                    '451024007',
                    'Inspection of vagina using vaginal speculum',
                    $inspekStatus,
                    $practitionerId,
                ),
                $idPelayanan,
                'Inspekulo',
            );
            Log::info('Procedure Inspekulo berhasil', ['id' => $procInspekId]);
            $result['procedure_inspekulo_id'] = $procInspekId;
        }

        // Observation Hasil Inspekulo (jika dilakukan)
        if ($iva->inspekulo !== 'Tidak Dilakukan' && isset($this->inspekValueMap[$iva->inspekulo])) {
            $existingObsInspek = $this->findExistingObservation($encounterId, '451024007');

            if ($existingObsInspek) {
                Log::info('Observation Inspekulo sudah ada, skip', ['id' => $existingObsInspek]);
                $result['observation_inspekulo_id'] = $existingObsInspek;
            } else {
                $obsInspekId = $this->createObservation(
                    $this->baseObservationPayload(
                        $patientId,
                        $encounterId,
                        'exam',
                        'Exam',
                        'http://snomed.info/sct',
                        '451024007',
                        'Inspection of vagina using vaginal speculum',
                        $this->inspekValueMap[$iva->inspekulo],
                        $practitionerId,
                    ),
                    $idPelayanan,
                    'Inspekulo',
                );
                Log::info('Observation Inspekulo berhasil', ['id' => $obsInspekId]);
                $result['observation_inspekulo_id'] = $obsInspekId;
            }
        }

        // Procedure IVA
        // Kode SNOMED IVA: 251422004
        $ivaStatus = ($iva->iva === 'Tidak Dilakukan') ? 'not-done' : 'completed';
        $existingProcIva = $this->findExistingProcedure($encounterId, '251422004');

        if ($existingProcIva) {
            Log::info('Procedure IVA sudah ada, skip', ['id' => $existingProcIva]);
            $result['procedure_iva_id'] = $existingProcIva;
        } else {
            $procIvaId = $this->createProcedure(
                $this->baseProcedurePayload(
                    $patientId,
                    $encounterId,
                    '103693007',
                    'Diagnostic procedure',
                    '251422004',
                    'Acetic acid test reaction',
                    $ivaStatus,
                    $practitionerId,
                ),
                $idPelayanan,
                'IVA',
            );
            Log::info('Procedure IVA berhasil', ['id' => $procIvaId]);
            $result['procedure_iva_id'] = $procIvaId;
        }

        // Observation Hasil IVA (jika dilakukan)
        if ($iva->iva !== 'Tidak Dilakukan' && isset($this->ivaValueMap[$iva->iva])) {
            $existingObsIva = $this->findExistingObservation($encounterId, 'X099241');

            if ($existingObsIva) {
                Log::info('Observation IVA sudah ada, skip', ['id' => $existingObsIva]);
                $result['observation_iva_id'] = $existingObsIva;
            } else {
                $obsIvaId = $this->createObservation(
                    $this->baseObservationPayload(
                        $patientId,
                        $encounterId,
                        'exam',
                        'Exam',
                        'http://terminology.kemkes.go.id/CodeSystem/examination',
                        'X099241',
                        'Tes IVA',
                        $this->ivaValueMap[$iva->iva],
                        $practitionerId,
                    ),
                    $idPelayanan,
                    'IVA',
                );
                Log::info('Observation IVA berhasil', ['id' => $obsIvaId]);
                $result['observation_iva_id'] = $obsIvaId;
            }
        }

        // Tindak lanjut IVA Positif
        if ($iva->iva === 'positif') {
            foreach (self::TINDAK_LANJUT as $key => $tindakan) {
                $dilakukan = (bool) $iva->{$key};

                $existingSr = $this->findExistingServiceRequest($encounterId, $tindakan['code']);

                if ($existingSr) {
                    Log::info("ServiceRequest {$key} sudah ada, skip", ['id' => $existingSr]);
                    $result["service_request_{$key}_id"] = $existingSr;
                    continue;
                }

                if ($dilakukan) {
                    // Kirim ServiceRequest tindakan
                    $srId = $this->createServiceRequest([
                        'resourceType' => 'ServiceRequest',
                        'status'       => 'active',
                        'intent'       => 'original-order',
                        'priority'     => 'routine',
                        'category'     => [[
                            'coding' => [[
                                'system'  => 'http://snomed.info/sct',
                                'code'    => '277132007',
                                'display' => 'Therapeutic procedure',
                            ]],
                        ]],
                        'code'         => [
                            'coding' => [[
                                'system'  => 'http://snomed.info/sct',
                                'code'    => $tindakan['code'],
                                'display' => $tindakan['display'],
                            ]],
                        ],
                        'subject'      => ['reference' => "Patient/{$patientId}"],
                        'encounter'    => ['reference' => "Encounter/{$encounterId}"],
                        'authoredOn'   => now()->toIso8601String(),
                        'requester'    => [
                            'reference' => 'Practitioner/' . $practitionerId,
                        ],
                        'performer'    => [[
                            'reference' => 'Practitioner/' . $practitionerId,
                        ]],
                    ], $idPelayanan, "TindakLanjut-{$key}");
                    Log::info("ServiceRequest {$key} berhasil", ['id' => $srId]);
                    $result["service_request_{$key}_id"] = $srId;
                } else {
                    // Pasien menolak → Procedure status not-done
                    $existingProc = $this->findExistingProcedure($encounterId, $tindakan['code']);

                    if ($existingProc) {
                        Log::info("Procedure tolak {$key} sudah ada, skip", ['id' => $existingProc]);
                        $result["procedure_tolak_{$key}_id"] = $existingProc;
                    } else {
                        $procTolakId = $this->createProcedure(
                            $this->baseProcedurePayload(
                                $patientId,
                                $encounterId,
                                '277132007',
                                'Therapeutic procedure',
                                $tindakan['code'],
                                $tindakan['display'],
                                'not-done',
                                $practitionerId,
                            ),
                            $idPelayanan,
                            "Tolak-{$key}",
                        );
                        Log::info("Procedure tolak {$key} berhasil", ['id' => $procTolakId]);
                        $result["procedure_tolak_{$key}_id"] = $procTolakId;
                    }
                }
            }
        }

        // Rujuk faskes (IVA positif atau Inspekulo curiga)
        $perluRujuk = $iva->rujuk_serviks
            || $iva->inspekulo === 'Suspected cervical cancer';

        if ($perluRujuk) {
            $existingRujuk = $this->findExistingServiceRequest($encounterId, '3457005');

            if ($existingRujuk) {
                Log::info('ServiceRequest Rujuk Serviks sudah ada, skip', ['id' => $existingRujuk]);
                $result['service_request_rujuk_id'] = $existingRujuk;
            } else {
                $rujukId = $this->createServiceRequest([
                    'resourceType' => 'ServiceRequest',
                    'status'       => 'active',
                    'intent'       => 'original-order',
                    'priority'     => 'routine',
                    'category'     => [[
                        'coding' => [[
                            'system'  => 'http://snomed.info/sct',
                            'code'    => '3457005',
                            'display' => 'Patient referral',
                        ]],
                    ]],
                    'code'         => [
                        'coding' => [[
                            'system'  => 'http://snomed.info/sct',
                            'code'    => '3457005',
                            'display' => 'Patient referral',
                        ]],
                    ],
                    'subject'      => ['reference' => "Patient/{$patientId}"],
                    'encounter'    => ['reference' => "Encounter/{$encounterId}"],
                    'authoredOn'   => now()->toIso8601String(),
                    'requester'    => [
                        'reference' => 'Practitioner/' . $practitionerId,
                    ],
                    'performer'    => [[
                        'reference' => 'Practitioner/' . $practitionerId,
                    ]],
                ], $idPelayanan, 'RujukServiks');
                Log::info('ServiceRequest Rujuk Serviks berhasil', ['id' => $rujukId]);
                $result['service_request_rujuk_id'] = $rujukId;
            }
        }

        // Observation HPV-DNA 
        // LOINC 44550-2
        if ($iva->hpv_dna !== 'Tidak Dilakukan' && !empty($iva->hpv_dna)) {
            $hpvValueMap = [
                'negatif' => ['code' => '260385009', 'display' => 'Negative'],
                'positif' => ['code' => '10828004',  'display' => 'Positive'],
            ];

            $existingHpv = $this->findExistingObservation($encounterId, '44550-2');

            if ($existingHpv) {
                Log::info('Observation HPV-DNA sudah ada, skip', ['id' => $existingHpv]);
                $result['observation_hpv_id'] = $existingHpv;
            } else {
                $hpvVal = $hpvValueMap[$iva->hpv_dna] ?? $hpvValueMap['negatif'];
                $hpvId  = $this->createObservation(
                    $this->baseObservationPayload(
                        $patientId,
                        $encounterId,
                        'laboratory',
                        'Laboratory',
                        'http://loinc.org',
                        '44550-2',
                        'Human papillomavirus DNA [Presence] in Cervix by Probe',
                        $hpvVal,
                        $practitionerId,
                    ),
                    $idPelayanan,
                    'HPV-DNA',
                );
                Log::info('Observation HPV-DNA berhasil', ['id' => $hpvId]);
                $result['observation_hpv_id'] = $hpvId;
            }
        }

        // Observation SADANIS
        // SNOMED 13607009
        if ($iva->sadanis !== 'Tidak Dilakukan' && !empty($iva->sadanis)) {
            $existingSadanis = $this->findExistingObservation($encounterId, '13607009');

            if ($existingSadanis) {
                Log::info('Observation SADANIS sudah ada, skip', ['id' => $existingSadanis]);
                $result['observation_sadanis_id'] = $existingSadanis;
            } else {
                $sadanisVal = $this->sadanisValueMap[$iva->sadanis]
                    ?? $this->sadanisValueMap['normal'];

                $sadanisId = $this->createObservation(
                    $this->baseObservationPayload(
                        $patientId,
                        $encounterId,
                        'exam',
                        'Exam',
                        'http://snomed.info/sct',
                        '13607009',
                        'Manual examination of breast',
                        $sadanisVal,
                        $practitionerId,
                    ),
                    $idPelayanan,
                    'SADANIS',
                );
                Log::info('Observation SADANIS berhasil', ['id' => $sadanisId]);
                $result['observation_sadanis_id'] = $sadanisId;
            }
        }

        // Observation USG Payudara 
        // LOINC 24601-7
        if ($iva->usg !== 'Tidak Dilakukan' && !empty($iva->usg)) {
            $existingUsg = $this->findExistingObservation($encounterId, '24601-7');

            if ($existingUsg) {
                Log::info('Observation USG Payudara sudah ada, skip', ['id' => $existingUsg]);
                $result['observation_usg_id'] = $existingUsg;
            } else {
                $usgVal = $this->usgValueMap[$iva->usg] ?? $this->usgValueMap['normal'];

                $usgId = $this->createObservation(
                    $this->baseObservationPayload(
                        $patientId,
                        $encounterId,
                        'imaging',
                        'Imaging',
                        'http://loinc.org',
                        '24601-7',
                        'US Breast',
                        $usgVal,
                        $practitionerId,
                    ),
                    $idPelayanan,
                    'USG-Payudara',
                );
                Log::info('Observation USG Payudara berhasil', ['id' => $usgId]);
                $result['observation_usg_id'] = $usgId;
            }
        }

        $iva->update(array_merge($result, ['sent_at' => now()]));

        return $result;
    }
}
