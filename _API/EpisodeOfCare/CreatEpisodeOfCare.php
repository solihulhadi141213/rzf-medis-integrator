<?php
/**
 * Create Episode Of Care
 * Endpoint: POST /_API/EpisodeOfCare/CreateEpisodeOfCare.php
 * Header: token, account_token
 * Body: JSON {
 *     "patientId": 22,
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
 * - Validasi patientId, diagnosisId, medicalPersonelId, episodeOfCareReferenceId.
 * - Validasi encounterId array dan setiap ID valid.
 * - Generate episodeOfCareCode (random 34 karakter).
 * - Insert ke episode_of_care dan episode_of_care_details.
 * - Sinkronisasi ke SATUSEHAT jika syarat terpenuhi.
 * - Format tanggal period menggunakan ISO8601 UTC.
 */

// --- 1. Response Header ---
header('Content-Type: application/json');
header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
header("Cache-Control: no-store, no-cache, must-revalidate");
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: false');
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, token, account_token");

date_default_timezone_set('UTC');

// --- 2. Include Dependencies ---
include "../../_Config/Connection.php";
include "../../_Config/Helper.php";
require "../../_Config/RateLimiter.php";

// --- 3. Rate Limiter ---
$Limiter = new RateLimiter($Conn);
$Limiter->check("create_episode_of_care", 5, 60);

// --- 4. Validasi Method ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

// --- 6. Validasi Token & Permission ---
$nowUtc = gmdate('Y-m-d H:i:s');
try {
    // API Token
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

    // Account Token
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

    // Permission
    $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_episode_of_care' LIMIT 1");
    $stmt->execute();
    $feature = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
        http_response_code(403);
        echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah Episode of Care", "code" => 403], "metadata" => []]);
        exit;
    }
} catch (PDOException $e) {
    error_log('[CreateEpisodeOfCare] Auth error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    exit;
}

// --- 7. Parse JSON Body ---
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["response" => ["message" => "Invalid JSON payload", "code" => 400], "metadata" => []]);
    exit;
}

// --- 8. Ambil nilai dari body ---
$patientId = isset($input['patientId']) ? (int) $input['patientId'] : 0;
$diagnosisId = isset($input['diagnosisId']) ? (int) $input['diagnosisId'] : 0;
$medicalPersonelId = isset($input['medicalPersonelId']) ? (int) $input['medicalPersonelId'] : 0;
$episodeOfCareReferenceId = isset($input['episodeOfCareReferenceId']) ? (int) $input['episodeOfCareReferenceId'] : 0;
$episodeOfCareStatus = isset($input['episodeOfCareStatus']) ? trim($input['episodeOfCareStatus']) : '';
$episodeOfCareStart = isset($input['episodeOfCareStart']) ? trim($input['episodeOfCareStart']) : '';
$episodeOfCareEnd = isset($input['episodeOfCareEnd']) ? trim($input['episodeOfCareEnd']) : null;
$encounterId = isset($input['encounterId']) ? $input['encounterId'] : [];

// --- 9. Validasi Field Wajib ---
$required = ['patientId', 'diagnosisId', 'medicalPersonelId', 'episodeOfCareReferenceId', 'episodeOfCareStatus', 'episodeOfCareStart', 'encounterId'];
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
if ($patientId <= 0 || $diagnosisId <= 0 || $medicalPersonelId <= 0 || $episodeOfCareReferenceId <= 0) {
    http_response_code(422);
    echo json_encode(["response" => ["message" => "Semua ID harus bernilai positif", "code" => 422], "metadata" => []]);
    exit;
}

// --- 10. Validasi patientId ---
$patientSatuSehat = null;
try {
    $stmt = $Conn->prepare("SELECT patientId, satuSehatCode FROM patient WHERE patientId = :id LIMIT 1");
    $stmt->execute([':id' => $patientId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "patientId tidak ditemukan", "code" => 422], "metadata" => []]);
        exit;
    }
    $patientSatuSehat = $patient['satuSehatCode'];
} catch (PDOException $e) {
    error_log('[CreateEpisodeOfCare] Check patientId error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    exit;
}

// --- 11. Validasi diagnosisId ---
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
    if ((int) $diagnosis['patientId'] !== $patientId) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "diagnosisId tidak sesuai dengan patientId yang diberikan", "code" => 422], "metadata" => []]);
        exit;
    }
    $diagnosisSatuSehat = $diagnosis['idCondition'];
} catch (PDOException $e) {
    error_log('[CreateEpisodeOfCare] Check diagnosisId error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    exit;
}

// --- 12. Validasi medicalPersonelId ---
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
    error_log('[CreateEpisodeOfCare] Check medicalPersonelId error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    exit;
}

// --- 13. Validasi episodeOfCareReferenceId ---
$typeCode = $typeDisplay = $typeSystem = null;
try {
    $stmt = $Conn->prepare("SELECT * FROM episode_of_care_reference WHERE episodeOfCareReferenceId = :id LIMIT 1");
    $stmt->execute([':id' => $episodeOfCareReferenceId]);
    $ref = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ref) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "episodeOfCareReferenceId tidak ditemukan", "code" => 422], "metadata" => []]);
        exit;
    }
    $typeCode = $ref['episodeOfCareTypeCode'];
    $typeDisplay = $ref['episodeOfCareTypeDisplay'];
    $typeSystem = $ref['episodeOfCareTypeSystem'];
} catch (PDOException $e) {
    error_log('[CreateEpisodeOfCare] Check episodeOfCareReferenceId error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    exit;
}

// --- 14. Validasi episodeOfCareStatus ---
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

// --- 15. Validasi tanggal ---
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

// --- 16. Validasi encounterId array ---
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
        if ((int) $enc['patientId'] !== $patientId) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "encounterId $encId tidak sesuai dengan patientId yang diberikan", "code" => 422], "metadata" => []]);
            exit;
        }
        $validEncounterIds[] = $encId;
    } catch (PDOException $e) {
        error_log('[CreateEpisodeOfCare] Check encounterId error: ' . $e->getMessage());
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

// --- 17. Generate episodeOfCareCode ---
$episodeOfCareCode = GenerateToken(34);

// --- 18. Insert ke episode_of_care ---
$createdDate = $nowUtc;
$episodeOfCareId = null;
try {
    $sql = "INSERT INTO episode_of_care (
                episodeOfCareCode,
                satuSehatCode,
                patientId,
                diagnosisId,
                medicalPersonelId,
                episodeOfCareTypeSystem,
                episodeOfCareTypeCode,
                episodeOfCareTypeDisplay,
                episodeOfCareStatus,
                episodeOfCareStart,
                episodeOfCareEnd,
                creatAt,
                updateAt,
                creatBy,
                updateBy
            ) VALUES (
                :episodeOfCareCode,
                :satuSehatCode,
                :patientId,
                :diagnosisId,
                :medicalPersonelId,
                :episodeOfCareTypeSystem,
                :episodeOfCareTypeCode,
                :episodeOfCareTypeDisplay,
                :episodeOfCareStatus,
                :episodeOfCareStart,
                :episodeOfCareEnd,
                :creatAt,
                :updateAt,
                :creatBy,
                :updateBy
            )";
    $stmt = $Conn->prepare($sql);
    $stmt->execute([
        ':episodeOfCareCode' => $episodeOfCareCode,
        ':satuSehatCode' => null,
        ':patientId' => $patientId,
        ':diagnosisId' => $diagnosisId,
        ':medicalPersonelId' => $medicalPersonelId,
        ':episodeOfCareTypeSystem' => $typeSystem,
        ':episodeOfCareTypeCode' => $typeCode,
        ':episodeOfCareTypeDisplay' => $typeDisplay,
        ':episodeOfCareStatus' => $episodeOfCareStatus,
        ':episodeOfCareStart' => $episodeOfCareStart,
        ':episodeOfCareEnd' => $episodeOfCareEnd,
        ':creatAt' => $createdDate,
        ':updateAt' => $createdDate,
        ':creatBy' => $loggedInAccountId,
        ':updateBy' => $loggedInAccountId
    ]);
    $episodeOfCareId = (int) $Conn->lastInsertId();
} catch (PDOException $e) {
    error_log('[CreateEpisodeOfCare] Insert episode_of_care error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Gagal menyimpan data Episode of Care: " . $e->getMessage(), "code" => 500], "metadata" => []]);
    exit;
}

// --- 19. Insert ke episode_of_care_details untuk setiap encounterId ---
try {
    $sql = "INSERT INTO episode_of_care_details (episodeOfCareId, encounterId, patientId) VALUES (:episodeOfCareId, :encounterId, :patientId)";
    $stmt = $Conn->prepare($sql);
    foreach ($validEncounterIds as $encId) {
        $stmt->execute([
            ':episodeOfCareId' => $episodeOfCareId,
            ':encounterId' => $encId,
            ':patientId' => $patientId
        ]);
    }
} catch (PDOException $e) {
    error_log('[CreateEpisodeOfCare] Insert episode_of_care_details error: ' . $e->getMessage());
    // Rollback: hapus episode_of_care yang sudah diinsert
    $Conn->prepare("DELETE FROM episode_of_care WHERE episodeOfCareId = :id")->execute([':id' => $episodeOfCareId]);
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Gagal menyimpan detail kunjungan: " . $e->getMessage(), "code" => 500], "metadata" => []]);
    exit;
}

// --- 20. Sinkronisasi ke SATUSEHAT ---
$satusehatSyncStatus = 'skipped';
$satusehatMessage = 'Sinkronisasi SATUSEHAT tidak dilakukan';
$satuSehatCode = null;

$canSync = !empty($patientSatuSehat) && !empty($diagnosisSatuSehat) && !empty($practitionerSatuSehat);
if ($canSync) {
    try {
        $credStmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
        $credStmt->execute();
        $credential = $credStmt->fetch(PDO::FETCH_ASSOC);
        if ($credential) {
            $tokenResult = generateTokenSatusehat($Conn);
            if ($tokenResult['status'] === 'success') {
                $accessToken = $tokenResult['token'];
                $baseUrl = rtrim($credential['baseUrl'], '/');

                // Format tanggal ke ISO8601 UTC
                $startDateTime = $episodeOfCareStart . 'T00:00:00Z';
                $endDateTime = !empty($episodeOfCareEnd) ? $episodeOfCareEnd . 'T23:59:59Z' : null;

                // Payload EpisodeOfCare (tanpa field 'type')
                $payload = [
                    'resourceType' => 'EpisodeOfCare',
                    'status' => $episodeOfCareStatus,
                    'patient' => [
                        'reference' => 'Patient/' . $patientSatuSehat
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
                if ($endDateTime) {
                    $payload['period']['end'] = $endDateTime;
                }

                $satusehatSyncStatus = 'failed';
                $satusehatMessage = 'Gagal mengirim ke SATUSEHAT';

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $baseUrl . '/fhir-r4/v1/EpisodeOfCare',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
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
                } elseif ($httpCode === 201 || $httpCode === 200) {
                    $result = json_decode($response, true);
                    if (isset($result['id'])) {
                        $satuSehatCode = $result['id'];
                        $updStmt = $Conn->prepare("UPDATE episode_of_care SET satuSehatCode = :code WHERE episodeOfCareId = :id");
                        $updStmt->execute([':code' => $satuSehatCode, ':id' => $episodeOfCareId]);
                        $satusehatSyncStatus = 'success';
                        $satusehatMessage = 'Berhasil disinkronkan ke SATUSEHAT';
                    } else {
                        $satusehatMessage = 'Respons SATUSEHAT tidak mengandung ID';
                    }
                } else {
                    $satusehatMessage = 'HTTP ' . $httpCode . ' - ' . substr($response, 0, 200);
                }
            } else {
                $satusehatMessage = 'Token SATUSEHAT error: ' . $tokenResult['message'];
            }
        } else {
            $satusehatMessage = 'Tidak ada kredensial SATUSEHAT aktif';
        }
    } catch (Exception $e) {
        $satusehatMessage = 'Exception: ' . $e->getMessage();
        error_log('[CreateEpisodeOfCare] SATUSEHAT integration error: ' . $e->getMessage());
        $satusehatSyncStatus = 'failed';
    }
} else {
    $satusehatMessage = 'Syarat sinkronisasi tidak terpenuhi (patient, diagnosis, atau practitioner tidak memiliki kode SATUSEHAT)';
    $satusehatSyncStatus = 'skipped';
}

// --- 21. Ambil data yang baru dibuat untuk response ---
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
    error_log('[CreateEpisodeOfCare] Fetch response data error: ' . $e->getMessage());
}

// --- 22. Response Sukses ---
http_response_code(201);
echo json_encode([
    "response" => [
        "message" => "Episode of Care berhasil ditambahkan",
        "code" => 201
    ],
    "metadata" => [
        "episodeOfCareId" => $episodeOfCareId,
        "satuSehatCode" => $satuSehatCode,
        "satusehat_sync" => [
            "status" => $satusehatSyncStatus,
            "message" => $satusehatMessage
        ],
        "created_at" => $createdDate . ' GMT'
    ],
    "data" => $data
]);
?>