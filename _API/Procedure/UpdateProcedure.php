<?php
    /**
     * Update Procedure
     * Endpoint: PUT /_API/Procedure/UpdateProcedure.php?procedureId={id}
     * Header: token, account_token
     * Body: JSON {
     *     "procedureStart": "2026-07-28 08:30",
     *     "procedureEnd": "2026-07-28 09:30",
     *     "procedureReferenceId": 13,
     *     "resonReference": "ICD10",
     *     "resonCode": "A01.0",
     *     "resonDisplay": "Typhoid fever",
     *     "postProcedure": "Kondisi pasien membaik setelah dilakukan tindakan"
     * }
     *
     * - Validasi mandatory: procedureStart, procedureReferenceId, resonReference.
     * - Validasi procedureReferenceId ada dan aktif (status=1).
     * - Validasi resonReference enum (ICD10/ICD11).
     * - Validasi format datetime untuk procedureStart dan procedureEnd.
     * - Jika procedureEnd diisi, harus > procedureStart.
     * - Jika satusehatCode terisi, update ke SATUSEHAT.
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
    $Limiter->check("update_procedure", 5, 60);

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

    // --- 6. Validasi Parameter procedureId ---
    if (!isset($_GET['procedureId']) || !is_numeric($_GET['procedureId']) || (int)$_GET['procedureId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter procedureId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $procedureId = (int) $_GET['procedureId'];

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_procedure' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah tindakan", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateProcedure] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data existing ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM procedure_encounter WHERE procedureId = :id LIMIT 1");
        $stmt->execute([':id' => $procedureId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Data tindakan tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
        $oldSatusehatCode = $existingData['satusehatCode'];
    } catch (PDOException $e) {
        error_log('[UpdateProcedure] Fetch existing error: ' . $e->getMessage());
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
    $procedureStart = isset($input['procedureStart']) ? trim($input['procedureStart']) : $existingData['procedureStart'];
    $procedureEnd = isset($input['procedureEnd']) ? trim($input['procedureEnd']) : $existingData['procedureEnd'];
    $procedureReferenceId = isset($input['procedureReferenceId']) ? (int) $input['procedureReferenceId'] : (int) $existingData['procedureReferenceId'];
    $resonReference = isset($input['resonReference']) ? trim($input['resonReference']) : $existingData['resonReference'];
    $resonCode = isset($input['resonCode']) ? trim($input['resonCode']) : $existingData['resonCode'];
    $resonDisplay = isset($input['resonDisplay']) ? trim($input['resonDisplay']) : $existingData['resonDisplay'];
    $postProcedure = isset($input['postProcedure']) ? trim($input['postProcedure']) : $existingData['postProcedure'];

    // --- 11. Validasi Field Wajib ---
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

    // --- 13. Validasi procedureReferenceId ---
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
        error_log('[UpdateProcedure] Check procedureReferenceId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 14. Validasi format datetime ---
    $startObj = DateTime::createFromFormat('Y-m-d H:i', $procedureStart);
    if (!$startObj) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "procedureStart harus format YYYY-MM-DD HH:MM", "code" => 422], "metadata" => []]);
        exit;
    }
    if (!empty($procedureEnd)) {
        $endObj = DateTime::createFromFormat('Y-m-d H:i', $procedureEnd);
        if (!$endObj) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "procedureEnd harus format YYYY-MM-DD HH:MM", "code" => 422], "metadata" => []]);
            exit;
        }
        if ($startObj >= $endObj) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "procedureStart harus lebih awal dari procedureEnd", "code" => 422], "metadata" => []]);
            exit;
        }
    }

    // --- 15. Update Data ---
    try {
        $updatedDate = $nowUtc;

        $sql = "UPDATE procedure_encounter SET
                    procedureStart = :procedureStart,
                    procedureEnd = :procedureEnd,
                    procedureReferenceId = :procedureReferenceId,
                    resonReference = :resonReference,
                    resonCode = :resonCode,
                    resonDisplay = :resonDisplay,
                    postProcedure = :postProcedure,
                    updateAt = :updateAt,
                    updateBy = :updateBy
                WHERE procedureId = :id";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':procedureStart' => $procedureStart,
            ':procedureEnd' => $procedureEnd,
            ':procedureReferenceId' => $procedureReferenceId,
            ':resonReference' => $resonReference,
            ':resonCode' => $resonCode,
            ':resonDisplay' => $resonDisplay,
            ':postProcedure' => $postProcedure,
            ':updateAt' => $updatedDate,
            ':updateBy' => $loggedInAccountId,
            ':id' => $procedureId
        ]);

    } catch (PDOException $e) {
        error_log('[UpdateProcedure] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 16. Sinkronisasi ke SATUSEHAT jika memiliki satusehatCode ---
    $satusehatSyncStatus = 'skipped';
    $satusehatMessage = 'Sinkronisasi SATUSEHAT dilewati (tidak memiliki satusehatCode)';
    $satusehatErrorDetail = null;

    if (!empty($oldSatusehatCode)) {
        // Syarat: procedureReferenceData sudah ada dari step 13
        // Ambil data lain: patient satuSehatCode, encounter satuSehatCode, performer id_practitioner
        $patientSatuSehat = null;
        $encounterSatuSehat = null;
        $primaryPerformerPractitioner = null;

        try {
            // Ambil patient dan encounter dari procedure_encounter
            $stmt = $Conn->prepare("
                SELECT pe.patientId, pe.encounterId, p.satuSehatCode AS patientSatuSehat, e.satuSehatCode AS encounterSatuSehat
                FROM procedure_encounter pe
                LEFT JOIN patient p ON pe.patientId = p.patientId
                LEFT JOIN encounter e ON pe.encounterId = e.encounterId
                WHERE pe.procedureId = :id LIMIT 1
            ");
            $stmt->execute([':id' => $procedureId]);
            $related = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($related) {
                $patientSatuSehat = $related['patientSatuSehat'];
                $encounterSatuSehat = $related['encounterSatuSehat'];
            }

            // Ambil performer Primary dengan id_practitioner
            $stmt = $Conn->prepare("
                SELECT pp.id_practitioner
                FROM procedure_performer pp
                WHERE pp.procedureId = :id AND pp.performerType = 'Primary' AND pp.id_practitioner IS NOT NULL
                LIMIT 1
            ");
            $stmt->execute([':id' => $procedureId]);
            $perf = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($perf) {
                $primaryPerformerPractitioner = $perf['id_practitioner'];
            }

        } catch (PDOException $e) {
            error_log('[UpdateProcedure] Get related data error: ' . $e->getMessage());
        }

        // Cek kelengkapan syarat
        $canSync = !empty($patientSatuSehat) && !empty($encounterSatuSehat) &&
                !empty($primaryPerformerPractitioner) &&
                !empty($procedureReferenceData['procedureCode']) &&
                !empty($procedureReferenceData['procedureDisplay']) &&
                !empty($procedureReferenceData['procedureSystem']) &&
                !empty($procedureReferenceData['bodySiteSystem']) &&
                !empty($procedureReferenceData['bodySiteCode']) &&
                !empty($procedureReferenceData['bodySiteDisplay']);

        if ($canSync) {
            $satusehatSyncStatus = 'failed';
            $satusehatMessage = 'Gagal mengupdate ke SATUSEHAT';

            try {
                $credStmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
                $credStmt->execute();
                $credential = $credStmt->fetch(PDO::FETCH_ASSOC);
                if ($credential) {
                    $tokenResult = generateTokenSatusehat($Conn);
                    if ($tokenResult['status'] === 'success') {
                        $accessToken = $tokenResult['token'];
                        $baseUrl = rtrim($credential['baseUrl'], '/');

                        $procedureDateTime = gmdate('Y-m-d\TH:i:s+00:00', strtotime($procedureStart));

                        $payload = [
                            'resourceType' => 'Procedure',
                            'id' => $oldSatusehatCode,
                            'status' => 'completed',
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
                                                'code' => 'PRF'
                                            ]
                                        ]
                                    ],
                                    'actor' => [
                                        'reference' => 'Practitioner/' . $primaryPerformerPractitioner
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

                        if (!empty($postProcedure)) {
                            $payload['note'] = [
                                [
                                    'text' => $postProcedure
                                ]
                            ];
                        }

                        // Kirim PUT
                        $ch = curl_init();
                        curl_setopt_array($ch, [
                            CURLOPT_URL => $baseUrl . '/fhir-r4/v1/Procedure/' . $oldSatusehatCode,
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
                            $satusehatMessage = 'Berhasil mengupdate Procedure di SATUSEHAT';
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
                error_log('[UpdateProcedure] SATUSEHAT integration error: ' . $e->getMessage());
            }
        } else {
            // Syarat tidak lengkap
            $unmet = [];
            if (empty($patientSatuSehat)) $unmet[] = 'Pasien tidak memiliki satuSehatCode';
            if (empty($encounterSatuSehat)) $unmet[] = 'Encounter tidak memiliki satuSehatCode';
            if (empty($primaryPerformerPractitioner)) $unmet[] = 'Tidak ada performer Primary dengan id_practitioner';
            if (empty($procedureReferenceData['procedureCode'])) $unmet[] = 'procedureCode tidak lengkap';
            if (empty($procedureReferenceData['procedureDisplay'])) $unmet[] = 'procedureDisplay tidak lengkap';
            if (empty($procedureReferenceData['procedureSystem'])) $unmet[] = 'procedureSystem tidak lengkap';
            if (empty($procedureReferenceData['bodySiteSystem'])) $unmet[] = 'bodySiteSystem tidak lengkap';
            if (empty($procedureReferenceData['bodySiteCode'])) $unmet[] = 'bodySiteCode tidak lengkap';
            if (empty($procedureReferenceData['bodySiteDisplay'])) $unmet[] = 'bodySiteDisplay tidak lengkap';
            $satusehatMessage = 'Syarat update SATUSEHAT tidak terpenuhi: ' . implode(', ', $unmet);
            $satusehatErrorDetail = $satusehatMessage;
            $satusehatSyncStatus = 'skipped';
        }
    }

    // --- 17. Ambil data terbaru untuk response ---
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
        $updatedData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($updatedData) {
            $updatedData['procedureId'] = (int) $updatedData['procedureId'];
            $updatedData['patientId'] = (int) $updatedData['patientId'];
            $updatedData['encounterId'] = (int) $updatedData['encounterId'];
            $updatedData['procedureReferenceId'] = (int) $updatedData['procedureReferenceId'];
            $updatedData['creatBy'] = $updatedData['creatBy'] !== null ? (int) $updatedData['creatBy'] : null;
            $updatedData['updateBy'] = $updatedData['updateBy'] !== null ? (int) $updatedData['updateBy'] : null;
            if ($updatedData['patientName'] === null) unset($updatedData['patientName']);
            if ($updatedData['EncounterCode'] === null) unset($updatedData['EncounterCode']);
            if ($updatedData['procedureName'] === null) unset($updatedData['procedureName']);
            if ($updatedData['refProcedureCode'] === null) unset($updatedData['refProcedureCode']);
            if ($updatedData['refProcedureDisplay'] === null) unset($updatedData['refProcedureDisplay']);
            if ($updatedData['createdName'] === null) unset($updatedData['createdName']);
            if ($updatedData['updatedName'] === null) unset($updatedData['updatedName']);
        }
    } catch (PDOException $e) {
        error_log('[UpdateProcedure] Fetch updated data error: ' . $e->getMessage());
    }

    // --- 18. Response Sukses ---
    http_response_code(200);
    echo json_encode([
        "response" => [
            "message" => "Data tindakan berhasil diperbarui",
            "code" => 200
        ],
        "metadata" => [
            "procedureId" => $procedureId,
            "satusehatCode" => $oldSatusehatCode,
            "satusehat_sync" => [
                "status" => $satusehatSyncStatus,
                "message" => $satusehatMessage,
                "error_detail" => $satusehatErrorDetail
            ],
            "updated_at" => $nowUtc . ' GMT'
        ],
        "data" => $updatedData
    ]);
?>