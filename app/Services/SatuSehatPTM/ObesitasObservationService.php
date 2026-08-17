<?php

namespace App\Services\SatuSehatPTM;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\RuangLayanan\SkriningPTM\KunjunganPTM;
use App\Models\RuangLayanan\SkriningPTM\SimpusObesitas;
use App\Models\RuangLayanan\SkriningPTM\SatuSehatLog;
use League\CommonMark\Reference\Reference;

class ObesitasObservationService
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
        try {
            SatuSehatLog::updateOrCreate([
                'idPelayanan' => $idPelayanan,
                'tanggal'     => now(),
                'puskId'      => $puskId,
                'resource'    => $resource,
                'idResponse'  => $idResponse,
                'method'      => $method,
                'kirim'       => is_array($kirim) ? json_encode($kirim) : $kirim,
                'terima'      => is_array($terima) ? json_encode($terima) : $terima,
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

    private function findExisting(string $encounterId, string $loincCode): ?string
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

    private function sendBundle(array $payload): array
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->post(
                config('services.satusehat.fhir_url'),
                $payload
            );

        if (!$response->successful()) {
            throw new \Exception('Gagal mengirim Bundle Antropometri: ' . $response->body());
        }

        return $response->json();
    }

    private function buildObservation(
        string $loincCode,
        string $loincDisplay,
        float $value,
        string $unit,
        string $ucumCode,
        string $patientId,
        string $encounterId,
        string $effectiveAt,
        string $fullUrl,
        string $practitionerId,
    ): array {
        return [
            'fullUrl'  => $fullUrl,
            'resource' => [
                'resourceType' => 'Observation',
                'status'       => 'final',
                'category'     => [[
                    'coding' => [[
                        'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
                        'code'    => 'vital-signs',
                        'display' => 'Vital Signs',
                    ]],
                ]],
                'code' => [
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
                'valueQuantity' => [
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

    /**
     * Ekstrak ID resource dari header/field "location" FHIR.
     * Menangani dua format:
     *   - "Observation/1234"
     *   - "Observation/1234/_history/1"
     * Mengambil segmen tepat setelah nama resource ("Observation"),
     * bukan segmen terakhir — supaya tidak keliru ambil nomor versi history.
     */
    private function extractIdFromLocation(string $location): ?string
    {
        $parts = explode('/', trim($location, '/'));
        $resourceIndex = array_search('Observation', $parts);

        if ($resourceIndex !== false && isset($parts[$resourceIndex + 1])) {
            return $parts[$resourceIndex + 1];
        }

        // Fallback kalau format tidak sesuai dugaan
        return end($parts) ?: null;
    }

    public function sendAntropometri(string $idSkrining): array
    {
        $skrining  = KunjunganPTM::where('idSkrining', $idSkrining)->firstOrFail();
        $obesitas  = SimpusObesitas::where('skriningID', $idSkrining)->firstOrFail();

        $patientId   = $skrining->patient_id;
        $encounterId = $skrining->encounter_id;
        $practitionerId = $skrining->id_petugas;
        $effectiveAt = now()->toIso8601String();
        $puskId      = Auth::user()->unit;

        $existingId = $this->findExisting($encounterId, '39156-5');
        if ($existingId) {
            Log::info('Observation Antropometri sudah ada, skip', [
                'observation_id' => $existingId,
            ]);

            $this->logSatuSehat(
                idPelayanan: $idSkrining,
                puskId: $puskId,
                resource: 'Observation-Antropometri',
                idResponse: $existingId,
                method: 'GET',
                kirim: ['encounter' => $encounterId, 'code' => '39156-5'],
                terima: ['observation_id' => $existingId, 'note' => 'sudah ada, skip create'],
                userId: $puskId,
            );

            $obesitas->update(['sent_at' => now()]);

            return ['observation_id' => $existingId];
        }

        // Pakai key deskriptif, bukan index numerik, supaya tidak salah ambil
        // observation saat mencocokkan balik ke response bundle.
        $entriesMap = [
            'berat_badan' => $this->buildObservation(
                loincCode: '29463-7',
                loincDisplay: 'Body weight',
                value: (float) $obesitas->berat_badan,
                unit: 'kg',
                ucumCode: 'kg',
                patientId: $patientId,
                encounterId: $encounterId,
                effectiveAt: $effectiveAt,
                fullUrl: 'urn:uuid:' . \Str::uuid(),
                practitionerId: $practitionerId,
            ),
            'tinggi_badan' => $this->buildObservation(
                loincCode: '8302-2',
                loincDisplay: 'Body height',
                value: (float) $obesitas->tinggi_badan,
                unit: 'cm',
                ucumCode: 'cm',
                patientId: $patientId,
                encounterId: $encounterId,
                effectiveAt: $effectiveAt,
                fullUrl: 'urn:uuid:' . \Str::uuid(),
                practitionerId: $practitionerId,
            ),
            'imt' => $this->buildObservation(
                loincCode: '39156-5',
                loincDisplay: 'Body mass index (BMI) [Ratio]',
                value: (float) $obesitas->imt,
                unit: 'kg/m2',
                ucumCode: 'kg/m2',
                patientId: $patientId,
                encounterId: $encounterId,
                effectiveAt: $effectiveAt,
                fullUrl: 'urn:uuid:' . \Str::uuid(),
                practitionerId: $practitionerId,
            ),
            'lingkar_pinggang' => $this->buildObservation(
                loincCode: '56086-2',
                loincDisplay: 'Waist circumference',
                value: (float) $obesitas->lingkar_pinggang,
                unit: 'cm',
                ucumCode: 'cm',
                patientId: $patientId,
                encounterId: $encounterId,
                effectiveAt: $effectiveAt,
                fullUrl: 'urn:uuid:' . \Str::uuid(),
                practitionerId: $practitionerId,
            ),
        ];

        $bundlePayload = [
            'resourceType' => 'Bundle',
            'type'         => 'transaction',
            'entry'        => array_values($entriesMap),
        ];


        $keys = array_keys($entriesMap);

        try {
            $result = $this->sendBundle($bundlePayload);

            $imtIndex = array_search('imt', $keys);
            $location = $result['entry'][$imtIndex]['response']['location'] ?? null;
            $observationId = $location ? $this->extractIdFromLocation($location) : null;

            Log::info('Bundle Antropometri berhasil', [
                'observation_imt_id' => $observationId,
            ]);

            $this->logSatuSehat(
                idPelayanan: $idSkrining,
                puskId: $puskId,
                resource: 'Observation-Antropometri',
                idResponse: $observationId,
                method: 'POST',
                kirim: $bundlePayload,
                terima: $result,
                userId: $puskId,
            );

            $obesitas->update([
                'observation_id' => $observationId,
                'sent_at'        => now(),
            ]);

            return ['observation_id' => $observationId];
        } catch (\Exception $e) {
            Log::error('Bundle Antropometri gagal', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
