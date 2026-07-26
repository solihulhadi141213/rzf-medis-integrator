<?php
    /**
     * Update Diagnosis
     * Endpoint: PUT /_API/Diagnosis/UpdateDiagnosis.php?diagnosisId={id}
     * Header: token, account_token
     * Body: JSON {
     *     "encounterId": 22,
     *     "idCondition": "",
     *     "medicalPersonelId": 5,
     *     "category": "Admission",
     *     "id_icd": 18,
     *     "diagnosisText": "Sakit kepala disertai batuk dan pilek",
     *     "caseStatus": "Lama",
     *     "certaintyStatus": "Final"
     * }
     *
     * - Jika idCondition kosong, kirim POST ke SATUSEHAT dan simpan idCondition.
     * - Jika idCondition terisi, kirim PUT ke SATUSEHAT untuk update.
     * - Validasi mandatory dan referensi data.
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
    $Limiter->check("update_diagnosis", 5, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

    // --- 6. Validasi Parameter diagnosisId ---
    if (!isset($_GET['diagnosisId']) || !is_numeric($_GET['diagnosisId']) || (int)$_GET['diagnosisId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter diagnosisId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $diagnosisId = (int) $_GET['diagnosisId'];

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_diagnosis' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah diagnosis", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateDiagnosis] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data diagnosis existing ---
    $existingData = null;
    try {
        $stmt = $Conn->prepare("SELECT * FROM diagnosis WHERE diagnosisId = :id LIMIT 1");
        $stmt->execute([':id' => $diagnosisId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Data diagnosis tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateDiagnosis] Fetch existing error: ' . $e->getMessage());
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
    $encounterId = isset($input['encounterId']) ? (int) $input['encounterId'] : (int) $existingData['encounterId'];
    $idCondition = isset($input['idCondition']) ? trim($input['idCondition']) : $existingData['idCondition'];
    $medicalPersonelId = isset($input['medicalPersonelId']) ? (int) $input['medicalPersonelId'] : (int) $existingData['medicalPersonelId'];
    $category = isset($input['category']) ? trim($input['category']) : $existingData['category'];
    $id_icd = isset($input['id_icd']) ? (int) $input['id_icd'] : (int) $existingData['id_icd'];
    $diagnosisText = isset($input['diagnosisText']) ? trim($input['diagnosisText']) : $existingData['diagnosisText'];
    $caseStatus = isset($input['caseStatus']) ? trim($input['caseStatus']) : $existingData['caseStatus'];
    $certaintyStatus = isset($input['certaintyStatus']) ? trim($input['certaintyStatus']) : $existingData['certaintyStatus'];

    // --- 11. Validasi Field Wajib ---
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

    // --- 12. Validasi encounterId dan ambil patientId & satuSehatCode ---
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
        error_log('[UpdateDiagnosis] Check encounterId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 13. Validasi medicalPersonelId dan ambil id_practitioner ---
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
        error_log('[UpdateDiagnosis] Check medicalPersonelId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 14. Validasi id_icd dan ambil data ICD ---
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
        error_log('[UpdateDiagnosis] Check id_icd error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 15. Validasi enum fields ---
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

    // --- 16. Validasi panjang field (opsional) ---
    if ($diagnosisText !== null && strlen($diagnosisText) > 65535) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "diagnosisText terlalu panjang", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 17. Ambil patient satuSehatCode untuk syarat SATUSEHAT ---
    $patientSatuSehat = null;
    try {
        $stmt = $Conn->prepare("SELECT satuSehatCode FROM patient WHERE patientId = :id LIMIT 1");
        $stmt->execute([':id' => $patientId]);
        $pRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            $patientSatuSehat = $pRow['satuSehatCode'];
        }
    } catch (PDOException $e) {
        error_log('[UpdateDiagnosis] Get patient satuSehatCode error: ' . $e->getMessage());
    }

    // --- 18. Update data lokal ---
    try {
        $updatedDate = $nowUtc;
        $sql = "UPDATE diagnosis SET
                    encounterId = :encounterId,
                    patientId = :patientId,
                    medicalPersonelId = :medicalPersonelId,
                    category = :category,
                    icdVersion = :icdVersion,
                    icdCode = :icdCode,
                    icdDescription = :icdDescription,
                    diagnosisText = :diagnosisText,
                    caseStatus = :caseStatus,
                    certaintyStatus = :certaintyStatus,
                    updateAt = :updateAt,
                    updateBy = :updateBy
                WHERE diagnosisId = :id";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':encounterId' => $encounterId,
            ':patientId' => $patientId,
            ':medicalPersonelId' => $medicalPersonelId,
            ':category' => $category,
            ':icdVersion' => $icdVersion,
            ':icdCode' => $icdCode,
            ':icdDescription' => $icdDescription,
            ':diagnosisText' => $diagnosisText,
            ':caseStatus' => $caseStatus,
            ':certaintyStatus' => $certaintyStatus,
            ':updateAt' => $updatedDate,
            ':updateBy' => $loggedInAccountId,
            ':id' => $diagnosisId
        ]);
    } catch (PDOException $e) {
        error_log('[UpdateDiagnosis] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 19. Sinkronisasi ke SATUSEHAT ---
    $satusehatSyncStatus = 'skipped';
    $satusehatMessage = 'Sinkronisasi SATUSEHAT dilewati';
    $satusehatErrorDetail = null;
    $newIdCondition = $idCondition; // default

    // Cek syarat SATUSEHAT
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

    if ($allRequirementsMet) {
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

                    $recordedDate = gmdate('Y-m-d\TH:i:s\Z', strtotime($nowUtc));

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

                    // Tentukan metode: POST jika idCondition kosong, PUT jika terisi
                    $satusehatSyncStatus = 'failed';
                    $satusehatMessage = 'Gagal sinkronisasi ke SATUSEHAT';
                    $satusehatErrorDetail = null;

                    if (empty($idCondition)) {
                        // POST baru
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
                                $newIdCondition = $result['id'];
                                // Update local diagnosis dengan idCondition baru
                                $updStmt = $Conn->prepare("UPDATE diagnosis SET idCondition = :idCondition WHERE diagnosisId = :id");
                                $updStmt->execute([':idCondition' => $newIdCondition, ':id' => $diagnosisId]);
                                $satusehatSyncStatus = 'success';
                                $satusehatMessage = 'Berhasil membuat Condition di SATUSEHAT';
                            } else {
                                $satusehatMessage = 'Respons SATUSEHAT tidak mengandung ID';
                                $satusehatErrorDetail = 'ID tidak ditemukan dalam respons';
                            }
                        } else {
                            $result = json_decode($response, true);
                            if ($result && isset($result['issue']) && is_array($result['issue'])) {
                                $errors = [];
                                foreach ($result['issue'] as $issue) {
                                    $details = isset($issue['details']['text']) ? $issue['details']['text'] : '';
                                    $diagnostics = isset($issue['diagnostics']) ? $issue['diagnostics'] : '';
                                    $expression = isset($issue['expression']) ? implode(', ', $issue['expression']) : '';
                                    $errorMsg = '';
                                    if ($details) $errorMsg .= $details;
                                    if ($diagnostics) $errorMsg .= ($errorMsg ? ' - ' : '') . $diagnostics;
                                    if ($expression) $errorMsg .= ($errorMsg ? ' (Field: ' : 'Field: ') . $expression . ')';
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
                        // PUT update existing
                        $payload['id'] = $idCondition;
                        $ch = curl_init();
                        curl_setopt_array($ch, [
                            CURLOPT_URL => $baseUrl . '/fhir-r4/v1/Condition/' . $idCondition,
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
                            $satusehatMessage = 'Berhasil mengupdate Condition di SATUSEHAT';
                        } else {
                            $result = json_decode($response, true);
                            if ($result && isset($result['issue']) && is_array($result['issue'])) {
                                $errors = [];
                                foreach ($result['issue'] as $issue) {
                                    $details = isset($issue['details']['text']) ? $issue['details']['text'] : '';
                                    $diagnostics = isset($issue['diagnostics']) ? $issue['diagnostics'] : '';
                                    $expression = isset($issue['expression']) ? implode(', ', $issue['expression']) : '';
                                    $errorMsg = '';
                                    if ($details) $errorMsg .= $details;
                                    if ($diagnostics) $errorMsg .= ($errorMsg ? ' - ' : '') . $diagnostics;
                                    if ($expression) $errorMsg .= ($errorMsg ? ' (Field: ' : 'Field: ') . $expression . ')';
                                    $errors[] = $errorMsg;
                                }
                                $satusehatErrorDetail = implode('; ', $errors);
                                $satusehatMessage = 'HTTP ' . $httpCode . ' - ' . $satusehatErrorDetail;
                            } else {
                                $satusehatErrorDetail = substr($response, 0, 500);
                                $satusehatMessage = 'HTTP ' . $httpCode . ' - ' . $satusehatErrorDetail;
                            }
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
            error_log('[UpdateDiagnosis] SATUSEHAT integration error: ' . $e->getMessage());
        }
    } else {
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

    // --- 20. Ambil data terbaru untuk response ---
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
        $updatedData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($updatedData) {
            $updatedData['diagnosisId'] = (int) $updatedData['diagnosisId'];
            $updatedData['encounterId'] = (int) $updatedData['encounterId'];
            $updatedData['patientId'] = (int) $updatedData['patientId'];
            $updatedData['medicalPersonelId'] = $updatedData['medicalPersonelId'] !== null ? (int) $updatedData['medicalPersonelId'] : null;
            $updatedData['creatBy'] = $updatedData['creatBy'] !== null ? (int) $updatedData['creatBy'] : null;
            $updatedData['updateBy'] = $updatedData['updateBy'] !== null ? (int) $updatedData['updateBy'] : null;
            if ($updatedData['patientName'] === null) unset($updatedData['patientName']);
            if ($updatedData['EncounterCode'] === null) unset($updatedData['EncounterCode']);
            if ($updatedData['medicalPersonelName'] === null) unset($updatedData['medicalPersonelName']);
            if ($updatedData['createdName'] === null) unset($updatedData['createdName']);
            if ($updatedData['updatedName'] === null) unset($updatedData['updatedName']);
        }
    } catch (PDOException $e) {
        error_log('[UpdateDiagnosis] Fetch updated data error: ' . $e->getMessage());
    }

    // --- 21. Response Sukses ---
    http_response_code(200);
    echo json_encode([
        "response" => [
            "message" => "Diagnosis berhasil diperbarui",
            "code" => 200
        ],
        "metadata" => [
            "diagnosisId" => $diagnosisId,
            "idCondition" => $newIdCondition,
            "satusehat_sync" => [
                "status" => $satusehatSyncStatus,
                "message" => $satusehatMessage,
                "error_detail" => $satusehatErrorDetail,
                "requirements" => $satusehatRequirements
            ],
            "updated_at" => $nowUtc . ' GMT'
        ],
        "data" => $updatedData
    ]);
?>