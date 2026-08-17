<?php

namespace App\Services\SatuSehatPTM;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\RuangLayanan\SkriningPTM\KunjunganPTM;
use App\Models\RuangLayanan\SkriningPTM\FaktorRisiko;
use App\Models\RuangLayanan\SkriningPTM\SatuSehatLog;

class QuestionnaireResponseService
{
    private array $codingMap = [
        'tidak'       => ['code' => 'QRI000014', 'display' => 'Tidak'],
        'kadang'      => ['code' => 'QRI000015', 'display' => 'Ya, tidak setiap hari'],
        'setiap_hari' => ['code' => 'QRI000016', 'display' => 'Ya, setiap hari'],
        'ya'          => ['code' => 'QRI000015', 'display' => 'Ya, setiap hari']
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

    private function createQuestionnaireResponse(array $payload, ?string $idPelayanan): string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->post(
                config('services.satusehat.fhir_url') . '/QuestionnaireResponse',
                $payload
            );

        $terima = $response->json() ?? $response->body();
        $qrId = is_array($terima) ? ($terima['id'] ?? null) : null;

        $this->simpanLog(
            idPelayanan: $idPelayanan,
            resource: 'QuestionnaireResponse',
            idResponse: $qrId,
            method: 'POST',
            kirim: $payload,
            terima: $terima,
        );

        if (!$response->successful()) {
            Log::error('SatuSehat: gagal membuat QuestionnaireResponse Faktor Risiko', [
                'idPelayanan' => $idPelayanan,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \Exception(
                'Gagal membuat QuestionnaireResponse: ' . $response->body()
            );
        }

        return $qrId;
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

    private function findExistingQuestionnaireResponse(string $encounterId): ?string
    {
        $response = Http::withToken($this->getToken())
            ->acceptJson()
            ->get(config('services.satusehat.fhir_url') . '/QuestionnaireResponse', [
                'encounter'      => $encounterId,
                'questionnaire'  => 'https://fhir.kemkes.go.id/Questionnaire/Q0013',
            ]);

        if (!$response->successful()) {
            return null;
        }

        $entries = $response->json('entry') ?? [];
        return !empty($entries) ? ($entries[0]['resource']['id'] ?? null) : null;
    }

    private function resolveCoding(string $value): array
    {
        $normalized = strtolower(trim($value));

         if (!isset($this->codingMap[$normalized])) {
        Log::warning('resolveCoding: value tidak dikenali, fallback ke tidak', [
            'original_value'    => $value,
            'normalized_value'  => $normalized,
        ]);
        return $this->codingMap['tidak'];
    }
        return $this->codingMap[$normalized];
    }

    private function resolveInteger(?int $value): int
    {
        return $value ?? 0;
    }

    public function sendFaktorRisiko(string $idSkrining, string $observationId): void
    {
        $skrining     = KunjunganPTM::where('idSkrining', $idSkrining)->firstOrFail();
        $faktorRisiko = FaktorRisiko::where('skriningID', $idSkrining)->firstOrFail();

        $patientId      = $skrining->patient_id;
        $encounterId    = $skrining->encounter_id;
        $idPelayanan    = $skrining->idPelayanan;
        $practitionerId = $skrining->id_petugas;

        $existingId = $this->findExistingQuestionnaireResponse($encounterId);
        if ($existingId) {
            Log::info('QuestionnaireResponse sudah ada, skip', [
                'questionnaire_response_id' => $existingId,
            ]);
            return;
        }

        $items = [
            [
                'linkId' => '1.1',
                'text'   => 'Apakah peserta pernah merokok?',
                'answer' => [[
                    'valueCoding' => [
                        'system' => 'http://terminology.kemkes.go.id/CodeSystem/clinical-term',
                        ...$this->resolveCoding($faktorRisiko->merokok ?? 'tidak'),
                    ],
                ]],
            ],
            [
                'linkId' => '1.2',
                'text'   => 'Berapa rata-rata jumlah batang rokok per hari',
                'answer' => [[
                    // ✅ Guard null
                    'valueInteger' => $this->resolveInteger($faktorRisiko->btg_rokok),
                ]],
            ],
            [
                'linkId' => '1.3',
                'text'   => 'Lama merokok dalam tahun',
                'answer' => [[
                    'valueQuantity' => [
                        // ✅ Guard null
                        'value'  => $this->resolveInteger($faktorRisiko->lama_rokok),
                        'unit'   => 'year',
                        'system' => 'http://unitsofmeasure.org',
                        'code'   => 'a',
                    ],
                ]],
            ],
            [
                'linkId' => '1.4',
                'text'   => 'Status merokok',
                'answer' => [[
                    'valueReference' => ['reference' => "Observation/{$observationId}"],
                ]],
            ],
            [
                'linkId' => '1.5',
                'text'   => 'Apakah peserta terpapar asap rokok orang lain dalam waktu 1 bulan terakhir?',
                'answer' => [[
                    'valueCoding' => [
                        'system' => 'http://terminology.kemkes.go.id/CodeSystem/clinical-term',
                        ...$this->resolveCoding($faktorRisiko->paparan_rokok ?? 'tidak'),
                    ],
                ]],
            ],
            [
                'linkId' => '1.6',
                'text'   => 'Apakah peserta menambahkan gula pada makanan/minuman peserta > 4 sendok makan dalam sehari?',
                'answer' => [[
                    'valueCoding' => [
                        'system' => 'http://terminology.kemkes.go.id/CodeSystem/clinical-term',
                        ...$this->resolveCoding($faktorRisiko->gula ?? 'tidak'),
                    ],
                ]],
            ],
            [
                'linkId' => '1.7',
                'text'   => 'Apakah peserta menggunakan garam pada makanan peserta > 1 sendok teh dalam sehari?',
                'answer' => [[
                    'valueCoding' => [
                        'system' => 'http://terminology.kemkes.go.id/CodeSystem/clinical-term',
                        ...$this->resolveCoding($faktorRisiko->garam ?? 'tidak'),
                    ],
                ]],
            ],
            [
                'linkId' => '1.8',
                'text'   => 'Apakah peserta konsumsi makanan yang diolah dengan minyak > 5 sendok makan dalam sehari?',
                'answer' => [[
                    'valueCoding' => [
                        'system' => 'http://terminology.kemkes.go.id/CodeSystem/clinical-term',
                        ...$this->resolveCoding($faktorRisiko->minyak ?? 'tidak'),
                    ],
                ]],
            ],
            [
                'linkId' => '1.9',
                'text'   => 'Apakah peserta makan sayur dan atau buah kurang dari 5 porsi sehari?',
                'answer' => [[
                    'valueCoding' => [
                        'system' => 'http://terminology.kemkes.go.id/CodeSystem/clinical-term',
                        ...$this->resolveCoding($faktorRisiko->sayur ?? 'tidak'),
                    ],
                ]],
            ],
            [
                'linkId' => '1.10',
                'text'   => 'Apakah peserta melakukan aktivitas fisik kurang dari minimal 30 menit/ hari atau minimal 150 menit/minggu?',
                'answer' => [[
                    'valueCoding' => [
                        'system' => 'http://terminology.kemkes.go.id/CodeSystem/clinical-term',
                        ...$this->resolveCoding($faktorRisiko->aktivitas ?? 'tidak'),
                    ],
                ]],
            ],
            [
                'linkId' => '1.11',
                'text'   => 'Apakah peserta konsumsi alkohol dalam waktu 1 bulan terakhir?',
                'answer' => [[
                    'valueCoding' => [
                        'system' => 'http://terminology.kemkes.go.id/CodeSystem/clinical-term',
                        ...$this->resolveCoding($faktorRisiko->alkohol ?? 'tidak'),
                    ],
                ]],
            ],
        ];

        $payload = [
            'resourceType'  => 'QuestionnaireResponse',
            'questionnaire' => 'https://fhir.kemkes.go.id/Questionnaire/Q0013',
            'status'        => 'completed',
            'authored'      => now()->toIso8601String(),
            'source'        => ['reference' => "Practitioner/" . $practitionerId],
            'subject'       => ['reference' => "Patient/{$patientId}"],
            'encounter'     => ['reference' => "Encounter/{$encounterId}"],
            'author'        => ['reference' => "Practitioner/" . $practitionerId],
            'item'          => [[
                'linkId' => '1',
                'text'   => 'Faktor Risiko PTM',
                'item'   => $items,
            ]],
        ];

        try {
            $id = $this->createQuestionnaireResponse($payload, $idPelayanan);
            Log::info('QuestionnaireResponse berhasil', [
                'questionnaire_response_id' => $id,
                'payload' => $payload,
            ]);
        } catch (\Exception $e) {
            Log::error('QuestionnaireResponse gagal', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}