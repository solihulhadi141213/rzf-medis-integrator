<?php
/**
 * Update Episode Of Care
 * Endpoint: PUT /_API/EpisodeOfCare/UpdateEpisodeOfCare.php?episodeOfCareId={id}
 * Header: token, account_token
 * Body: JSON {
 *     "diagnosisId": 12,
 *     "medicalPersonelId": 5,
 *     "episodeOfCareReferenceId": 2,
 *     "episodeOfCareStatus": "active",
 *     "episodeOfCareStart": "2026-07-23",
 *     "episodeOfCareEnd": "",
 *     "encounterId": [20, 22]
 * }
 *
 * - Validasi mandatory.
 * - Update episode_of_care dan episode_of_care_details.
 * - Sinkronisasi ke SATUSEHAT dengan PUT, menyertakan field 'type' jika referensi tersedia.
 * - Menampilkan error lengkap dari SATUSEHAT untuk debugging.
 */

// --- 1. Response Header ---
header('Content-Type: application/json');
header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
header("Cache-Control: no-store, no-cache, must-revalidate");
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: false');
header("Access-Control-Allow-Methods: PUT");
header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, token, account_token");

date_default_timezone_set('UTC');

// --- 2. Include Dependencies ---
include "../../_Config/Connection.php";
include "../../_Config/Helper.php";
require "../../_Config/RateLimiter.php";

// --- 3. Rate Limiter ---
$Limiter = new RateLimiter($Conn);
$Limiter->check("update_episode_of_care", 5, 60);

// --- 4. Validasi Method ---
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(["response" => ["message" => "Metode request tidak diizinkan", "code" => 405], "metadata" => []]);
    exit;
}

// --- 5. Validasi Header ---
$apiToken     = getRequestHeader('token');
$accountToken = getRequestHeader('account_token');
if (empty($apiToken)) {
    http_response_code(401);
    echo json_encode(["response" => ["message" => "Token Kredensial Aplikasi Tidak Boleh Kosong", "code" => 401], "metadata" => []]);
    exit;
}
if (empty($accountToken)) {
    http_response_code(401);
    echo json_encode(["response" => ["message" => "Token Sesi Akses Tidak Boleh Kosong", "code" => 401], "metadata" => []]);
    exit;
}

// --- 6. Validasi Parameter episodeOfCareId ---
if (!isset($_GET['episodeOfCareId']) || !is_numeric($_GET['episodeOfCareId']) || (int)$_GET['episodeOfCareId'] <= 0) {
    http_response_code(400);
    echo json_encode(["response" => ["message" => "Parameter episodeOfCareId wajib diisi dengan angka positif", "code" => 400], "metadata" => []]);
    exit;
}
$episodeOfCareId = (int) $_GET['episodeOfCareId'];

// --- 7. Validasi Token & Permission ---
$nowUtc = gmdate('Y-m-d H:i:s');
try {
    $stmt = $Conn->prepare("
        SELECT t.*, k.client_id, k.api_name, k.id_api_key 
        FROM api_token t 
        JOIN api_key k ON t.id_api_key = k.id_api_key 
        WHERE t.token = :token LIMIT 1
    ");
    $stmt->execute([':token' => $apiToken]);
    $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tokenData || $tokenData['datetime_expired'] < $nowUtc) {
        http_response_code(401);
        echo json_encode(["response" => ["message" => "Token tidak valid / kadaluarsa", "code" => 401], "metadata" => []]);
        exit;
    }

    $stmt = $Conn->prepare("
        SELECT accountId 
        FROM account_token 
        WHERE account_token = :account_token 
        AND datetime_expired >= :nowUtc LIMIT 1
    ");
    $stmt->execute([':account_token' => $accountToken, ':nowUtc' => $nowUtc]);
    $accountTokenData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$accountTokenData) {
        http_response_code(401);
        echo json_encode(["response" => ["message" => "account_token tidak valid", "code" => 401], "metadata" => []]);
        exit;
    }
    $loggedInAccountId = (int) $accountTokenData['accountId'];

    $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_episode_of_care' LIMIT 1");
    $stmt->execute();
    $feature = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
        http_response_code(403);
        echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah Episode of Care", "code" => 403], "metadata" => []]);
        exit;
    }
} catch (PDOException $e) {
    error_log('[UpdateEpisodeOfCare] Auth error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    exit;
}

// --- 8. Ambil data existing ---
try {
    $stmt = $Conn->prepare("
        SELECT eoc.*, p.satuSehatCode AS patientSatuSehat, d.idCondition AS diagnosisSatuSehat,
               mp.id_practitioner AS practitionerSatuSehat
        FROM episode_of_care eoc
        LEFT JOIN patient p ON eoc.patientId = p.patientId
        LEFT JOIN diagnosis d ON eoc.diagnosisId = d.diagnosisId
        LEFT JOIN medical_personel mp ON eoc.medicalPersonelId = mp.medicalPersonelId
        WHERE eoc.episodeOfCareId = :id LIMIT 1
    ");
    $stmt->execute([':id' => $episodeOfCareId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(["response" => ["message" => "Episode of Care tidak ditemukan", "code" => 404], "metadata" => []]);
        exit;
    }
    $oldPatientId = (int) $existing['patientId'];
    $oldSatuSehatCode = $existing['satuSehatCode'];
    $oldDiagnosisId = (int) $existing['diagnosisId'];
    $oldMedicalPersonelId = (int) $existing['medicalPersonelId'];
    $oldTypeSystem = $existing['episodeOfCareTypeSystem'];
    $oldTypeCode = $existing['episodeOfCareTypeCode'];
    $oldTypeDisplay = $existing['episodeOfCareTypeDisplay'];
    $oldStatus = $existing['episodeOfCareStatus'];
    $oldStart = $existing['episodeOfCareStart'];
    $oldEnd = $existing['episodeOfCareEnd'];
} catch (PDOException $e) {
    error_log('[UpdateEpisodeOfCare] Fetch existing error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    exit;
}

// --- 9. Ambil daftar encounterId existing ---
try {
    $stmt = $Conn->prepare("SELECT encounterId FROM episode_of_care_details WHERE episodeOfCareId = :id");
    $stmt->execute([':id' => $episodeOfCareId]);
    $oldEncounterIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log('[UpdateEpisodeOfCare] Fetch old encounterIds error: ' . $e->getMessage());
    $oldEncounterIds = [];
}

// --- 10. Parse JSON Body ---
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["response" => ["message" => "Invalid JSON payload", "code" => 400], "metadata" => []]);
    exit;
}

// --- 11. Ambil nilai dari body ---
$diagnosisId = isset($input['diagnosisId']) ? (int) $input['diagnosisId'] : $oldDiagnosisId;
$medicalPersonelId = isset($input['medicalPersonelId']) ? (int) $input['medicalPersonelId'] : $oldMedicalPersonelId;
$episodeOfCareReferenceId = isset($input['episodeOfCareReferenceId']) ? (int) $input['episodeOfCareReferenceId'] : 0;
$episodeOfCareStatus = isset($input['episodeOfCareStatus']) ? trim($input['episodeOfCareStatus']) : $oldStatus;
$episodeOfCareStart = isset($input['episodeOfCareStart']) ? trim($input['episodeOfCareStart']) : $oldStart;
$episodeOfCareEnd = isset($input['episodeOfCareEnd']) ? trim($input['episodeOfCareEnd']) : $oldEnd;
$encounterId = isset($input['encounterId']) ? $input['encounterId'] : $oldEncounterIds;

// --- 12. Validasi Field Wajib ---
$required = ['diagnosisId', 'medicalPersonelId', 'episodeOfCareStatus', 'episodeOfCareStart', 'encounterId'];
foreach ($required as $field) {
    if (empty($$field)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "Field '$field' wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }
}
if (!is_array($encounterId) || count($encounterId) == 0) {
    http_response_code(422);
    echo json_encode(["response" => ["message" => "encounterId harus berupa array dengan minimal 1 item", "code" => 422], "metadata" => []]);
    exit;
}
if ($diagnosisId <= 0 || $medicalPersonelId <= 0) {
    http_response_code(422);
    echo json_encode(["response" => ["message" => "diagnosisId dan medicalPersonelId harus bernilai positif", "code" => 422], "metadata" => []]);
    exit;
}

// --- 13. Validasi diagnosisId ---
$diagnosisSatuSehat = null;
try {
    $stmt = $Conn->prepare("SELECT diagnosisId, patientId, idCondition FROM diagnosis WHERE diagnosisId = :id LIMIT 1");
    $stmt->execute([':id' => $diagnosisId]);
    $diagnosis = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$diagnosis) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "diagnosisId tidak ditemukan", "code" => 422], "metadata" => []]);
        exit;
    }
    if ((int) $diagnosis['patientId'] !== $oldPatientId) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "diagnosisId tidak sesuai dengan patientId yang terdaftar pada Episode of Care", "code" => 422], "metadata" => []]);
        exit;
    }
    $diagnosisSatuSehat = $diagnosis['idCondition'];
} catch (PDOException $e) {
    error_log('[UpdateEpisodeOfCare] Check diagnosisId error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    exit;
}

// --- 14. Validasi medicalPersonelId ---
$practitionerSatuSehat = null;
try {
    $stmt = $Conn->prepare("SELECT id_practitioner FROM medical_personel WHERE medicalPersonelId = :id AND status = 1 LIMIT 1");
    $stmt->execute([':id' => $medicalPersonelId]);
    $mp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mp) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "medicalPersonelId tidak ditemukan atau tidak aktif", "code" => 422], "metadata" => []]);
        exit;
    }
    $practitionerSatuSehat = $mp['id_practitioner'];
} catch (PDOException $e) {
    error_log('[UpdateEpisodeOfCare] Check medicalPersonelId error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    exit;
}

// --- 15. Validasi episodeOfCareReferenceId (jika diberikan) ---
$typeSystem = $oldTypeSystem;
$typeCode = $oldTypeCode;
$typeDisplay = $oldTypeDisplay;
if ($episodeOfCareReferenceId > 0) {
    try {
        $stmt = $Conn->prepare("SELECT * FROM episode_of_care_reference WHERE episodeOfCareReferenceId = :id LIMIT 1");
        $stmt->execute([':id' => $episodeOfCareReferenceId]);
        $ref = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ref) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "episodeOfCareReferenceId tidak ditemukan", "code" => 422], "metadata" => []]);
            exit;
        }
        $typeSystem = $ref['episodeOfCareTypeSystem'];
        $typeCode = $ref['episodeOfCareTypeCode'];
        $typeDisplay = $ref['episodeOfCareTypeDisplay'];
    } catch (PDOException $e) {
        error_log('[UpdateEpisodeOfCare] Check episodeOfCareReferenceId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }
}

// --- 16. Validasi episodeOfCareStatus ---
$allowedStatus = ['planned', 'waitlist', 'active', 'onhold', 'finished', 'cancelled', 'entered-in-error'];
if (!in_array($episodeOfCareStatus, $allowedStatus, true)) {
    http_response_code(422);
    echo json_encode([
        "response" => [
            "message" => "episodeOfCareStatus harus salah satu dari: " . implode(', ', $allowedStatus),
            "code" => 422
        ],
        "metadata" => []
    ]);
    exit;
}

// --- 17. Validasi tanggal ---
$startDate = DateTime::createFromFormat('Y-m-d', $episodeOfCareStart);
if (!$startDate || $startDate->format('Y-m-d') !== $episodeOfCareStart) {
    http_response_code(422);
    echo json_encode(["response" => ["message" => "episodeOfCareStart harus format YYYY-MM-DD", "code" => 422], "metadata" => []]);
    exit;
}
if (!empty($episodeOfCareEnd)) {
    $endDate = DateTime::createFromFormat('Y-m-d', $episodeOfCareEnd);
    if (!$endDate || $endDate->format('Y-m-d') !== $episodeOfCareEnd) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "episodeOfCareEnd harus format YYYY-MM-DD", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($startDate > $endDate) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "episodeOfCareStart tidak boleh lebih dari episodeOfCareEnd", "code" => 422], "metadata" => []]);
        exit;
    }
} else {
    $episodeOfCareEnd = null;
}

// --- 18. Validasi encounterId array ---
$validEncounterIds = [];
foreach ($encounterId as $encId) {
    $encId = (int) $encId;
    if ($encId <= 0) continue;
    try {
        $stmt = $Conn->prepare("SELECT encounterId, patientId FROM encounter WHERE encounterId = :id LIMIT 1");
        $stmt->execute([':id' => $encId]);
        $enc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$enc) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "encounterId $encId tidak ditemukan", "code" => 422], "metadata" => []]);
            exit;
        }
        if ((int) $enc['patientId'] !== $oldPatientId) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "encounterId $encId tidak sesuai dengan patientId yang terdaftar pada Episode of Care", "code" => 422], "metadata" => []]);
            exit;
        }
        $validEncounterIds[] = $encId;
    } catch (PDOException $e) {
        error_log('[UpdateEpisodeOfCare] Check encounterId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }
}
if (count($validEncounterIds) == 0) {
    http_response_code(422);
    echo json_encode(["response" => ["message" => "Tidak ada encounterId yang valid", "code" => 422], "metadata" => []]);
    exit;
}

// --- 19. Update episode_of_care ---
try {
    $sql = "UPDATE episode_of_care SET
                diagnosisId = :diagnosisId,
                medicalPersonelId = :medicalPersonelId,
                episodeOfCareTypeSystem = :episodeOfCareTypeSystem,
                episodeOfCareTypeCode = :episodeOfCareTypeCode,
                episodeOfCareTypeDisplay = :episodeOfCareTypeDisplay,
                episodeOfCareStatus = :episodeOfCareStatus,
                episodeOfCareStart = :episodeOfCareStart,
                episodeOfCareEnd = :episodeOfCareEnd,
                updateAt = :updateAt,
                updateBy = :updateBy
            WHERE episodeOfCareId = :id";
    $stmt = $Conn->prepare($sql);
    $stmt->execute([
        ':diagnosisId' => $diagnosisId,
        ':medicalPersonelId' => $medicalPersonelId,
        ':episodeOfCareTypeSystem' => $typeSystem,
        ':episodeOfCareTypeCode' => $typeCode,
        ':episodeOfCareTypeDisplay' => $typeDisplay,
        ':episodeOfCareStatus' => $episodeOfCareStatus,
        ':episodeOfCareStart' => $episodeOfCareStart,
        ':episodeOfCareEnd' => $episodeOfCareEnd,
        ':updateAt' => $nowUtc,
        ':updateBy' => $loggedInAccountId,
        ':id' => $episodeOfCareId
    ]);
} catch (PDOException $e) {
    error_log('[UpdateEpisodeOfCare] Update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Gagal memperbarui Episode of Care: " . $e->getMessage(), "code" => 500], "metadata" => []]);
    exit;
}

// --- 20. Update episode_of_care_details ---
try {
    $stmt = $Conn->prepare("DELETE FROM episode_of_care_details WHERE episodeOfCareId = :id");
    $stmt->execute([':id' => $episodeOfCareId]);

    $sql = "INSERT INTO episode_of_care_details (episodeOfCareId, encounterId, patientId) VALUES (:episodeOfCareId, :encounterId, :patientId)";
    $stmt = $Conn->prepare($sql);
    foreach ($validEncounterIds as $encId) {
        $stmt->execute([
            ':episodeOfCareId' => $episodeOfCareId,
            ':encounterId' => $encId,
            ':patientId' => $oldPatientId
        ]);
    }
} catch (PDOException $e) {
    error_log('[UpdateEpisodeOfCare] Update details error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Gagal memperbarui detail kunjungan: " . $e->getMessage(), "code" => 500], "metadata" => []]);
    exit;
}

// --- 21. Sinkronisasi ke SATUSEHAT ---
$satusehatSyncStatus = 'skipped';
$satusehatMessage = 'Sinkronisasi SATUSEHAT tidak dilakukan';
$satuSehatCode = $oldSatuSehatCode;
$satusehatErrorDetail = null;

if (!empty($oldSatuSehatCode) && !empty($existing['patientSatuSehat']) && !empty($diagnosisSatuSehat) && !empty($practitionerSatuSehat)) {
    try {
        $credStmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
        $credStmt->execute();
        $credential = $credStmt->fetch(PDO::FETCH_ASSOC);
        if ($credential) {
            $tokenResult = generateTokenSatusehat($Conn);
            if ($tokenResult['status'] === 'success') {
                $accessToken = $tokenResult['token'];
                $baseUrl = rtrim($credential['baseUrl'], '/');

                $startDateTime = $episodeOfCareStart . 'T00:00:00Z';
                $endDateTime = !empty($episodeOfCareEnd) ? $episodeOfCareEnd . 'T23:59:59Z' : null;

                // Payload update (termasuk type jika tersedia)
                $payload = [
                    'resourceType' => 'EpisodeOfCare',
                    'id' => $oldSatuSehatCode,
                    'status' => $episodeOfCareStatus,
                    'patient' => [
                        'reference' => 'Patient/' . $existing['patientSatuSehat']
                    ],
                    'managingOrganization' => [
                        'reference' => 'Organization/' . $credential['organizationId']
                    ],
                    'period' => [
                        'start' => $startDateTime
                    ],
                    'diagnosis' => [
                        [
                            'condition' => [
                                'reference' => 'Condition/' . $diagnosisSatuSehat
                            ],
                            'role' => [
                                'coding' => [
                                    [
                                        'system' => 'http://terminology.hl7.org/CodeSystem/diagnosis-role',
                                        'code' => 'CC'
                                    ]
                                ]
                            ],
                            'rank' => 1
                        ]
                    ]
                ];

                // Tambahkan field 'type' jika data referensi tersedia
                if (!empty($typeCode) && !empty($typeDisplay) && !empty($typeSystem)) {
                    $payload['type'] = [
                        [
                            'coding' => [
                                [
                                    'system' => $typeSystem,
                                    'code' => $typeCode,
                                    'display' => $typeDisplay
                                ]
                            ]
                        ]
                    ];
                }

                if ($endDateTime) {
                    $payload['period']['end'] = $endDateTime;
                }

                $satusehatSyncStatus = 'failed';
                $satusehatMessage = 'Gagal mengupdate ke SATUSEHAT';

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $baseUrl . '/fhir-r4/v1/EpisodeOfCare/' . $oldSatuSehatCode,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $accessToken
                    ],
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($response === false) {
                    $satusehatMessage = 'Curl error: ' . $curlError;
                    $satusehatErrorDetail = $curlError;
                } elseif ($httpCode === 200) {
                    $satusehatSyncStatus = 'success';
                    $satusehatMessage = 'Berhasil mengupdate Episode of Care di SATUSEHAT';
                } else {
                    // Ambil detail error
                    $result = json_decode($response, true);
                    if ($result && isset($result['issue']) && is_array($result['issue'])) {
                        $errors = [];
                        foreach ($result['issue'] as $issue) {
                            $severity = isset($issue['severity']) ? $issue['severity'] : 'error';
                            $code = isset($issue['code']) ? $issue['code'] : '';
                            $details = isset($issue['details']['text']) ? $issue['details']['text'] : '';
                            $diagnostics = isset($issue['diagnostics']) ? $issue['diagnostics'] : '';
                            $expression = isset($issue['expression']) ? implode(', ', $issue['expression']) : '';
                            $errorMsg = '';
                            if ($details) $errorMsg .= $details;
                            if ($diagnostics) $errorMsg .= ($errorMsg ? ' - ' : '') . $diagnostics;
                            if ($expression) $errorMsg .= ($errorMsg ? ' (Field: ' : 'Field: ') . $expression . ')';
                            if (!$errorMsg) $errorMsg = $code . ' (Severity: ' . $severity . ')';
                            $errors[] = $errorMsg;
                        }
                        $satusehatErrorDetail = implode('; ', $errors);
                        $satusehatMessage = 'HTTP ' . $httpCode . ' - ' . $satusehatErrorDetail;
                    } else {
                        $satusehatErrorDetail = substr($response, 0, 500);
                        $satusehatMessage = 'HTTP ' . $httpCode . ' - ' . $satusehatErrorDetail;
                    }
                }
            } else {
                $satusehatMessage = 'Token SATUSEHAT error: ' . $tokenResult['message'];
                $satusehatErrorDetail = $tokenResult['message'];
            }
        } else {
            $satusehatMessage = 'Tidak ada kredensial SATUSEHAT aktif';
            $satusehatErrorDetail = 'Tidak ada kredensial SATUSEHAT aktif';
        }
    } catch (Exception $e) {
        $satusehatMessage = 'Exception: ' . $e->getMessage();
        $satusehatErrorDetail = $e->getMessage();
        error_log('[UpdateEpisodeOfCare] SATUSEHAT integration error: ' . $e->getMessage());
        $satusehatSyncStatus = 'failed';
    }
} else {
    $satusehatMessage = 'Syarat sinkronisasi tidak terpenuhi (patient, diagnosis, atau practitioner tidak memiliki kode SATUSEHAT)';
    $satusehatSyncStatus = 'skipped';
}

// --- 22. Ambil data terbaru untuk response ---
try {
    $stmt = $Conn->prepare("
        SELECT eoc.*,
               p.name AS patientName,
               d.icdCode,
               d.icdDescription,
               mp.name AS medicalPersonelName,
               ca.name AS createdName,
               ua.name AS updatedName,
               GROUP_CONCAT(eod.encounterId) AS encounterIds
        FROM episode_of_care eoc
        LEFT JOIN patient p ON eoc.patientId = p.patientId
        LEFT JOIN diagnosis d ON eoc.diagnosisId = d.diagnosisId
        LEFT JOIN medical_personel mp ON eoc.medicalPersonelId = mp.medicalPersonelId
        LEFT JOIN account ca ON eoc.creatBy = ca.accountId
        LEFT JOIN account ua ON eoc.updateBy = ua.accountId
        LEFT JOIN episode_of_care_details eod ON eoc.episodeOfCareId = eod.episodeOfCareId
        WHERE eoc.episodeOfCareId = :id
        GROUP BY eoc.episodeOfCareId
    ");
    $stmt->execute([':id' => $episodeOfCareId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($data) {
        $data['episodeOfCareId'] = (int) $data['episodeOfCareId'];
        $data['patientId'] = (int) $data['patientId'];
        $data['diagnosisId'] = (int) $data['diagnosisId'];
        $data['medicalPersonelId'] = (int) $data['medicalPersonelId'];
        $data['creatBy'] = $data['creatBy'] !== null ? (int) $data['creatBy'] : null;
        $data['updateBy'] = $data['updateBy'] !== null ? (int) $data['updateBy'] : null;
        if (!empty($data['encounterIds'])) {
            $data['encounterIds'] = array_map('intval', explode(',', $data['encounterIds']));
        } else {
            $data['encounterIds'] = [];
        }
        if ($data['patientName'] === null) unset($data['patientName']);
        if ($data['icdCode'] === null) unset($data['icdCode']);
        if ($data['icdDescription'] === null) unset($data['icdDescription']);
        if ($data['medicalPersonelName'] === null) unset($data['medicalPersonelName']);
        if ($data['createdName'] === null) unset($data['createdName']);
        if ($data['updatedName'] === null) unset($data['updatedName']);
    }
} catch (PDOException $e) {
    error_log('[UpdateEpisodeOfCare] Fetch updated data error: ' . $e->getMessage());
}

// --- 23. Response Sukses ---
http_response_code(200);
echo json_encode([
    "response" => [
        "message" => "Episode of Care berhasil diperbarui",
        "code" => 200
    ],
    "metadata" => [
        "episodeOfCareId" => $episodeOfCareId,
        "satuSehatCode" => $oldSatuSehatCode,
        "satusehat_sync" => [
            "status" => $satusehatSyncStatus,
            "message" => $satusehatMessage,
            "error_detail" => $satusehatErrorDetail
        ],
        "updated_at" => $nowUtc . ' GMT'
    ],
    "data" => $data
]);
?>