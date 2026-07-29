<?php
    /**
     * Create Procedure
     * Endpoint: POST /_API/Procedure/CreateProcedure.php
     * Header: token, account_token
     * Body: JSON {
     *     "encounterId": 22,
     *     "procedureStart": "2026-07-29 08:30",
     *     "procedureEnd": "2026-07-29 09:30",
     *     "procedureReferenceId": 13,
     *     "resonReference": "ICD10",
     *     "resonCode": "A01.0",
     *     "resonDisplay": "Typhoid fever",
     *     "postProcedure": "Kondisi pasien membaik setelah dilakukan tindakan",
     *     "procedurePerformer": [
     *         {
     *             "medicalPersonelId": 1,
     *             "performerType": "Primary",
     *             "performerNote": "Tindakan berjalan lancar"
     *         }
     *     ]
     * }
     *
     * - Validasi mandatory.
     * - Validasi encounterId, procedureReferenceId, medicalPersonelId.
     * - Insert procedure_encounter dan procedure_performer.
     * - Sinkronisasi ke SATUSEHAT jika syarat terpenuhi.
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
    $Limiter->check("create_procedure", 5, 60);

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_procedure' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah tindakan", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateProcedure] Auth error: ' . $e->getMessage());
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
    $procedureStart = isset($input['procedureStart']) ? trim($input['procedureStart']) : '';
    $procedureEnd = isset($input['procedureEnd']) ? trim($input['procedureEnd']) : null;
    $procedureReferenceId = isset($input['procedureReferenceId']) ? (int) $input['procedureReferenceId'] : 0;
    $resonReference = isset($input['resonReference']) ? trim($input['resonReference']) : '';
    $resonCode = isset($input['resonCode']) ? trim($input['resonCode']) : null;
    $resonDisplay = isset($input['resonDisplay']) ? trim($input['resonDisplay']) : null;
    $postProcedure = isset($input['postProcedure']) ? trim($input['postProcedure']) : null;
    $procedurePerformer = isset($input['procedurePerformer']) ? $input['procedurePerformer'] : [];

    // --- 9. Validasi Field Wajib ---
    if ($encounterId <= 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "encounterId wajib diisi dengan angka positif", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($procedureStart)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "procedureStart wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($procedureReferenceId <= 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "procedureReferenceId wajib diisi dengan angka positif", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($resonReference)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "resonReference wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($procedurePerformer) || !is_array($procedurePerformer)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "procedurePerformer harus diisi dengan array minimal 1 performer", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 10. Validasi encounterId ---
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
        error_log('[CreateProcedure] Check encounterId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 11. Validasi procedureReferenceId ---
    $procedureReferenceData = null;
    try {
        $stmt = $Conn->prepare("SELECT * FROM procedure_reference WHERE procedureReferenceId = :id AND status = 1 LIMIT 1");
        $stmt->execute([':id' => $procedureReferenceId]);
        $procedureReferenceData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$procedureReferenceData) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "procedureReferenceId tidak ditemukan atau tidak aktif", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateProcedure] Check procedureReferenceId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi resonReference ---
    $allowedResonReference = ['ICD10', 'ICD11'];
    if (!in_array($resonReference, $allowedResonReference, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "resonReference harus salah satu dari: " . implode(', ', $allowedResonReference),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 13. Validasi procedureStart dan procedureEnd format datetime ---
    if (!DateTime::createFromFormat('Y-m-d H:i', $procedureStart)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "procedureStart harus format YYYY-MM-DD HH:MM", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($procedureEnd !== null) {
        if (!DateTime::createFromFormat('Y-m-d H:i', $procedureEnd)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "procedureEnd harus format YYYY-MM-DD HH:MM", "code" => 422], "metadata" => []]);
            exit;
        }
        $startObj = DateTime::createFromFormat('Y-m-d H:i', $procedureStart);
        $endObj = DateTime::createFromFormat('Y-m-d H:i', $procedureEnd);
        if ($startObj >= $endObj) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "procedureStart harus lebih awal dari procedureEnd", "code" => 422], "metadata" => []]);
            exit;
        }
    }

    // --- 14. Validasi performer ---
    $performerIds = [];
    foreach ($procedurePerformer as $index => $performer) {
        if (!isset($performer['medicalPersonelId']) || (int)$performer['medicalPersonelId'] <= 0) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Performer ke-" . ($index+1) . ": medicalPersonelId wajib diisi", "code" => 422], "metadata" => []]);
            exit;
        }
        if (empty($performer['performerType']) || !in_array($performer['performerType'], ['Primary', 'Assistant'])) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Performer ke-" . ($index+1) . ": performerType harus Primary atau Assistant", "code" => 422], "metadata" => []]);
            exit;
        }
        $performerIds[] = (int) $performer['medicalPersonelId'];
    }

    // --- 15. Validasi medicalPersonelId untuk semua performer ---
    $practitionerData = [];
    try {
        $placeholders = implode(',', array_fill(0, count($performerIds), '?'));
        $stmt = $Conn->prepare("SELECT medicalPersonelId, name, nik, id_practitioner FROM medical_personel WHERE medicalPersonelId IN ($placeholders) AND status = 1");
        $stmt->execute($performerIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($performerIds)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Salah satu medicalPersonelId tidak ditemukan atau tidak aktif", "code" => 422], "metadata" => []]);
            exit;
        }
        foreach ($rows as $row) {
            $practitionerData[$row['medicalPersonelId']] = $row;
        }
    } catch (PDOException $e) {
        error_log('[CreateProcedure] Check medicalPersonelId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 16. Ambil patient satuSehatCode ---
    $patientSatuSehat = null;
    try {
        $stmt = $Conn->prepare("SELECT satuSehatCode FROM patient WHERE patientId = :id LIMIT 1");
        $stmt->execute([':id' => $patientId]);
        $pRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            $patientSatuSehat = $pRow['satuSehatCode'];
        }
    } catch (PDOException $e) {
        error_log('[CreateProcedure] Get patient satuSehatCode error: ' . $e->getMessage());
    }

    // --- 17. Insert procedure_encounter ---
    $procedureId = null;
    $createdDate = $nowUtc;

    try {
        $sql = "INSERT INTO procedure_encounter (
                    patientId,
                    encounterId,
                    satusehatCode,
                    procedureStart,
                    procedureEnd,
                    procedureReferenceId,
                    resonReference,
                    resonCode,
                    resonDisplay,
                    postProcedure,
                    creatAt,
                    updateAt,
                    creatBy,
                    updateBy
                ) VALUES (
                    :patientId,
                    :encounterId,
                    :satusehatCode,
                    :procedureStart,
                    :procedureEnd,
                    :procedureReferenceId,
                    :resonReference,
                    :resonCode,
                    :resonDisplay,
                    :postProcedure,
                    :creatAt,
                    :updateAt,
                    :creatBy,
                    :updateBy
                )";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':patientId' => $patientId,
            ':encounterId' => $encounterId,
            ':satusehatCode' => null,
            ':procedureStart' => $procedureStart,
            ':procedureEnd' => $procedureEnd,
            ':procedureReferenceId' => $procedureReferenceId,
            ':resonReference' => $resonReference,
            ':resonCode' => $resonCode,
            ':resonDisplay' => $resonDisplay,
            ':postProcedure' => $postProcedure,
            ':creatAt' => $createdDate,
            ':updateAt' => $createdDate,
            ':creatBy' => $loggedInAccountId,
            ':updateBy' => $loggedInAccountId
        ]);

        $procedureId = (int) $Conn->lastInsertId();

    } catch (PDOException $e) {
        error_log('[CreateProcedure] Insert procedure error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan data tindakan: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 18. Insert procedure_performer ---
    try {
        foreach ($procedurePerformer as $performer) {
            $mpId = (int) $performer['medicalPersonelId'];
            $performerType = $performer['performerType'];
            $performerNote = isset($performer['performerNote']) ? trim($performer['performerNote']) : null;
            $pract = $practitionerData[$mpId] ?? null;

            $sql = "INSERT INTO procedure_performer (
                        procedureId,
                        medicalPersonelId,
                        performerType,
                        id_practitioner,
                        performerNik,
                        performerName,
                        performerNote
                    ) VALUES (
                        :procedureId,
                        :medicalPersonelId,
                        :performerType,
                        :id_practitioner,
                        :performerNik,
                        :performerName,
                        :performerNote
                    )";
            $stmt = $Conn->prepare($sql);
            $stmt->execute([
                ':procedureId' => $procedureId,
                ':medicalPersonelId' => $mpId,
                ':performerType' => $performerType,
                ':id_practitioner' => $pract ? $pract['id_practitioner'] : null,
                ':performerNik' => $pract ? $pract['nik'] : null,
                ':performerName' => $pract ? $pract['name'] : '',
                ':performerNote' => $performerNote
            ]);
        }
    } catch (PDOException $e) {
        error_log('[CreateProcedure] Insert performer error: ' . $e->getMessage());
        // Hapus procedure yang sudah diinsert jika performer gagal
        $Conn->prepare("DELETE FROM procedure_encounter WHERE procedureId = :id")->execute([':id' => $procedureId]);
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan performer: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 19. Sinkronisasi ke SATUSEHAT ---
    $satusehatSyncStatus = 'skipped';
    $satusehatMessage = 'Sinkronisasi SATUSEHAT dilewati';
    $satusehatErrorDetail = null;
    $satusehatCode = null;

    // Cek syarat: patient.satuSehatCode, encounter.satuSehatCode, minimal satu performer dengan id_practitioner,
    // dan procedure_reference lengkap (procedureCode, procedureDisplay, procedureSystem)
    $primaryPerformerHasPractitioner = false;
    foreach ($procedurePerformer as $perf) {
        $mpId = (int) $perf['medicalPersonelId'];
        if (isset($practitionerData[$mpId]) && !empty($practitionerData[$mpId]['id_practitioner'])) {
            $primaryPerformerHasPractitioner = true;
            break;
        }
    }

    $procedureRefComplete = !empty($procedureReferenceData['procedureCode']) &&
                            !empty($procedureReferenceData['procedureDisplay']) &&
                            !empty($procedureReferenceData['procedureSystem']);

    $canSync = !empty($patientSatuSehat) && !empty($encounterSatuSehat) &&
            $primaryPerformerHasPractitioner && $procedureRefComplete;

    if ($canSync) {
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

                    // Ambil performer utama (Primary) sebagai performer
                    $primaryPerformer = null;
                    foreach ($procedurePerformer as $perf) {
                        if ($perf['performerType'] === 'Primary') {
                            $mpId = (int) $perf['medicalPersonelId'];
                            if (isset($practitionerData[$mpId]) && !empty($practitionerData[$mpId]['id_practitioner'])) {
                                $primaryPerformer = $practitionerData[$mpId];
                                break;
                            }
                        }
                    }
                    if (!$primaryPerformer) {
                        throw new Exception('Tidak ada performer Primary dengan id_practitioner');
                    }

                    // Siapkan payload Procedure
                    date_default_timezone_set('UTC');
                    $procedureDateTime = date('Y-m-d\TH:i:sP', strtotime($procedureStart));
                    $payload = [
                        'resourceType' => 'Procedure',
                        'status' => 'completed', // default, bisa disesuaikan
                        'code' => [
                            'coding' => [
                                [
                                    'system' => $procedureReferenceData['procedureSystem'],
                                    'code' => $procedureReferenceData['procedureCode'],
                                    'display' => $procedureReferenceData['procedureDisplay']
                                ]
                            ]
                        ],
                        'subject' => [
                            'reference' => 'Patient/' . $patientSatuSehat
                        ],
                        'encounter' => [
                            'reference' => 'Encounter/' . $encounterSatuSehat
                        ],
                        'performedDateTime' => $procedureDateTime,
                        'performer' => [
                            [
                                'function' => [
                                    'coding' => [
                                        [
                                            'system' => 'http://terminology.hl7.org/CodeSystem/v3-ParticipationType',
                                            'code' => 'PRF' // Primary performer
                                        ]
                                    ]
                                ],
                                'actor' => [
                                    'reference' => 'Practitioner/' . $primaryPerformer['id_practitioner']
                                ]
                            ]
                        ],
                        'reasonCode' => [
                            [
                                'coding' => [
                                    [
                                        'system' => 'http://hl7.org/fhir/sid/icd-10',
                                        'code' => $resonCode,
                                        'display' => $resonDisplay
                                    ]
                                ]
                            ]
                        ],
                        'bodySite' => [
                            [
                                'coding' => [
                                    [
                                        'system' => $procedureReferenceData['bodySiteSystem'],
                                        'code' => $procedureReferenceData['bodySiteCode'],
                                        'display' => $procedureReferenceData['bodySiteDisplay']
                                    ]
                                ]
                            ]
                        ]
                    ];

                    // Tambahkan note jika ada postProcedure
                    if (!empty($postProcedure)) {
                        $payload['note'] = [
                            [
                                'text' => $postProcedure
                            ]
                        ];
                    }

                    // Kirim POST ke SATUSEHAT
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $baseUrl . '/fhir-r4/v1/Procedure',
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
                            $satusehatCode = $result['id'];
                            // Update procedure_encounter dengan satusehatCode
                            $updStmt = $Conn->prepare("UPDATE procedure_encounter SET satusehatCode = :satusehatCode WHERE procedureId = :id");
                            $updStmt->execute([':satusehatCode' => $satusehatCode, ':id' => $procedureId]);
                            $satusehatSyncStatus = 'success';
                            $satusehatMessage = 'Berhasil disinkronkan ke SATUSEHAT';
                        } else {
                            $satusehatMessage = 'Respons SATUSEHAT tidak mengandung ID';
                            $satusehatErrorDetail = 'ID tidak ditemukan dalam respons';
                        }
                    } else {
                        // Parse error
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
            error_log('[CreateProcedure] SATUSEHAT integration error: ' . $e->getMessage());
        }
    } else {
        // Tentukan syarat yang tidak terpenuhi
        $unmet = [];
        if (empty($patientSatuSehat)) $unmet[] = 'Pasien tidak memiliki satuSehatCode';
        if (empty($encounterSatuSehat)) $unmet[] = 'Encounter tidak memiliki satuSehatCode';
        if (!$primaryPerformerHasPractitioner) $unmet[] = 'Tidak ada performer Primary dengan id_practitioner';
        if (!$procedureRefComplete) $unmet[] = 'Referensi tindakan tidak lengkap (procedureCode, display, system)';
        $satusehatMessage = 'Syarat SATUSEHAT tidak terpenuhi: ' . implode(', ', $unmet);
        $satusehatErrorDetail = $satusehatMessage;
    }

    // --- 20. Ambil data terbaru untuk response (dengan JOIN) ---
    try {
        $stmt = $Conn->prepare("
            SELECT pe.*,
                p.name AS patientName,
                e.EncounterCode,
                pr.procedureName,
                pr.procedureCode AS refProcedureCode,
                pr.procedureDisplay AS refProcedureDisplay,
                ca.name AS createdName,
                ua.name AS updatedName
            FROM procedure_encounter pe
            LEFT JOIN patient p ON pe.patientId = p.patientId
            LEFT JOIN encounter e ON pe.encounterId = e.encounterId
            LEFT JOIN procedure_reference pr ON pe.procedureReferenceId = pr.procedureReferenceId
            LEFT JOIN account ca ON pe.creatBy = ca.accountId
            LEFT JOIN account ua ON pe.updateBy = ua.accountId
            WHERE pe.procedureId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $procedureId]);
        $procedureData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($procedureData) {
            $procedureData['procedureId'] = (int) $procedureData['procedureId'];
            $procedureData['patientId'] = (int) $procedureData['patientId'];
            $procedureData['encounterId'] = (int) $procedureData['encounterId'];
            $procedureData['procedureReferenceId'] = (int) $procedureData['procedureReferenceId'];
            $procedureData['creatBy'] = $procedureData['creatBy'] !== null ? (int) $procedureData['creatBy'] : null;
            $procedureData['updateBy'] = $procedureData['updateBy'] !== null ? (int) $procedureData['updateBy'] : null;
            if ($procedureData['patientName'] === null) unset($procedureData['patientName']);
            if ($procedureData['EncounterCode'] === null) unset($procedureData['EncounterCode']);
            if ($procedureData['procedureName'] === null) unset($procedureData['procedureName']);
            if ($procedureData['refProcedureCode'] === null) unset($procedureData['refProcedureCode']);
            if ($procedureData['refProcedureDisplay'] === null) unset($procedureData['refProcedureDisplay']);
            if ($procedureData['createdName'] === null) unset($procedureData['createdName']);
            if ($procedureData['updatedName'] === null) unset($procedureData['updatedName']);
        }

        // Ambil performer
        $stmt = $Conn->prepare("
            SELECT * FROM procedure_performer WHERE procedureId = :id
        ");
        $stmt->execute([':id' => $procedureId]);
        $performers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($performers as &$perf) {
            $perf['procedurePerformerId'] = (int) $perf['procedurePerformerId'];
            $perf['procedureId'] = (int) $perf['procedureId'];
            $perf['medicalPersonelId'] = $perf['medicalPersonelId'] !== null ? (int) $perf['medicalPersonelId'] : null;
        }
        unset($perf);

    } catch (PDOException $e) {
        error_log('[CreateProcedure] Fetch response data error: ' . $e->getMessage());
    }

    // --- 21. Response Sukses ---
    http_response_code(201);
    echo json_encode([
        "response" => [
            "message" => "Tindakan berhasil ditambahkan",
            "code" => 201
        ],
        "metadata" => [
            "procedureId" => $procedureId,
            "satusehatCode" => $satusehatCode,
            "satusehat_sync" => [
                "status" => $satusehatSyncStatus,
                "message" => $satusehatMessage,
                "error_detail" => $satusehatErrorDetail
            ],
            "created_at" => $createdDate . ' GMT'
        ],
        "data" => [
            "procedure" => $procedureData,
            "performers" => $performers
        ]
    ]);
?>