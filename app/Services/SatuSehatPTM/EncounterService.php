<?php

namespace App\Services\SatuSehatPTM;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\RuangLayanan\SkriningPTM\KunjunganPTM;
use App\Models\RuangLayanan\SkriningPTM\SimpusSkriningPTM;
use App\Models\RuangLayanan\SkriningPTM\SatuSehatLog;

class EncounterService
{
    public function getAccessToken(): string
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])
            ->withBody(
                http_build_query([
                    'client_id' => config('services.satusehat.client_id'),
                    'client_secret' => config('services.satusehat.client_secret'),
                ]),
                'application/x-www-form-urlencoded'
            )
            ->post(
                config('services.satusehat.auth_url'),
            );

        if (! $response->successful()) {
            Log::error('SatuSehat: gagal mendapatkan access token', [
                'status' => $response->status(),
                'body' => $response->body(),
                'auth_url' => config('services.satusehat.auth_url'),
            ]);
        }

        $token = $response->json('access_token');

        if (empty($token)) {
            Log::warning('SatuSehat: access_token kosong/null dari response auth', [
                'response' => $response->json(),
            ]);
        }

        // dd(
        //     $response->status(),
        //     $response->body()
        // );
        return $token;
    }

    public function updateDischargeStatus(
        string $encounterId,
        string $caraKeluar,
        string $periodEnd,
        ?string $idPelayanan = null,
        array $conditionIds = [],
    ): array {
        $token = $this->getAccessToken();

        $getResponse = Http::withToken($token)
            ->get(config('services.satusehat.fhir_url') . '/Encounter/' . $encounterId);

        if (! $getResponse->successful()) {
            Log::error('SatuSehat: gagal GET Encounter existing untuk update discharge', [
                'encounterId' => $encounterId,
                'status'      => $getResponse->status(),
                'body'        => $getResponse->body(),
            ]);
            throw new \Exception('Gagal mengambil data Encounter: ' . $getResponse->body());
        }

        $existing = $getResponse->json();

        $caraKeluarDisplay = match ($caraKeluar) {
            'home'     => 'Home',
            'aadvice'  => 'Left against advice',
            'alt-home' => 'Alternative home',
            'oth'      => 'Other',
            default    => 'Other',
        };

        $statusHistory = $existing['statusHistory'] ?? [];

        $statusHistory = array_map(function ($item) use ($periodEnd) {
            if (empty($item['period']['end'])) {
                $item['period']['end'] = $periodEnd;
            }
            return $item;
        }, $statusHistory);

        // Tambahkan entry untuk status akhir 'finished' (start = end = periodEnd)
        $statusHistory[] = [
            'status' => 'finished',
            'period' => [
                'start' => $periodEnd,
                'end'   => $periodEnd,
            ],
        ];

        $diagnosis = array_map(function ($cond) {
            return [
                'condition' => [
                    'reference' => 'Condition/' . $cond['id'],
                    'display'   => $cond['display'] ?? null,
                ],
            ];
        }, $conditionIds);

        $existing['status'] = 'finished';
        $existing['statusHistory'] = $statusHistory;
        $existing['period']['end'] = $periodEnd;
        $existing['hospitalization'] = [
            'dischargeDisposition' => [
                'coding' => [[
                    'system'  => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                    'code'    => $caraKeluar,
                    'display' => $caraKeluarDisplay,
                ]],
            ],
        ];

        if (!empty($diagnosis)) {
            $existing['diagnosis'] = $diagnosis;
        }

        $response = Http::withToken($token)
            ->put(config('services.satusehat.fhir_url') . '/Encounter/' . $encounterId, $existing);

        $terima = $response->json() ?? $response->body();

        $this->simpanLog(
            idPelayanan: $idPelayanan,
            resource: 'Encounter-Discharge',
            idResponse: $encounterId,
            method: 'PUT',
            kirim: $existing,
            terima: $terima,
        );

        if (! $response->successful()) {
            Log::error('SatuSehat: gagal update discharge status Encounter', [
                'encounterId' => $encounterId,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \Exception('Gagal update status keluar pasien: ' . $response->body());
        }

        return $terima;
    }
    public function createEncounter(array $payload, ?string $idPelayanan, ?string $idEncounter = null): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->post(
                config('services.satusehat.fhir_url') . '/Encounter',
                $payload
            );

        Log::info('SatuSehat: response createEncounter', [
            'idSkrining' => $idPelayanan,
            'status' => $response->status(),
            'successful' => $response->successful(),
        ]);

        $terima = $response->json() ?? $response->body();

        // idEncounter dari FHIR (root 'id') menang jika ada; kalau tidak, pakai yang dikirim caller.
        $encounterId = (is_array($terima) ? ($terima['id'] ?? null) : null) ?? $idEncounter;

        $this->simpanLog(
            idPelayanan: $idPelayanan,
            resource: 'Encounter',
            idResponse: $encounterId,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
        );

        if (! $response->successful()) {
            Log::error('SatuSehat: gagal createEncounter', [
                'idSkrining' => $idPelayanan,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new \Exception($response->body());
        }

        return [
            'encounterId' => $encounterId,
            'accessToken' => $token,
        ];
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
            'tanggal' => now(),
            'puskId' => Auth::user()->unit,
            'resource' => $resource,
            'idResponse' => $idResponse,
            'method' => $method,
            'kirim' => json_encode($kirim),
            'terima' => json_encode($terima),
            'userId' => Auth::id(),
        ];

        try {
            $log = SatuSehatLog::create($data);

            Log::info('SatuSehat: log tersimpan ke satu_sehat_log', [
                'id' => $log->id ?? null,
                'idPelayanan' => $idPelayanan,
                'resource' => $resource,
            ]);
        } catch (\Throwable $e) {
            Log::error('SatuSehat: GAGAL menyimpan ke satu_sehat_log', [
                'message' => $e->getMessage(),
                'idPelayanan' => $idPelayanan,
                'resource' => $resource,
                'userId' => Auth::id(),
                'panjang_kirim' => strlen((string) $data['kirim']),
                'panjang_terima' => strlen((string) $data['terima']),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function kirimEncounter(string $idSkrining): array
    {
        Log::info('SatuSehat: mulai kirimEncounter', ['idSkrining' => $idSkrining]);

        try {
            $skrining = KunjunganPTM::select(
                'simpus_kunjungan_ptm.*',
                'simpus_pasien.NAMA_LGKP',
                'simpus_pasien.NIK',
                'simpus_pasien.IHS_NUMBER',
            )
                ->join(
                    'simpus_pasien',
                    'simpus_pasien.NIK',
                    '=',
                    'simpus_kunjungan_ptm.nik_pasien'
                )
                ->where('simpus_kunjungan_ptm.idSkrining', $idSkrining)
                ->firstOrFail();
        } catch (\Throwable $e) {
            Log::error('SatuSehat: data skrining/pasien tidak ditemukan', [
                'idSkrining' => $idSkrining,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $patientId = $skrining->IHS_NUMBER;
        $patientName = $skrining->NAMA_LGKP;
        $practitionerId = $skrining->id_petugas;
        $idPelayanan = $skrining->idPelayanan;

        // dd($patientName, $patientId);

        $payload = [
            'resourceType' => 'Encounter',
            'status' => 'arrived',
            'statusHistory' => [
                [
                    'status' => 'arrived',
                    'period' => [
                        'start' => now()->toIso8601String(),
                    ],
                ],
            ],
            'class' => [
                'system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                'code' => 'AMB',
                'display' => 'ambulatory',
            ],
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/encounter/' . config('services.satusehat.organization_id'),
                    'value' => $idSkrining,
                ],
            ],
            'subject' => [
                'reference' => 'Patient/' . $patientId,
                'display' => $patientName,
            ],
            'participant' => [
                [
                    'type' => [
                        [
                            'coding' => [
                                [
                                    'system' => 'http://terminology.hl7.org/CodeSystem/v3-ParticipationType',
                                    'code' => 'ATND',
                                    'display' => 'attender',
                                ],
                            ],
                        ],
                    ],
                    'individual' => [
                        'reference' => 'Practitioner/' . $practitionerId,
                        'display' => 'Practitioner 1',
                    ],
                ],
            ],
            'period' => [
                'start' => now()->toIso8601String(),
            ],
            'location' => [
                [
                    'location' => [
                        'reference' => 'Location/' . config('services.satusehat.location_id'),
                        'display' => config('services.satusehat.location_name'),
                    ],
                ],
            ],
            'serviceProvider' => [
                'reference' => 'Organization/' . config('services.satusehat.organization_id'),
            ],
        ];

        $result = $this->createEncounter($payload, $idPelayanan);
        $encounterId = $result['encounterId'];
        $accessToken = $result['accessToken'];

        try {
            KunjunganPTM::updateOrCreate(
                [
                    'idSkrining' => $idSkrining,
                ],
                [
                    'encounter_id' => $encounterId,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('SatuSehat: gagal updateOrCreate SimpusSkriningPTM', [
                'idSkrining' => $idSkrining,
                'encounterId' => $encounterId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        Log::info('SatuSehat: kirimEncounter sukses', [
            'idSkrining' => $idSkrining,
            'encounterId' => $encounterId,
        ]);

        return [
            'encounterId' => $encounterId,
            'accessToken' => $accessToken,
        ];
    }
}
