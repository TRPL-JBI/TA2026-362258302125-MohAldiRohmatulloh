<?php

namespace App\Services\SatuSehatPTM;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\RuangLayanan\SkriningPTM\SatuSehatLog;

class ServiceRequestService
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

    private function createServiceRequest(array $payload, ?string $idPelayanan): string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->post(config('services.satusehat.fhir_url') . '/ServiceRequest', $payload);

        $terima = $response->json() ?? $response->body();
        $serviceRequestId = is_array($terima) ? ($terima['id'] ?? null) : null;

        $this->simpanLog(
            idPelayanan: $idPelayanan,
            resource: 'ServiceRequest',
            idResponse: $serviceRequestId,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
        );

        if (!$response->successful()) {
            Log::error('SatuSehat: gagal membuat ServiceRequest', [
                'idPelayanan' => $idPelayanan,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \Exception('Gagal membuat ServiceRequest: ' . $response->body());
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

    /**
     * Map value form 'rujukan' ke deskripsi kode/kategori ServiceRequest.
     */
    private function mapRencanaRujuk(string $rujukan): array
    {
        return match ($rujukan) {
            'internal' => [
                'display' => 'Konsultasi internal puskesmas',
            ],
            'fkrtl' => [
                'display' => 'Rujuk ke FKRTL',
            ],
            'igd' => [
                'display' => 'Rujuk segera / IGD',
            ],
            default => [
                'display' => 'Tindak lanjut lainnya',
            ],
        };
    }

    /**
     * Kirim rencana tindak lanjut (rujukan + jadwal kontrol + transportasi) sebagai ServiceRequest.
     *
     * @param  string      $patientId
     * @param  string      $patientName
     * @param  string      $encounterId
     * @param  string      $practitionerId
     * @param  string      $rencanaRujuk    tidak | internal | fkrtl | igd
     * @param  string|null $jadwalKontrol   format Y-m-d
     * @param  string      $transport       tidak_berlaku | ambulan | kendaraan_pribadi | ojek
     * @param  string      $authoredOn      ISO8601 datetime
     * @param  string|null $idPelayanan     idPelayanan di simpus_skrining_ptm, untuk log
     * @return string|null  ID ServiceRequest, null jika rencanaRujuk = 'tidak' dan tidak ada jadwal kontrol
     */
    public function sendRencanaTindakLanjut(
        string $patientId,
        string $patientName,
        string $encounterId,
        string $practitionerId,
        string $rencanaRujuk,
        ?string $jadwalKontrol,
        string $transport,
        string $authoredOn,
        ?string $idPelayanan = null,
    ): ?string {
        // Kalau tidak ada rencana rujuk & tidak ada jadwal kontrol, tidak perlu kirim apa pun
        if ($rencanaRujuk === 'tidak' && empty($jadwalKontrol)) {
            Log::info('ServiceRequest skip: tidak ada rencana rujuk maupun jadwal kontrol');
            return null;
        }

        $mapped = $this->mapRencanaRujuk($rencanaRujuk);

        $transportDisplay = match ($transport) {
            'ambulance'           => 'Ambulans',
            'kendaraan_pribadi' => 'Kendaraan pribadi',
            'ojek'              => 'Ojek/taksi',
            default             => 'Tidak berlaku',
        };

        $payload = [
            'resourceType' => 'ServiceRequest',
            'status'       => 'active',
            'intent'       => 'plan',
            'category'     => [[
                'coding' => [[
                    'system'  => 'http://snomed.info/sct',
                    'code'    => '306206005',
                    'display' => 'Referral for treatment',
                ]],
            ]],
            'code' => [
                'coding' => [[
                    'system'  => 'http://snomed.info/sct',
                    'code'    => '306206005',
                    'display' => 'Referral for treatment',
                ]],
                'text' => $mapped['display'],
            ],
            'subject' => [
                'reference' => "Patient/{$patientId}",
                'display'   => $patientName,
            ],
            'encounter' => [
                'reference' => "Encounter/{$encounterId}",
            ],
            'requester' => [
                'reference' => "Practitioner/{$practitionerId}",
            ],
            'performer' => [[
                'reference' => 'Organization/' . config('services.satusehat.organization_id'),
            ]],
            'authoredOn' => $authoredOn,
            'note' => [[
                'text' => 'Transportasi rujuk: ' . $transportDisplay,
            ]],
        ];

        if (!empty($jadwalKontrol)) {
            $payload['occurrenceDateTime'] = \Carbon\Carbon::parse($jadwalKontrol)->toIso8601String();
        }

        $serviceRequestId = $this->createServiceRequest($payload, $idPelayanan);

        Log::info('ServiceRequest rencana tindak lanjut berhasil', [
            'serviceRequestId' => $serviceRequestId,
            'rencanaRujuk'     => $rencanaRujuk,
        ]);

        return $serviceRequestId;
    }
}
