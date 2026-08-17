<?php

namespace App\Services\SatuSehatPTM;

use App\Models\RuangLayanan\SkriningPTM\KunjunganPTM;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\SimpusDataEdukasi;
use App\Models\RuangLayanan\SkriningPTM\SimpusSkriningPTM;
use App\Models\RuangLayanan\SkriningPTM\SatuSehatLog;

class EdukasiProcedureService
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

    private function findExistingProcedure(string $encounterId, string $kodeSnomed): ?string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->get(config('services.satusehat.fhir_url') . '/Procedure', [
                'encounter' => $encounterId,
                'code'      => "http://snomed.info/sct|{$kodeSnomed}",
            ]);

        if (!$response->successful()) return null;

        $entries = $response->json('entry') ?? [];
        return !empty($entries) ? ($entries[0]['resource']['id'] ?? null) : null;
    }

    private function createProcedure(array $payload, ?string $idPelayanan): string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->post(config('services.satusehat.fhir_url') . '/Procedure', $payload);

        $terima = $response->json() ?? $response->body();
        $procedureId = is_array($terima) ? ($terima['id'] ?? null) : null;

        $this->simpanLog(
            idPelayanan: $idPelayanan,
            resource: 'Procedure',
            idResponse: $procedureId,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
        );

        if (!$response->successful()) {
            Log::error('SatuSehat: gagal membuat Procedure Edukasi', [
                'idPelayanan' => $idPelayanan,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \Exception('Gagal membuat Procedure Edukasi: ' . $response->body());
        }

        return $procedureId;
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

    private function buildPayload(
        string $patientId,
        string $encounterId,
        string $kodeSnomed,
        string $display,
        string $performedDate,
        string $practitionerId,
    ): array {
        return [
            'resourceType'      => 'Procedure',
            'status'            => 'completed',
            'category'          => [
                'coding' => [[
                    'system'  => 'http://snomed.info/sct',
                    'code'    => '409073007',
                    'display' => 'Education',
                ]],
            ],
            'code'              => [
                'coding' => [[
                    'system'  => 'http://snomed.info/sct',
                    'code'    => $kodeSnomed,
                    'display' => $display,
                ]],
                'text'   => $display,
            ],
            'subject'           => ['reference' => "Patient/{$patientId}"],
            'encounter'         => ['reference' => "Encounter/{$encounterId}"],
            'performedDateTime' => $performedDate,
            'performer'         => [[
                'actor' => [
                    'reference' => 'Practitioner/' . $practitionerId,
                ],
            ]],
            'location'          => [
                'reference' => 'Location/' . config('services.satusehat.location_id'),
            ],
        ];
    }

    /**
     * Kirim semua data edukasi milik satu skrining ke SATUSEHAT
     *
     * @param  string $skriningId → sama dengan skriningID di simpus_data_edukasi / idSkrining di simpus_skrining_ptm
     * @return array
     */
    public function sendEdukasi(string $skriningId): array
    {
        $skrining = KunjunganPTM::where('idSkrining', $skriningId)->firstOrFail();

        $patientId   = $skrining->patient_id;
        $encounterId = $skrining->encounter_id;
        $idPelayanan = $skrining->idPelayanan;
        $practitionerId = $skrining->id_petugas;

        $edukasiList = SimpusDataEdukasi::select(
            'simpus_data_edukasi.*',
            'master_edukasi_ptm.*'
        )
            ->join('master_edukasi_ptm', 'master_edukasi_ptm.kode_snomed', '=', 'simpus_data_edukasi.kode_snomed')
            ->where('skriningID', $skriningId)
            ->get();

        if ($edukasiList->isEmpty()) {
            Log::info('Tidak ada data edukasi untuk skriningID', ['skriningID' => $skriningId]);
            return [];
        }

        $results = [];

        foreach ($edukasiList as $edukasi) {
            $kodeSnomed = $edukasi->kode_snomed;
            $display = $edukasi->display ?? $kodeSnomed;
            $performedDate = now()->toIso8601String();

            // Skip jika kode SNOMED kosong
            if (empty($kodeSnomed)) {
                Log::warning('Edukasi skip: kode_snomed kosong', [
                    'id' => $edukasi->id,
                ]);
                $results[] = [
                    'id'          => $edukasi->id,
                    'kode_snomed' => $kodeSnomed,
                    'procedureId' => null,
                    'status'      => 'skip',
                    'message'     => 'kode_snomed kosong',
                ];
                continue;
            }

            // Cek apakah sudah pernah dikirim (by procedureId di DB)
            if (!empty($edukasi->procedureId)) {
                Log::info('Procedure edukasi sudah ada di DB, skip', [
                    'id'          => $edukasi->id,
                    'procedureId' => $edukasi->procedureId,
                ]);
                $results[] = [
                    'id'          => $edukasi->id,
                    'kode_snomed' => $kodeSnomed,
                    'procedureId' => $edukasi->procedureId,
                    'status'      => 'already_exists',
                    'message'     => 'Sudah pernah dikirim sebelumnya',
                ];
                continue;
            }

            // Cek apakah sudah ada di SATUSEHAT by encounter + kode SNOMED
            $existingId = $this->findExistingProcedure($encounterId, $kodeSnomed);

            if ($existingId) {
                Log::info('Procedure edukasi sudah ada di SATUSEHAT, simpan ID', [
                    'id'          => $edukasi->id,
                    'procedureId' => $existingId,
                ]);

                $edukasi->update(['procedureId' => $existingId]);

                $results[] = [
                    'id'          => $edukasi->id,
                    'kode_snomed' => $kodeSnomed,
                    'procedureId' => $existingId,
                    'status'      => 'already_exists',
                    'message'     => 'Ditemukan di SATUSEHAT, ID disimpan',
                ];
                continue;
            }

            // Kirim ke SATUSEHAT
            try {
                $payload     = $this->buildPayload(
                    $patientId,
                    $encounterId,
                    $kodeSnomed,
                    $display,
                    $performedDate,
                    $practitionerId,
                );
                $procedureId = $this->createProcedure($payload, $idPelayanan);

                $edukasi->update(['procedureId' => $procedureId]);

                Log::info('Procedure Edukasi berhasil', [
                    'id'          => $edukasi->id,
                    'kode_snomed' => $kodeSnomed,
                    'procedureId' => $procedureId,
                ]);

                $results[] = [
                    'id'          => $edukasi->id,
                    'kode_snomed' => $kodeSnomed,
                    'procedureId' => $procedureId,
                    'status'      => 'berhasil',
                    'message'     => null,
                ];
            } catch (\Exception $e) {
                Log::error('Procedure Edukasi gagal', [
                    'id'          => $edukasi->id,
                    'kode_snomed' => $kodeSnomed,
                    'error'       => $e->getMessage(),
                ]);

                $results[] = [
                    'id'          => $edukasi->id,
                    'kode_snomed' => $kodeSnomed,
                    'procedureId' => null,
                    'status'      => 'gagal',
                    'message'     => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}