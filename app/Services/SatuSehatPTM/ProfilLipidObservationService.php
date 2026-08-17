<?php

namespace App\Services\SatuSehatPTM;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\RuangLayanan\SkriningPTM\KunjunganPTM;
use App\Models\RuangLayanan\SkriningPTM\SimpusProfilLipid;
use App\Models\RuangLayanan\SkriningPTM\SatuSehatLog;

class ProfilLipidObservationService
{
    //  LOINC map per kolom
    private array $loincMap = [
        'kolesterol_total' => ['code' => '2093-3',  'display' => 'Cholesterol [Mass/volume] in Serum or Plasma',     'unit' => 'mg/dL', 'ucum' => 'mg/dL'],
        'hdl'              => ['code' => '2085-9',  'display' => 'Cholesterol in HDL [Mass/volume] in Serum or Plasma', 'unit' => 'mg/dL', 'ucum' => 'mg/dL'],
        'ldl'              => ['code' => '2089-1',  'display' => 'Cholesterol in LDL [Mass/volume] in Serum or Plasma', 'unit' => 'mg/dL', 'ucum' => 'mg/dL'],
        'trigliserida'     => ['code' => '2571-8',  'display' => 'Triglyceride [Mass/volume] in Serum or Plasma',    'unit' => 'mg/dL', 'ucum' => 'mg/dL'],
    ];

    // ICD-10 map per interpretasi
    private array $kolesterolMap = [
        'normal'              => null,
        'borderline_tinggi'   => ['code' => 'E78.0', 'display' => 'Pure hypercholesterolemia, unspecified'],
        'tinggi'              => ['code' => 'E78.0', 'display' => 'Pure hypercholesterolemia, unspecified'],
        'data_tidak_tersedia' => null,
    ];

    private array $hdlMap = [
        'rendah'              => ['code' => 'E78.6', 'display' => 'Lipoprotein deficiency'],
        'sedang'              => null,
        'protektif'           => null,
        'data_tidak_tersedia' => null,
    ];

    private array $trigliseridaMap = [
        'normal'            => null, // skip
        'borderline_tinggi' => ['code' => 'E78.1', 'display' => 'Pure hyperglyceridemia'],
        'tinggi'            => ['code' => 'E78.1', 'display' => 'Pure hyperglyceridemia'],
        'sangat_tinggi'     => ['code' => 'E78.1', 'display' => 'Pure hyperglyceridemia'],
    ];

    private array $ldlMap = [
        'optimal'            => null, // skip
        'mendekati_optimal'  => null, // skip — masih dianggap dalam batas wajar
        'borderline_tinggi'  => ['code' => 'E78.0', 'display' => 'Pure hypercholesterolemia, unspecified'],
        'tinggi'             => ['code' => 'E78.0', 'display' => 'Pure hypercholesterolemia, unspecified'],
        'sangat_tinggi'      => ['code' => 'E78.0', 'display' => 'Pure hypercholesterolemia, unspecified'],
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

    private function sendBundle(array $entries, ?string $idPelayanan): array
    {
        $payload = [
            'resourceType' => 'Bundle',
            'type'         => 'transaction',
            'entry'        => $entries,
        ];

        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->post(config('services.satusehat.fhir_url'), $payload);

        $terima = $response->json() ?? $response->body();

        // Ambil observation id dari setiap entry hasil response bundle
        $bundleResourceIds = [];
        foreach ((is_array($terima) ? ($terima['entry'] ?? []) : []) as $respEntry) {
            $location   = $respEntry['response']['location'] ?? null;
            $resourceId = $respEntry['resource']['id'] ?? null;

            if (!$resourceId && $location) {
                $parts      = explode('/', $location);
                $resourceId = $parts[1] ?? null;
            }

            if ($resourceId) {
                $bundleResourceIds[] = $resourceId;
            }
        }

        $this->simpanLog(
            idPelayanan: $idPelayanan,
            resource: 'Observation',
            idResponse: !empty($bundleResourceIds) ? implode(',', $bundleResourceIds) : null,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
        );

        if (!$response->successful()) {
            Log::error('SatuSehat: gagal mengirim Bundle Profil Lipid', [
                'idPelayanan' => $idPelayanan,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \Exception('Gagal mengirim Bundle Profil Lipid: ' . $response->body());
        }

        return $bundleResourceIds;
    }

    private function createCondition(array $payload, ?string $idPelayanan, string $label): string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->post(config('services.satusehat.fhir_url') . '/Condition', $payload);

        $terima = $response->json() ?? $response->body();
        $conditionId = is_array($terima) ? ($terima['id'] ?? null) : null;

        $this->simpanLog(
            idPelayanan: $idPelayanan,
            resource: "Condition",
            idResponse: $conditionId,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
        );

        if (!$response->successful()) {
            Log::error('SatuSehat: gagal membuat Condition Profil Lipid', [
                'idPelayanan' => $idPelayanan,
                'label'       => $label,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \Exception('Gagal membuat Condition Profil Lipid: ' . $response->body());
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

    private function normalizeInterpretasi(string $value): string
    {
        $normalized = strtolower(trim($value));
        return preg_replace('/\s+/', '_', $normalized);
    }

    private function resolveIcd(array $map, string $interpretasi): ?array
    {
        $normalized = $this->normalizeInterpretasi($interpretasi);

        if (!array_key_exists($normalized, $map)) {
            Log::warning('resolveIcd ProfilLipid: interpretasi tidak dikenali', [
                'interpretasi' => $interpretasi,
                'normalized'   => $normalized,
            ]);
            return null;
        }

        return $map[$normalized]; // null = skip condition
    }

    private function buildObservationEntry(
        string $loincCode,
        string $loincDisplay,
        float $value,
        string $unit,
        string $ucumCode,
        string $patientId,
        string $encounterId,
        string $effectiveAt,
        string $practitionerId,
    ): array {
        return [
            'fullUrl'  => 'urn:uuid:' . \Str::uuid(),
            'resource' => [
                'resourceType'      => 'Observation',
                'status'            => 'final',
                'category'          => [[
                    'coding' => [[
                        'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
                        'code'    => 'laboratory',
                        'display' => 'Laboratory',
                    ]],
                ]],
                'code'              => [
                    'coding' => [[
                        'system'  => 'http://loinc.org',
                        'code'    => $loincCode,
                        'display' => $loincDisplay,
                    ]],
                ],
                'subject'           => ['reference' => "Patient/{$patientId}"],
                'encounter'         => ['reference' => "Encounter/{$encounterId}"],
                'effectiveDateTime' => $effectiveAt,
                'performer'         => [[
                    'reference' => 'Practitioner/' . $practitionerId,
                ]],
                'valueQuantity'     => [
                    'value'  => $value,
                    'unit'   => $unit,
                    'system' => 'http://unitsofmeasure.org',
                    'code'   => $ucumCode,
                ],
            ],
            'request' => [
                'method' => 'POST',
                'url'    => 'Observation',
            ],
        ];
    }

    private function buildConditionPayload(array $icd, string $patientId, string $encounterId, string $practitionerId): array
    {
        return [
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
                    'system'  => 'http://hl7.org/fhir/sid/icd-10',
                    'code'    => $icd['code'],
                    'display' => $icd['display'],
                ]],
            ],
            'subject'        => ['reference' => "Patient/{$patientId}"],
            'encounter'      => ['reference' => "Encounter/{$encounterId}"],
            'onsetDateTime'  => now()->toIso8601String(),
            'recorder'       => [
                'reference' => 'Practitioner/' . $practitionerId,
            ],
        ];
    }

    private function handleCondition(
        string $encounterId,
        string $patientId,
        ?array $icd,
        string $label,
        ?string $idPelayanan,
        string $practitionerId,
    ): ?string {

        if (is_null($icd)) {
            Log::info("Condition {$label} skip, interpretasi tidak perlu dikirim");
            return null;
        }

        $existingId = $this->findExistingCondition($encounterId, $icd['code']);
        if ($existingId) {
            Log::info("Condition {$label} sudah ada, skip", ['condition_id' => $existingId]);
            return $existingId;
        }

        $id = $this->createCondition(
            $this->buildConditionPayload($icd, $patientId, $encounterId, $practitionerId),
            $idPelayanan,
            $label,
        );

        Log::info("Condition {$label} berhasil", ['condition_id' => $id]);
        return $id;
    }

    public function sendProfilLipid(string $idSkrining): array
    {
        $skrining    = KunjunganPTM::where('idSkrining', $idSkrining)->firstOrFail();
        $profilLipid = SimpusProfilLipid::where('skriningID', $idSkrining)->firstOrFail();

        $patientId   = $skrining->patient_id;
        $encounterId = $skrining->encounter_id;
        $idPelayanan = $skrining->idPelayanan;
        $practitionerId = $skrining->id_petugas;
        $effectiveAt = now()->toIso8601String();

        // ─── Observation Bundle ──────────────────────────────────────
        $fields = [
            'kolesterol_total' => $profilLipid->kolesterol_total,
            'hdl'              => $profilLipid->hdl,
            'ldl'              => $profilLipid->ldl,
            'trigliserida'     => $profilLipid->trigliserida,
        ];

        $entries = [];
        $entryKeys = [];
        $observationByKey = []; // <-- key => observation_id

        foreach ($fields as $key => $value) {
            if (is_null($value)) continue;

            $loinc      = $this->loincMap[$key];
            $existingId = $this->findExistingObservation($encounterId, $loinc['code']);

            if ($existingId) {
                Log::info("Observation {$key} sudah ada, skip", ['observation_id' => $existingId]);
                $observationByKey[$key] = $existingId; // <-- simpan id yang sudah ada
                continue;
            }

            $entries[] = $this->buildObservationEntry(
                loincCode: $loinc['code'],
                loincDisplay: $loinc['display'],
                value: (float) $value,
                unit: $loinc['unit'],
                ucumCode: $loinc['ucum'],
                patientId: $patientId,
                encounterId: $encounterId,
                effectiveAt: $effectiveAt,
                practitionerId: $practitionerId
            );
            $entryKeys[] = $key; // <-- catat urutan key yang benar-benar dikirim
        }

        if (!empty($entries)) {
            $bundleResourceIds = $this->sendBundle($entries, $idPelayanan);

            // pasangkan observation id baru dengan key-nya berdasarkan urutan $entryKeys
            foreach ($entryKeys as $i => $key) {
                if (isset($bundleResourceIds[$i])) {
                    $observationByKey[$key] = $bundleResourceIds[$i];
                }
            }

            Log::info('Bundle Observation Profil Lipid berhasil', ['total' => count($entries)]);
        }

        $conditionKolesterolId = $this->handleCondition(
            $encounterId,
            $patientId,
            $this->resolveIcd($this->kolesterolMap, $profilLipid->interpretasi_kolesterol_total ?? 'normal'),
            'Kolesterol Total',
            $idPelayanan,
            $practitionerId,
        );

        $conditionHdlId = $this->handleCondition(
            $encounterId,
            $patientId,
            $this->resolveIcd($this->hdlMap, $profilLipid->interpretasi_hdl ?? 'protektif'),
            'HDL',
            $idPelayanan,
            $practitionerId,
        );

        $conditionLdlId = $this->handleCondition(
            $encounterId,
            $patientId,
            $this->resolveIcd($this->ldlMap, $profilLipid->interpretasi_ldl ?? 'optimal'),
            'LDL',
            $idPelayanan,
            $practitionerId,
        );

        $conditionTrigliseridaId = $this->handleCondition(
            $encounterId,
            $patientId,
            $this->resolveIcd($this->trigliseridaMap, $profilLipid->interpretasi_trigliserida ?? 'normal'),
            'Trigliserida',
            $idPelayanan,
            $practitionerId
        );

        $profilLipid->update([
            'sent_at' => now(),
        ]);

        // Prioritas: kolesterol total dulu, baru LDL, HDL, trigliserida
        $priorityOrder = ['kolesterol_total', 'ldl', 'hdl', 'trigliserida'];
        $primaryObservationId = null;

        foreach ($priorityOrder as $key) {
            if (isset($observationByKey[$key])) {
                $primaryObservationId = $observationByKey[$key];
                break;
            }
        }

        return [
            'observation_id' => $primaryObservationId,
            'condition_id'   => $conditionKolesterolId,
        ];
    }
}
