<?php

namespace App\Services\SatuSehatPTM;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\RuangLayanan\SkriningPTM\KunjunganPTM;
use App\Models\RuangLayanan\SkriningPTM\SimpusObesitas;
use App\Models\RuangLayanan\SkriningPTM\SatuSehatLog;

class ObesitasConditionService
{
    private array $imtMap = [
        'kurus'   => ['code' => 'E46',  'display' => 'Unspecified protein-energy malnutrition'],
        'normal'  => ['code' => 'Z68.1', 'display' => 'Body mass index (BMI) 19 or less, adult'],
        'overweight'   => ['code' => 'E66.9', 'display' => 'Other obesity due to excess calories'],
        'obesitas' => ['code' => 'E66.9', 'display' => 'Obesity, unspecified'],
    ];

    private array $lpMap = [
        'normal'           => ['code' => 'Z68.1', 'display' => 'Waist circumference normal'],
        'risiko_meningkat' => ['code' => 'E66.9', 'display' => 'Obesity, unspecified - increased waist circumference'],
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

    private function logSatuSehat(
        string $idPelayanan,
        ?string $puskId,
        string $resource,
        ?string $idResponse,
        string $method,
        mixed $kirim,
        mixed $terima,
        ?string $userId,
    ): void {
        try {
            SatuSehatLog::create([
                'idPelayanan' => $idPelayanan,
                'tanggal'     => now(),
                'puskId'      => $puskId,
                'resource'    => $resource,
                'idResponse'  => $idResponse,
                'method'      => $method,
                'kirim'       => json_encode($kirim),
                'terima'      => json_encode($terima),
                'userId'      => $userId,
            ]);
        } catch (\Throwable $e) {
            // Jangan sampai gagal logging menggagalkan proses utama
            Log::error('Gagal menyimpan SatuSehatLog (Obesitas)', [
                'message'  => $e->getMessage(),
                'resource' => $resource,
            ]);
        }
    }

    private function findExisting(string $encounterId, string $icdCode): ?string
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

 
    private function createCondition(array $payload)
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->post(
                config('services.satusehat.fhir_url') . '/Condition',
                $payload
            );

        if (!$response->successful()) {
            throw new \Exception('Gagal membuat Condition: ' . $response->body());
        }

        return $response;
    }

    private function resolveImt(string $interpretasi): array
    {
        $normalized = strtolower(trim($interpretasi));

        if (!isset($this->imtMap[$normalized])) {
            Log::warning('resolveImt: interpretasi tidak dikenali, fallback ke normal', [
                'interpretasi' => $interpretasi,
            ]);
            return $this->imtMap['normal'];
        }

        return $this->imtMap[$normalized];
    }

    private function resolveLp(string $interpretasi): array
    {
        $normalized = strtolower(trim($interpretasi));
        $normalized = preg_replace('/\s+/', '_', $normalized);

        if (!isset($this->lpMap[$normalized])) {
            Log::warning('resolveLp: interpretasi tidak dikenali, fallback ke normal', [
                'interpretasi' => $interpretasi,
            ]);
            return $this->lpMap['normal'];
        }

        return $this->lpMap[$normalized];
    }

    private function buildPayload(
        array $icd,
        string $patientId,
        string $encounterId,
        string $practitionerId,
    ): array {
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
            'category' => [[
                'coding' => [[
                    'system'  => 'http://terminology.hl7.org/CodeSystem/condition-category',
                    'code'    => 'encounter-diagnosis',
                    'display' => 'Encounter Diagnosis',
                ]],
            ]],
            'code' => [
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

    public function sendCondition(string $idSkrining): array
    {
        $skrining  = KunjunganPTM::where('idSkrining', $idSkrining)->firstOrFail();
        $obesitas  = SimpusObesitas::where('skriningID', $idSkrining)->firstOrFail();

        $patientId   = $skrining->patient_id;
        $encounterId = $skrining->encounter_id;
        $practitionerId = $skrining->id_petugas;
        $puskId = Auth::user()->unit;

        $icdImt = $this->resolveImt($obesitas->interpretasi_ptm);
        $icdLp  = $this->resolveLp($obesitas->interpretasi_lp);

        $imtConditionId = null;
        $normalizedImt  = strtolower(trim($obesitas->interpretasi_ptm));

        if ($normalizedImt === 'normal') {
            Log::info('Condition IMT skip, interpretasi normal');
        } else {
            $existingImtId = $this->findExisting($encounterId, $icdImt['code']);
            if ($existingImtId) {
                Log::info('Condition IMT sudah ada, skip', ['condition_id' => $existingImtId]);
                $imtConditionId = $existingImtId;
            } else {
                $imtPayload  = $this->buildPayload($icdImt, $patientId, $encounterId, $practitionerId);
                $imtResponse = $this->createCondition($imtPayload);
                $imtResponseJson = $imtResponse->json() ?? $imtResponse->body();

                $imtConditionId  = $imtResponseJson['id'] ?? null;

                Log::info('Condition IMT berhasil', ['condition_id' => $imtConditionId]);

                $this->logSatuSehat(
                    idPelayanan: $idSkrining,
                    puskId: $puskId,
                    resource: 'Condition',
                    idResponse: $imtConditionId,
                    method: 'POST',
                    kirim: $imtPayload,
                    terima: $imtResponseJson,
                    userId: $puskId,
                );
            }
        }

        $lpConditionId  = null;
        $normalizedLp   = strtolower(trim($obesitas->interpretasi_lp));
        $normalizedLp   = preg_replace('/\s+/', '_', $normalizedLp);

        if ($normalizedLp === 'normal') {
            Log::info('Condition LP skip, interpretasi normal');
        } else {
            $existingLpId = $this->findExisting($encounterId, $icdLp['code']);
            if ($existingLpId) {
                Log::info('Condition LP sudah ada, skip', ['condition_id' => $existingLpId]);
                $lpConditionId = $existingLpId;
            } else {
                $lpPayload  = $this->buildPayload($icdLp, $patientId, $encounterId, $practitionerId);
                $lpResponse = $this->createCondition($lpPayload);
                $lpResponseJson = $lpResponse->json();
                $lpConditionId  = $lpResponseJson['id'] ?? null;

                Log::info('Condition LP berhasil', ['condition_id' => $lpConditionId]);

                //  Catat log untuk Condition LP
                $this->logSatuSehat(
                    idPelayanan: $idSkrining,
                    puskId: $puskId,
                    resource: 'Condition',
                    idResponse: $lpConditionId,
                    method: 'POST',
                    kirim: $lpPayload,
                    terima: $lpResponseJson,
                    userId: $puskId,
                );
            }
        }

        $obesitas->update([
            'condition_imt_id' => $imtConditionId,
            'condition_lp_id'  => $lpConditionId,
            'sent_at'          => now(),
        ]);

        return [
            'condition_imt_id' => $imtConditionId,
            'condition_lp_id'  => $lpConditionId,
        ];
    }
}