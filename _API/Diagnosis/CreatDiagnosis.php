<?php
/**
 * Create Diagnosis
 * Endpoint: POST /_API/Diagnosis/CreateDiagnosis.php
 * Header: token, account_token
 * Body: JSON {
 *     "encounterId": 20,
 *     "medicalPersonelId": 5,
 *     "category": "Admission",
 *     "id_icd": 18,
 *     "diagnosisText": "Sakit kepala disertai batuk dan pilek",
 *     "caseStatus": "Lama",
 *     "certaintyStatus": "Final"
 * }
 *
 * - Validasi mandatory.
 * - Validasi encounterId, medicalPersonelId, id_icd ada di database.
 * - Jika encounter.satuSehatCode dan practitioner.id_practitioner terisi, kirim ke SATUSEHAT.
 * - Format tanggal dikirim dalam ISO8601 UTC (Y-m-d\TH:i:s\Z).
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
$Limiter->check("create_diagnosis", 5, 60);

// --- 4. Validasi Method ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "response" => ["message" => "Metode request tidak diizinkan", "code" => 405],
        "metadata" => []
    ]);
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
    $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_diagnosis' LIMIT 1");
    $stmt->execute();
    $feature = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
        http_response_code(403);
        echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah diagnosis", "code" => 403], "metadata" => []]);
        exit;
    }
} catch (PDOException $e) {
    error_log('[CreateDiagnosis] Auth error: ' . $e->getMessage());
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
$encounterId = isset($input['encounterId']) ? (int) $input['encounterId'] : 0;
$medicalPersonelId = isset($input['medicalPersonelId']) ? (int) $input['medicalPersonelId'] : 0;
$category = isset($input['category']) ? trim($input['category']) : '';
$id_icd = isset($input['id_icd']) ? (int) $input['id_icd'] : 0;
$diagnosisText = isset($input['diagnosisText']) ? trim($input['diagnosisText']) : null;
$caseStatus = isset($input['caseStatus']) ? trim($input['caseStatus']) : '';
$certaintyStatus = isset($input['certaintyStatus']) ? trim($input['certaintyStatus']) : '';

// --- 9. Validasi Field Wajib ---
if ($encounterId <= 0) {
    http_response_code(422);
    echo json_encode(["response" => ["message" => "encounterId wajib diisi dengan angka positif", "code" => 422], "metadata" => []]);
    exit;
}
if ($medicalPersonelId <= 0) {
    http_response_code(422);
    echo json_encode(["response" => ["message" => "medicalPersonelId wajib diisi dengan angka positif", "code" => 422], "metadata" => []]);
    exit;
}
if (empty($category)) {
    http_response_code(422);
    echo json_encode(["response" => ["message" => "category wajib diisi", "code" => 422], "metadata" => []]);
    exit;
}
if ($id_icd <= 0) {
    http_response_code(422);
    echo json_encode(["response" => ["message" => "id_icd wajib diisi dengan angka positif", "code" => 422], "metadata" => []]);
    exit;
}
if (empty($caseStatus)) {
    http_response_code(422);
    echo json_encode(["response" => ["message" => "caseStatus wajib diisi", "code" => 422], "metadata" => []]);
    exit;
}
if (empty($certaintyStatus)) {
    http_response_code(422);
    echo json_encode(["response" => ["message" => "certaintyStatus wajib diisi", "code" => 422], "metadata" => []]);
    exit;
}

// --- 10. Validasi encounterId dan ambil patientId & satuSehatCode ---
$patientId = null;
$encounterSatuSehat = null;
try {
    $stmt = $Conn->prepare("SELECT patientId, satuSehatCode FROM encounter WHERE encounterId = :id LIMIT 1");
    $stmt->execute([':id' => $encounterId]);
    $encounter = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$encounter) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "encounterId tidak ditemukan", "code" => 422], "metadata" => []]);
        exit;
    }
    $patientId = $encounter['patientId'];
    $encounterSatuSehat = $encounter['satuSehatCode'];
} catch (PDOException $e) {
    error_log('[CreateDiagnosis] Check encounterId error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    exit;
}

// --- 11. Validasi medicalPersonelId dan ambil id_practitioner ---
$practitionerSatuSehat = null;
try {
    $stmt = $Conn->prepare("SELECT medicalPersonelId, id_practitioner FROM medical_personel WHERE medicalPersonelId = :id LIMIT 1");
    $stmt->execute([':id' => $medicalPersonelId]);
    $mp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mp) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "medicalPersonelId tidak ditemukan", "code" => 422], "metadata" => []]);
        exit;
    }
    $practitionerSatuSehat = $mp['id_practitioner'];
} catch (PDOException $e) {
    error_log('[CreateDiagnosis] Check medicalPersonelId error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    exit;
}

// --- 12. Validasi id_icd dan ambil data ICD ---
$icdVersion = null;
$icdCode = null;
$icdDescription = null;
try {
    $stmt = $Conn->prepare("SELECT icd, kode, long_des FROM icd WHERE id_icd = :id LIMIT 1");
    $stmt->execute([':id' => $id_icd]);
    $icd = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$icd) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "id_icd tidak ditemukan", "code" => 422], "metadata" => []]);
        exit;
    }
    $icdVersion = $icd['icd'];
    $icdCode = $icd['kode'];
    $icdDescription = $icd['long_des'];
} catch (PDOException $e) {
    error_log('[CreateDiagnosis] Check id_icd error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    exit;
}

// --- 13. Validasi enum fields ---
$allowedCategory = ['Admission','Provisional','Primary','Secondary','Working','Differential','Final'];
$allowedCaseStatus = ['Baru','Lama','Kambuh','Kronis'];
$allowedCertaintyStatus = ['Provisional','Final'];

if (!in_array($category, $allowedCategory, true)) {
    http_response_code(422);
    echo json_encode([
        "response" => [
            "message" => "category harus salah satu dari: " . implode(', ', $allowedCategory),
            "code" => 422
        ],
        "metadata" => []
    ]);
    exit;
}
if (!in_array($caseStatus, $allowedCaseStatus, true)) {
    http_response_code(422);
    echo json_encode([
        "response" => [
            "message" => "caseStatus harus salah satu dari: " . implode(', ', $allowedCaseStatus),
            "code" => 422
        ],
        "metadata" => []
    ]);
    exit;
}
if (!in_array($certaintyStatus, $allowedCertaintyStatus, true)) {
    http_response_code(422);
    echo json_encode([
        "response" => [
            "message" => "certaintyStatus harus salah satu dari: " . implode(', ', $allowedCertaintyStatus),
            "code" => 422
        ],
        "metadata" => []
    ]);
    exit;
}

// --- 14. Validasi panjang field (opsional) ---
if ($diagnosisText !== null && strlen($diagnosisText) > 65535) {
    http_response_code(422);
    echo json_encode(["response" => ["message" => "diagnosisText terlalu panjang", "code" => 422], "metadata" => []]);
    exit;
}

// --- 15. Ambil patient satuSehatCode untuk syarat SATUSEHAT ---
$patientSatuSehat = null;
try {
    $stmt = $Conn->prepare("SELECT satuSehatCode FROM patient WHERE patientId = :id LIMIT 1");
    $stmt->execute([':id' => $patientId]);
    $pRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($pRow) {
        $patientSatuSehat = $pRow['satuSehatCode'];
    }
} catch (PDOException $e) {
    error_log('[CreateDiagnosis] Get patient satuSehatCode error: ' . $e->getMessage());
}

// --- 16. Cek syarat SATUSEHAT ---
$satusehatRequirements = [
    'encounter_satusehat' => !empty($encounterSatuSehat),
    'patient_satusehat' => !empty($patientSatuSehat),
    'practitioner_satusehat' => !empty($practitionerSatuSehat),
    'icd_code' => !empty($icdCode)
];

$allRequirementsMet = $satusehatRequirements['encounter_satusehat'] && 
                      $satusehatRequirements['patient_satusehat'] && 
                      $satusehatRequirements['practitioner_satusehat'] && 
                      $satusehatRequirements['icd_code'];

// --- 17. Insert Data ---
$diagnosisId = null;
$idCondition = null;
$createdDate = $nowUtc;

try {
    $sql = "INSERT INTO diagnosis (
                encounterId,
                patientId,
                idCondition,
                medicalPersonelId,
                category,
                icdVersion,
                icdCode,
                icdDescription,
                diagnosisText,
                caseStatus,
                certaintyStatus,
                creatAt,
                updateAt,
                creatBy,
                updateBy
            ) VALUES (
                :encounterId,
                :patientId,
                :idCondition,
                :medicalPersonelId,
                :category,
                :icdVersion,
                :icdCode,
                :icdDescription,
                :diagnosisText,
                :caseStatus,
                :certaintyStatus,
                :creatAt,
                :updateAt,
                :creatBy,
                :updateBy
            )";

    $stmt = $Conn->prepare($sql);
    $stmt->execute([
        ':encounterId' => $encounterId,
        ':patientId' => $patientId,
        ':idCondition' => null,
        ':medicalPersonelId' => $medicalPersonelId,
        ':category' => $category,
        ':icdVersion' => $icdVersion,
        ':icdCode' => $icdCode,
        ':icdDescription' => $icdDescription,
        ':diagnosisText' => $diagnosisText,
        ':caseStatus' => $caseStatus,
        ':certaintyStatus' => $certaintyStatus,
        ':creatAt' => $createdDate,
        ':updateAt' => $createdDate,
        ':creatBy' => $loggedInAccountId,
        ':updateBy' => $loggedInAccountId
    ]);

    $diagnosisId = (int) $Conn->lastInsertId();

} catch (PDOException $e) {
    error_log('[CreateDiagnosis] Insert error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["response" => ["message" => "Gagal menyimpan data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
    exit;
}

// --- 18. Sinkronisasi ke SATUSEHAT jika semua syarat terpenuhi ---
$satusehatSyncStatus = 'skipped';
$satusehatMessage = 'Sinkronisasi SATUSEHAT dilewati';
$satusehatErrorDetail = null;

if ($allRequirementsMet) {
    $satusehatSyncStatus = 'failed';
    $satusehatMessage = 'Gagal sinkronisasi ke SATUSEHAT';

    try {
        $credStmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
        $credStmt->execute();
        $credential = $credStmt->fetch(PDO::FETCH_ASSOC);
        if ($credential) {
            $tokenResult = generateTokenSatusehat($Conn);
            if ($tokenResult['status'] === 'success') {
                $accessToken = $tokenResult['token'];
                $baseUrl = rtrim($credential['baseUrl'], '/');

                // Mapping clinicalStatus
                $clinicalStatus = ($certaintyStatus === 'Final') ? 'resolved' : 'active';
                $verificationStatus = ($certaintyStatus === 'Final') ? 'confirmed' : 'provisional';

                $categoryMapping = [
                    'Admission' => 'encounter-diagnosis',
                    'Provisional' => 'encounter-diagnosis',
                    'Primary' => 'encounter-diagnosis',
                    'Secondary' => 'encounter-diagnosis',
                    'Working' => 'encounter-diagnosis',
                    'Differential' => 'encounter-diagnosis',
                    'Final' => 'encounter-diagnosis'
                ];
                $categoryCode = isset($categoryMapping[$category]) ? $categoryMapping[$category] : 'encounter-diagnosis';

                // Format tanggal ke ISO8601 UTC
                $recordedDate = gmdate('Y-m-d\TH:i:s\Z', strtotime($createdDate));

                $payload = [
                    'resourceType' => 'Condition',
                    'clinicalStatus' => [
                        'coding' => [
                            [
                                'system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical',
                                'code' => $clinicalStatus
                            ]
                        ]
                    ],
                    'verificationStatus' => [
                        'coding' => [
                            [
                                'system' => 'http://terminology.hl7.org/CodeSystem/condition-ver-status',
                                'code' => $verificationStatus
                            ]
                        ]
                    ],
                    'category' => [
                        [
                            'coding' => [
                                [
                                    'system' => 'http://terminology.hl7.org/CodeSystem/condition-category',
                                    'code' => $categoryCode
                                ]
                            ]
                        ]
                    ],
                    'code' => [
                        'coding' => [
                            [
                                'system' => 'http://hl7.org/fhir/sid/icd-10',
                                'code' => $icdCode,
                                'display' => $icdDescription
                            ]
                        ],
                        'text' => $icdDescription
                    ],
                    'subject' => [
                        'reference' => 'Patient/' . $patientSatuSehat
                    ],
                    'encounter' => [
                        'reference' => 'Encounter/' . $encounterSatuSehat
                    ],
                    'recorder' => [
                        'reference' => 'Practitioner/' . $practitionerSatuSehat
                    ],
                    'recordedDate' => $recordedDate
                ];

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $baseUrl . '/fhir-r4/v1/Condition',
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
                    $satusehatErrorDetail = $curlError;
                } elseif ($httpCode === 201 || $httpCode === 200) {
                    $result = json_decode($response, true);
                    if (isset($result['id'])) {
                        $idCondition = $result['id'];
                        $updStmt = $Conn->prepare("UPDATE diagnosis SET idCondition = :idCondition WHERE diagnosisId = :id");
                        $updStmt->execute([':idCondition' => $idCondition, ':id' => $diagnosisId]);
                        $satusehatSyncStatus = 'success';
                        $satusehatMessage = 'Berhasil disinkronkan ke SATUSEHAT';
                    } else {
                        $satusehatMessage = 'Respons SATUSEHAT tidak mengandung ID';
                        $satusehatErrorDetail = 'ID tidak ditemukan dalam respons';
                    }
                } else {
                    // Parse error response dari SATUSEHAT
                    $result = json_decode($response, true);
                    if ($result && isset($result['issue']) && is_array($result['issue'])) {
                        $errors = [];
                        foreach ($result['issue'] as $issue) {
                            $severity = isset($issue['severity']) ? $issue['severity'] : 'error';
                            $code = isset($issue['code']) ? $issue['code'] : '';
                            $details = isset($issue['details']['text']) ? $issue['details']['text'] : '';
                            $expression = isset($issue['expression']) ? implode(', ', $issue['expression']) : '';
                            $diagnostics = isset($issue['diagnostics']) ? $issue['diagnostics'] : '';
                            
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
                        // Jika bukan JSON atau tidak ada issue, tampilkan response raw
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
        error_log('[CreateDiagnosis] SATUSEHAT integration error: ' . $e->getMessage());
    }
} else {
    // Buat daftar syarat yang tidak terpenuhi
    $unmetRequirements = [];
    foreach ($satusehatRequirements as $key => $met) {
        if (!$met) {
            $labels = [
                'encounter_satusehat' => 'Encounter memiliki satuSehatCode',
                'patient_satusehat' => 'Pasien memiliki satuSehatCode',
                'practitioner_satusehat' => 'Tenaga medis memiliki id_practitioner',
                'icd_code' => 'Kode ICD tersedia'
            ];
            $unmetRequirements[] = $labels[$key] ?? $key;
        }
    }
    $satusehatMessage = 'Syarat SATUSEHAT tidak terpenuhi: ' . implode(', ', $unmetRequirements);
    $satusehatErrorDetail = 'Syarat tidak terpenuhi: ' . implode(', ', $unmetRequirements);
}

// --- 19. Ambil data yang baru dibuat untuk response ---
try {
    $stmt = $Conn->prepare("
        SELECT d.*,
               p.name AS patientName,
               e.EncounterCode,
               mp.name AS medicalPersonelName,
               ca.name AS createdName,
               ua.name AS updatedName
        FROM diagnosis d
        LEFT JOIN patient p ON d.patientId = p.patientId
        LEFT JOIN encounter e ON d.encounterId = e.encounterId
        LEFT JOIN medical_personel mp ON d.medicalPersonelId = mp.medicalPersonelId
        LEFT JOIN account ca ON d.creatBy = ca.accountId
        LEFT JOIN account ua ON d.updateBy = ua.accountId
        WHERE d.diagnosisId = :id LIMIT 1
    ");
    $stmt->execute([':id' => $diagnosisId]);
    $newData = $stmt->fetch(PDO::FETCH_ASSOC);

    // Format response
    if ($newData) {
        $newData['diagnosisId'] = (int) $newData['diagnosisId'];
        $newData['encounterId'] = (int) $newData['encounterId'];
        $newData['patientId'] = (int) $newData['patientId'];
        $newData['medicalPersonelId'] = $newData['medicalPersonelId'] !== null ? (int) $newData['medicalPersonelId'] : null;
        $newData['creatBy'] = $newData['creatBy'] !== null ? (int) $newData['creatBy'] : null;
        $newData['updateBy'] = $newData['updateBy'] !== null ? (int) $newData['updateBy'] : null;
        if ($newData['patientName'] === null) unset($newData['patientName']);
        if ($newData['EncounterCode'] === null) unset($newData['EncounterCode']);
        if ($newData['medicalPersonelName'] === null) unset($newData['medicalPersonelName']);
        if ($newData['createdName'] === null) unset($newData['createdName']);
        if ($newData['updatedName'] === null) unset($newData['updatedName']);
    }
} catch (PDOException $e) {
    error_log('[CreateDiagnosis] Fetch response data error: ' . $e->getMessage());
}

// --- 20. Response Sukses ---
http_response_code(201);
echo json_encode([
    "response" => [
        "message" => "Diagnosis berhasil ditambahkan",
        "code" => 201
    ],
    "metadata" => [
        "diagnosisId" => $diagnosisId,
        "idCondition" => $idCondition,
        "satusehat_sync" => [
            "status" => $satusehatSyncStatus,
            "message" => $satusehatMessage,
            "error_detail" => $satusehatErrorDetail,
            "requirements" => $satusehatRequirements
        ],
        "created_at" => $createdDate . ' GMT'
    ],
    "data" => $newData
]);
?>