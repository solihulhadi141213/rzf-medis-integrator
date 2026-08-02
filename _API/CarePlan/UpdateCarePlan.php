<?php
    /**
     * Update Care Plan
     * Endpoint: PUT /_API/CarePlan/UpdateCarePlan.php?carePlanId={id}
     * Header: token, account_token
     * Body: JSON {
     *     "medicalPersonelId": 5,
     *     "carePlanTitle": "Rencana Perawatan Pasien",
     *     "carePlanStatus": "active",
     *     "carePlanIntent": "plan",
     *     "carePlanCategoryName": "Perencanaan Pasien Rawat Jalan",
     *     "carePlanCategoryCode": "736271009",
     *     "carePlanCategoryDisplay": "Outpatient care plan",
     *     "carePlanCategorySystem": "http://snomed.info/sct",
     *     "carePlaneDescription": "Rencana kontrol 7 hari, edukasi diet rendah garam, dan terapi antihipertensi"
     * }
     *
     * - Validasi mandatory.
     * - Validasi carePlanId ada.
     * - Validasi medicalPersonelId ada.
     * - Validasi carePlanStatus dan carePlanIntent sesuai enum.
     * - Jika sudah memiliki satuSehatCode, update ke SATUSEHAT (PUT).
     * - Jika belum memiliki, cek syarat dan create ke SATUSEHAT (POST).
     * - updateAt dan updateBy diisi otomatis.
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
    $Limiter->check("update_care_plan", 5, 60);

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

    // --- 6. Validasi Parameter carePlanId ---
    if (!isset($_GET['carePlanId']) || !is_numeric($_GET['carePlanId']) || (int)$_GET['carePlanId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter carePlanId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $carePlanId = (int) $_GET['carePlanId'];

    // --- 7. Validasi Token & Permission ---
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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_care_plan' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah Care Plan", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateCarePlan] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data existing ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM care_plan WHERE carePlanId = :id LIMIT 1");
        $stmt->execute([':id' => $carePlanId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Care Plan tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
        $oldSatusehatCode = $existingData['satuSehatCode'];
        $oldPatientId = (int) $existingData['patientId'];
        $oldEncounterId = (int) $existingData['encounterId'];
        $oldMedicalPersonelId = (int) $existingData['medicalPersonelId'];
        $oldCarePlanTitle = $existingData['carePlanTitle'];
        $oldCarePlanStatus = $existingData['carePlanStatus'];
        $oldCarePlanIntent = $existingData['carePlanIntent'];
        $oldCarePlanCategoryName = $existingData['carePlanCategoryName'];
        $oldCarePlanCategoryCode = $existingData['carePlanCategoryCode'];
        $oldCarePlanCategoryDisplay = $existingData['carePlanCategoryDisplay'];
        $oldCarePlanCategorySystem = $existingData['carePlanCategorySystem'];
        $oldCarePlaneDescription = $existingData['carePlaneDescription'];
    } catch (PDOException $e) {
        error_log('[UpdateCarePlan] Fetch existing error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 9. Parse JSON Body ---
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["response" => ["message" => "Invalid JSON payload", "code" => 400], "metadata" => []]);
        exit;
    }

    // --- 10. Ambil nilai dari body, gunakan nilai lama jika tidak ada ---
    $medicalPersonelId = isset($input['medicalPersonelId']) ? (int) $input['medicalPersonelId'] : $oldMedicalPersonelId;
    $carePlanTitle = isset($input['carePlanTitle']) ? trim($input['carePlanTitle']) : $oldCarePlanTitle;
    $carePlanStatus = isset($input['carePlanStatus']) ? trim($input['carePlanStatus']) : $oldCarePlanStatus;
    $carePlanIntent = isset($input['carePlanIntent']) ? trim($input['carePlanIntent']) : $oldCarePlanIntent;
    $carePlanCategoryName = isset($input['carePlanCategoryName']) ? trim($input['carePlanCategoryName']) : $oldCarePlanCategoryName;
    $carePlanCategoryCode = isset($input['carePlanCategoryCode']) ? trim($input['carePlanCategoryCode']) : $oldCarePlanCategoryCode;
    $carePlanCategoryDisplay = isset($input['carePlanCategoryDisplay']) ? trim($input['carePlanCategoryDisplay']) : $oldCarePlanCategoryDisplay;
    $carePlanCategorySystem = isset($input['carePlanCategorySystem']) ? trim($input['carePlanCategorySystem']) : $oldCarePlanCategorySystem;
    $carePlaneDescription = isset($input['carePlaneDescription']) ? trim($input['carePlaneDescription']) : $oldCarePlaneDescription;

    // --- 11. Validasi Field Wajib ---
    $required = ['medicalPersonelId', 'carePlanTitle', 'carePlanStatus', 'carePlanIntent'];
    foreach ($required as $field) {
        if (empty($$field)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Field '$field' wajib diisi", "code" => 422], "metadata" => []]);
            exit;
        }
    }
    if ($medicalPersonelId <= 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "medicalPersonelId harus diisi dengan angka positif", "code" => 422], "metadata" => []]);
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
        error_log('[UpdateCarePlan] Check medicalPersonelId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 13. Validasi carePlanStatus ---
    $allowedStatus = ['draft', 'active', 'on-hold', 'revoked', 'completed', 'entered-in-error', 'unknown'];
    if (!in_array($carePlanStatus, $allowedStatus, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "carePlanStatus harus salah satu dari: " . implode(', ', $allowedStatus),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 14. Validasi carePlanIntent ---
    $allowedIntent = ['proposal', 'plan', 'order', 'option'];
    if (!in_array($carePlanIntent, $allowedIntent, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "carePlanIntent harus salah satu dari: " . implode(', ', $allowedIntent),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 15. Ambil data patient dan encounter untuk satuSehatCode ---
    $patientSatuSehat = null;
    $encounterSatuSehat = null;
    try {
        $stmt = $Conn->prepare("SELECT satuSehatCode FROM patient WHERE patientId = :id LIMIT 1");
        $stmt->execute([':id' => $oldPatientId]);
        $pat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pat) {
            $patientSatuSehat = $pat['satuSehatCode'];
        }
    } catch (PDOException $e) {
        error_log('[UpdateCarePlan] Get patient satuSehatCode error: ' . $e->getMessage());
    }
    try {
        $stmt = $Conn->prepare("SELECT satuSehatCode FROM encounter WHERE encounterId = :id LIMIT 1");
        $stmt->execute([':id' => $oldEncounterId]);
        $enc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($enc) {
            $encounterSatuSehat = $enc['satuSehatCode'];
        }
    } catch (PDOException $e) {
        error_log('[UpdateCarePlan] Get encounter satuSehatCode error: ' . $e->getMessage());
    }

    // --- 16. Update data di database ---
    try {
        $updatedDate = $nowUtc;

        $sql = "UPDATE care_plan SET
                    medicalPersonelId = :medicalPersonelId,
                    carePlanTitle = :carePlanTitle,
                    carePlanStatus = :carePlanStatus,
                    carePlanIntent = :carePlanIntent,
                    carePlanCategoryName = :carePlanCategoryName,
                    carePlanCategoryCode = :carePlanCategoryCode,
                    carePlanCategoryDisplay = :carePlanCategoryDisplay,
                    carePlanCategorySystem = :carePlanCategorySystem,
                    carePlaneDescription = :carePlaneDescription,
                    updateAt = :updateAt,
                    updateBy = :updateBy
                WHERE carePlanId = :id";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':medicalPersonelId' => $medicalPersonelId,
            ':carePlanTitle' => $carePlanTitle,
            ':carePlanStatus' => $carePlanStatus,
            ':carePlanIntent' => $carePlanIntent,
            ':carePlanCategoryName' => $carePlanCategoryName,
            ':carePlanCategoryCode' => $carePlanCategoryCode,
            ':carePlanCategoryDisplay' => $carePlanCategoryDisplay,
            ':carePlanCategorySystem' => $carePlanCategorySystem,
            ':carePlaneDescription' => $carePlaneDescription,
            ':updateAt' => $updatedDate,
            ':updateBy' => $loggedInAccountId,
            ':id' => $carePlanId
        ]);
    } catch (PDOException $e) {
        error_log('[UpdateCarePlan] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 17. Sinkronisasi ke SATUSEHAT ---
    $satusehatSyncStatus = 'skipped';
    $satusehatMessage = 'Sinkronisasi SATUSEHAT tidak dilakukan';
    $satuSehatCode = $oldSatusehatCode;

    // Syarat: patient, encounter, practitioner punya satuSehatCode
    $canSync = !empty($patientSatuSehat) && !empty($encounterSatuSehat) && !empty($practitionerSatuSehat);

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

                    // Siapkan payload
                    $payload = [
                        'resourceType' => 'CarePlan',
                        'title' => $carePlanTitle,
                        'status' => $carePlanStatus,
                        'intent' => $carePlanIntent,
                        'subject' => [
                            'reference' => 'Patient/' . $patientSatuSehat
                        ],
                        'encounter' => [
                            'reference' => 'Encounter/' . $encounterSatuSehat
                        ],
                        'author' => [
                            'reference' => 'Practitioner/' . $practitionerSatuSehat
                        ],
                        'created' => gmdate('Y-m-d\TH:i:s\Z', strtotime($existingData['creatAt']))
                    ];

                    if (!empty($carePlanCategoryCode) && !empty($carePlanCategoryDisplay) && !empty($carePlanCategorySystem)) {
                        $payload['category'] = [
                            [
                                'coding' => [
                                    [
                                        'system' => $carePlanCategorySystem,
                                        'code' => $carePlanCategoryCode,
                                        'display' => $carePlanCategoryDisplay
                                    ]
                                ]
                            ]
                        ];
                    }
                    if (!empty($carePlaneDescription)) {
                        $payload['description'] = $carePlaneDescription;
                    }

                    $satusehatSyncStatus = 'failed';
                    $satusehatMessage = 'Gagal sinkronisasi ke SATUSEHAT';

                    // Tentukan metode: PUT jika sudah ada satuSehatCode, POST jika belum
                    $method = empty($oldSatusehatCode) ? 'POST' : 'PUT';
                    $url = $baseUrl . '/fhir-r4/v1/CarePlan';
                    if ($method === 'PUT') {
                        $url .= '/' . $oldSatusehatCode;
                        $payload['id'] = $oldSatusehatCode;
                    }

                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CUSTOMREQUEST => $method,
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
                    } elseif ($httpCode === 200 || $httpCode === 201) {
                        $result = json_decode($response, true);
                        if (isset($result['id'])) {
                            $satuSehatCode = $result['id'];
                            // Update jika POST dan belum ada satuSehatCode
                            if (empty($oldSatusehatCode)) {
                                $updStmt = $Conn->prepare("UPDATE care_plan SET satuSehatCode = :code WHERE carePlanId = :id");
                                $updStmt->execute([':code' => $satuSehatCode, ':id' => $carePlanId]);
                            }
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
            error_log('[UpdateCarePlan] SATUSEHAT integration error: ' . $e->getMessage());
            $satusehatSyncStatus = 'failed';
        }
    } else {
        $satusehatMessage = 'Syarat sinkronisasi tidak terpenuhi (patient, encounter, atau practitioner tidak memiliki kode SATUSEHAT)';
        $satusehatSyncStatus = 'skipped';
    }

    // --- 18. Ambil data terbaru untuk response ---
    try {
        $stmt = $Conn->prepare("
            SELECT cp.*,
                p.name AS patientName,
                e.EncounterCode,
                mp.name AS medicalPersonelName,
                ca.name AS createdName,
                ua.name AS updatedName
            FROM care_plan cp
            LEFT JOIN patient p ON cp.patientId = p.patientId
            LEFT JOIN encounter e ON cp.encounterId = e.encounterId
            LEFT JOIN medical_personel mp ON cp.medicalPersonelId = mp.medicalPersonelId
            LEFT JOIN account ca ON cp.creatBy = ca.accountId
            LEFT JOIN account ua ON cp.updateBy = ua.accountId
            WHERE cp.carePlanId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $carePlanId]);
        $updatedData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($updatedData) {
            $updatedData['carePlanId'] = (int) $updatedData['carePlanId'];
            $updatedData['patientId'] = (int) $updatedData['patientId'];
            $updatedData['encounterId'] = (int) $updatedData['encounterId'];
            $updatedData['medicalPersonelId'] = (int) $updatedData['medicalPersonelId'];
            $updatedData['creatBy'] = $updatedData['creatBy'] !== null ? (int) $updatedData['creatBy'] : null;
            $updatedData['updateBy'] = $updatedData['updateBy'] !== null ? (int) $updatedData['updateBy'] : null;
            // Hapus null values
            if ($updatedData['patientName'] === null) unset($updatedData['patientName']);
            if ($updatedData['EncounterCode'] === null) unset($updatedData['EncounterCode']);
            if ($updatedData['medicalPersonelName'] === null) unset($updatedData['medicalPersonelName']);
            if ($updatedData['createdName'] === null) unset($updatedData['createdName']);
            if ($updatedData['updatedName'] === null) unset($updatedData['updatedName']);
        }
    } catch (PDOException $e) {
        error_log('[UpdateCarePlan] Fetch updated data error: ' . $e->getMessage());
    }

    // --- 19. Response Sukses ---
    http_response_code(200);
    echo json_encode([
        "response" => [
            "message" => "Care Plan berhasil diperbarui",
            "code" => 200
        ],
        "metadata" => [
            "carePlanId" => $carePlanId,
            "satuSehatCode" => $satuSehatCode,
            "satusehat_sync" => [
                "status" => $satusehatSyncStatus,
                "message" => $satusehatMessage
            ],
            "updated_at" => $nowUtc . ' GMT'
        ],
        "data" => $updatedData
    ]);
?>