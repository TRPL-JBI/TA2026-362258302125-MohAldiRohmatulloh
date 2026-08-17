<?php

namespace App\Services\SatuSehatPTM;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\RuangLayanan\SkriningPTM\KunjunganPTM;
use App\Models\RuangLayanan\SkriningPTM\SimpusDiabetes;
use App\Models\RuangLayanan\SkriningPTM\SatuSehatLog;

class DiabetesObservationService
{
    private array $categoryMap = [
        'normal'     => ['code' => 'Z03.8', 'display' => 'No diagnosis'],
        'prediabetes' => ['code' => 'R73.0', 'display' => 'Prediabetes'],
        'diabetes'   => ['code' => 'E11.9', 'display' => 'Type 2 diabetes mellitus without complications'],
    ];

    private array $loincMap = [
        'gula_darah_puasa'     => ['code' => '76629-5', 'display' => 'Fasting glucose [Moles/volume] in Blood', 'unit' => 'mg/dL', 'ucum' => 'mg/dL'],
        'gula_darah_2_jam_pp'  => ['code' => '14743-9', 'display' => 'Glucose [Moles/volume] in Capillary blood --2 hours post meal', 'unit' => 'mg/dL', 'ucum' => 'mg/dL'],
        'gula_darah_sewaktu'   => ['code' => '2339-0',  'display' => 'Glucose [Mass/volume] in Blood', 'unit' => 'mg/dL', 'ucum' => 'mg/dL'],
        'hba1c'                => ['code' => '4548-4',  'display' => 'Hemoglobin A1c/Hemoglobin.total in Blood', 'unit' => '%', 'ucum' => '%'],
    ];

    private array $labelMap = [
        'indikasi diabetes'            => 'diabetes',
        'prediabetes'                  => 'prediabetes',
        'toleransi glukosa terganggu'  => 'prediabetes',
        'tidak diabetes'               => 'normal',
        'normal'                       => 'normal',
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

    /**
     * Log Satu Sehat
     */
    private function logSatuSehat(
        string $idPelayanan,
        ?string $puskId,
        string $resource,
        ?string $idResponse,
        string $method,
        array|string|null $kirim,
        array|string|null $terima,
        ?string $userId,
    ): void {
        $data = [
            'idPelayanan' => $idPelayanan,
            'tanggal'     => now(),
            'puskId'      => $puskId,
            'resource'    => $resource,
            'idResponse'  => $idResponse,
            'method'      => $method,
            'kirim'       => is_array($kirim) ? json_encode($kirim) : $kirim,
            'terima'      => is_array($terima) ? json_encode($terima) : $terima,
            'userId'      => $userId,
        ];

        try {
            $log = SatuSehatLog::create($data);

            Log::info('SatuSehat: log tersimpan ke satu_sehat_log (Diabetes)', [
                'id'          => $log->id ?? null,
                'idPelayanan' => $idPelayanan,
                'resource'    => $resource,
            ]);
        } catch (\Throwable $e) {
            Log::error('SatuSehat: GAGAL menyimpan ke satu_sehat_log (Diabetes)', [
                'message'        => $e->getMessage(),
                'idPelayanan'    => $idPelayanan,
                'resource'       => $resource,
                'userId'         => $userId,
                'panjang_kirim'  => strlen((string) $data['kirim']),
                'panjang_terima' => strlen((string) $data['terima']),
                'trace'          => $e->getTraceAsString(),
            ]);
        }
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


    private function sendBundle(array $entries, string $idSkrining, ?string $puskId): array
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

        // Ambil observationId dari setiap entry hasil response bundle
        $bundleResourceIds = [];
        foreach ((is_array($terima) ? ($terima['entry'] ?? []) : []) as $respEntry) {
            // SatuSehat biasanya mengembalikan response.location, contoh: "Observation/abcd-1234/_history/1"
            $location = $respEntry['response']['location'] ?? null;
            $resourceId = $respEntry['resource']['id'] ?? null;

            if (!$resourceId && $location) {
                // fallback: ambil id dari location, format "Observation/{id}/_history/{versionId}"
                $parts = explode('/', $location);
                $resourceId = $parts[1] ?? null;
            }

            if ($resourceId) {
                $bundleResourceIds[] = $resourceId;
            }
        }
        $observationId = !empty($bundleResourceIds) ? implode(',', $bundleResourceIds) : null;

        $this->logSatuSehat(
            idPelayanan: $idSkrining,
            puskId: $puskId,
            resource: 'Observation',
            idResponse: $observationId,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
            userId: $puskId,
        );

        if (!$response->successful()) {
            throw new \Exception('Gagal mengirim Bundle Diabetes: ' . $response->body());
        }

        return $terima;
    }

    private function createCondition(array $payload, string $idSkrining, ?string $puskId): array
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->post(config('services.satusehat.fhir_url') . '/Condition', $payload);

        $terima = $response->json() ?? $response->body();
        $conditionId = is_array($terima) ? ($terima['id'] ?? null) : null;

        $this->logSatuSehat(
            idPelayanan: $idSkrining,
            puskId: $puskId,
            resource: 'Condition',
            idResponse: $conditionId,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
            userId: $puskId,
        );

        if (!$response->successful()) {
            throw new \Exception('Gagal membuat Condition Diabetes: ' . $response->body());
        }

        return $terima;
    }

    private function resolveCategory(string $kategori): array
    {
        $normalized = strtolower(trim($kategori));

        if (!isset($this->categoryMap[$normalized])) {
            Log::warning('resolveCategory Diabetes: tidak dikenali, fallback ke normal', [
                'kategori' => $kategori,
            ]);
            return $this->categoryMap['normal'];
        }

        return $this->categoryMap[$normalized];
    }

    private function normalizeKategori(?string $label): string
    {
        if (is_null($label) || trim($label) === '') {
            return 'normal';
        }

        $key = strtolower(trim($label));

        if (!isset($this->labelMap[$key])) {
            Log::warning('normalizeKategori Diabetes: label tidak dikenali, fallback ke normal', [
                'label' => $label,
            ]);
            return 'normal';
        }

        return $this->labelMap[$key];
    }

    // Prioritas: diabetes > prediabetes > normal
    private function resolveKategoriUtama(array $kategoriList): string
    {
        $priority = ['diabetes' => 2, 'prediabetes' => 1, 'normal' => 0];
        $highest  = 'normal';

        foreach ($kategoriList as $kategoriMentah) {
            if (is_null($kategoriMentah)) continue;

            $kategori = $this->normalizeKategori($kategoriMentah);

            if ($priority[$kategori] > ($priority[$highest] ?? 0)) {
                $highest = $kategori;
            }
        }

        return $highest;
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
            'subject'            => ['reference' => "Patient/{$patientId}"],
            'encounter'          => ['reference' => "Encounter/{$encounterId}"],
            'onsetDateTime'      => now()->toIso8601String(),
            'recorder'           => [
                'reference' => 'Practitioner/' . $practitionerId,
            ],
        ];
    }

    public function sendDiabetes(string $idSkrining): array
    {
        $skrining  = KunjunganPTM::where('idSkrining', $idSkrining)->firstOrFail();
        $diabetes  = SimpusDiabetes::where('skriningID', $idSkrining)->firstOrFail();

        $patientId   = $skrining->patient_id;
        $encounterId = $skrining->encounter_id;
        $effectiveAt = now()->toIso8601String();
        $practitionerId = $skrining->id_petugas;

        $puskId = Auth::user()->unit;

        $fields = [
            'gula_darah_puasa'    => $diabetes->gula_darah_puasa,
            'gula_darah_2_jam_pp' => $diabetes->gula_darah_2_jam_pp,
            'gula_darah_sewaktu'  => $diabetes->gula_darah_sewaktu,
            'hba1c'               => $diabetes->hba1c,
        ];

        $entries = [];
        $entryKeys = [];
        $observationByKey = [];

        foreach ($fields as $key => $value) {
            if (is_null($value)) continue;

            $loinc = $this->loincMap[$key];

            $existingId = $this->findExistingObservation($encounterId, $loinc['code']);
            if ($existingId) {
                Log::info("Observation {$key} sudah ada, skip", ['observation_id' => $existingId]);
                $observationByKey[$key] = $existingId;
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
                practitionerId: $practitionerId,
            );
            $entryKeys[] = $key;
        }

        if (!empty($entries)) {
            $bundleResult = $this->sendBundle($entries, $idSkrining, $puskId);

            // pasangkan observation id baru dengan key-nya (urutan sesuai $entryKeys)
            foreach ($entryKeys as $i => $key) {
                if (isset($bundleResult['observation_ids'][$i])) {
                    $observationByKey[$key] = $bundleResult['observation_ids'][$i];
                }
            }

            Log::info('Bundle Observation Diabetes berhasil', ['total' => count($entries)]);
        }

        // Ambil kategori paling berat sebagai Condition utama
        $kategoriUtama = $this->resolveKategoriUtama([
            $diabetes->kategori_gula_darah_puasa,
            $diabetes->kategori_gula_darah_2_jam_pp,
            $diabetes->kategori_gula_darah_sewaktu,
            $diabetes->kategori_hba1c,
        ]);

        $conditionId = null;

        if ($kategoriUtama !== 'normal') {
            $icd = $this->resolveCategory($kategoriUtama);
            $existingConditionId = $this->findExistingCondition($encounterId, $icd['code']);

            if ($existingConditionId) {
                Log::info('Condition Diabetes sudah ada, skip', ['condition_id' => $existingConditionId]);
                $conditionId = $existingConditionId;
            } else {
                $conditionPayload = $this->buildConditionPayload($icd, $patientId, $encounterId, $practitionerId);
                $conditionResponseJson = $this->createCondition($conditionPayload, $idSkrining, $puskId);
                $conditionId = is_array($conditionResponseJson) ? ($conditionResponseJson['id'] ?? null) : null;

                Log::info('Condition Diabetes berhasil', ['condition_id' => $conditionId]);
            }
        } else {
            Log::info('Condition Diabetes skip, semua kategori normal');
        }

        $diabetes->update([
            'condition_id' => $conditionId,
            'sent_at'      => now(),
        ]);

        // Prioritas: GDP dulu, baru HbA1c, 2 jam PP, terakhir sewaktu
        $priorityOrder = ['gula_darah_puasa', 'hba1c', 'gula_darah_2_jam_pp', 'gula_darah_sewaktu'];
        $primaryObservationId = null;

        foreach ($priorityOrder as $key) {
            if (isset($observationByKey[$key])) {
                $primaryObservationId = $observationByKey[$key];
                break;
            }
        }

        return [
            'observation_id' => $primaryObservationId,
            'condition_id'   => $conditionId,
        ];
    }
}
