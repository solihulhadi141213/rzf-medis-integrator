<?php
    /**
     * Update Observation Result
     * Endpoint: PUT /_API/Observation/UpdateObservation.php?observationResumeId={id}
     * Header: token, account_token
     * Body: JSON { ... }
     *
     * - Validasi observationResumeId ada.
     * - Hanya field yang dikirim yang diupdate (field opsional).
     * - medicalPersonelId dan observationAt wajib jika diubah, atau pertahankan nilai lama.
     * - Berdasarkan resultType, update field hasil yang sesuai.
     * - Jika satuSehatCode sudah ada, update ke SATUSEHAT (PUT).
     * - Jika belum ada, cek syarat dan kirim ke SATUSEHAT (POST) jika memenuhi.
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
    $Limiter->check("update_observation", 5, 60);

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

    // --- 6. Validasi Parameter observationResumeId ---
    if (!isset($_GET['observationResumeId']) || !is_numeric($_GET['observationResumeId']) || (int)$_GET['observationResumeId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter observationResumeId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $observationResumeId = (int) $_GET['observationResumeId'];

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_observation' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah hasil observasi", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateObservation] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data existing ---
    try {
        $stmt = $Conn->prepare("
            SELECT o.*, ref.resultType 
            FROM observation_result o
            LEFT JOIN observation_reference ref ON o.observationReferenceId = ref.observationReferenceId
            WHERE o.observationResumeId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $observationResumeId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Data hasil observasi tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
        $resultType = $existingData['resultType'];
        $oldSatusehatCode = $existingData['satuSehatCode'];
        $oldObservationAt = $existingData['observationAt'];
        $oldMedicalPersonelId = (int) $existingData['medicalPersonelId'];
        $oldResultNumeric = $existingData['resultNumeric'];
        $oldResultDecimal = $existingData['resultDecimal'];
        $oldResultCoded = $existingData['resultCoded'];
        $oldResultText = $existingData['resultText'];
        $oldOtherDetail = $existingData['OtherDetail'];
        $oldPatientId = (int) $existingData['patientId'];
        $oldEncounterId = (int) $existingData['encounterId'];
        $oldObservationReferenceId = (int) $existingData['observationReferenceId'];
    } catch (PDOException $e) {
        error_log('[UpdateObservation] Fetch existing error: ' . $e->getMessage());
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
    $observationAt = isset($input['observationAt']) ? trim($input['observationAt']) : $oldObservationAt;
    $resultNumeric = isset($input['resultNumeric']) && $input['resultNumeric'] !== '' ? (int) $input['resultNumeric'] : null;
    $resultDecimal = isset($input['resultDecimal']) && $input['resultDecimal'] !== '' ? (float) $input['resultDecimal'] : null;
    $resultCoded = isset($input['resultCoded']) ? trim($input['resultCoded']) : null;
    $resultText = isset($input['resultText']) ? trim($input['resultText']) : null;
    $OtherDetail = isset($input['OtherDetail']) ? trim($input['OtherDetail']) : $oldOtherDetail;

    // --- 11. Validasi Field ---
    if ($medicalPersonelId <= 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "medicalPersonelId harus diisi dengan angka positif", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($observationAt)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "observationAt wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi observationAt format ---
    $date = DateTime::createFromFormat('Y-m-d H:i:s', $observationAt);
    if (!$date || $date->format('Y-m-d H:i:s') !== $observationAt) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "observationAt harus format YYYY-MM-DD HH:MM:SS (UTC)", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 13. Validasi medicalPersonelId ---
    try {
        $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE medicalPersonelId = :id AND status = 1 LIMIT 1");
        $stmt->execute([':id' => $medicalPersonelId]);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "medicalPersonelId tidak ditemukan atau tidak aktif", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateObservation] Check medicalPersonelId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 14. Validasi hasil sesuai resultType ---
    $resultValue = null;
    if ($resultType === 'Numeric') {
        if ($resultNumeric === null) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "resultNumeric wajib diisi untuk resultType Numeric", "code" => 422], "metadata" => []]);
            exit;
        }
        $resultValue = (float) $resultNumeric;
        $resultDecimal = null;
        $resultCoded = null;
        $resultText = null;
    } elseif ($resultType === 'Decimal') {
        if ($resultDecimal === null) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "resultDecimal wajib diisi untuk resultType Decimal", "code" => 422], "metadata" => []]);
            exit;
        }
        $resultValue = $resultDecimal;
        $resultNumeric = null;
        $resultCoded = null;
        $resultText = null;
    } elseif ($resultType === 'Coded') {
        if (empty($resultCoded)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "resultCoded wajib diisi untuk resultType Coded", "code" => 422], "metadata" => []]);
            exit;
        }
        $resultValue = $resultCoded;
        $resultNumeric = null;
        $resultDecimal = null;
        $resultText = null;
    } elseif ($resultType === 'Text') {
        if (empty($resultText)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "resultText wajib diisi untuk resultType Text", "code" => 422], "metadata" => []]);
            exit;
        }
        $resultValue = $resultText;
        $resultNumeric = null;
        $resultDecimal = null;
        $resultCoded = null;
    } else {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "resultType tidak dikenal", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 15. Ambil data patient (untuk interpertasi ulang) ---
    $patientGender = null;
    $patientBirthDate = null;
    try {
        $stmt = $Conn->prepare("SELECT gender, birthDate FROM patient WHERE patientId = :id LIMIT 1");
        $stmt->execute([':id' => $oldPatientId]);
        $pat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pat) {
            $patientGender = $pat['gender'];
            $patientBirthDate = $pat['birthDate'];
        }
    } catch (PDOException $e) {
        error_log('[UpdateObservation] Get patient data error: ' . $e->getMessage());
    }

    // --- 16. Ambil data referensi observasi ---
    $allowAge = 0;
    $allowSex = 0;
    try {
        $stmt = $Conn->prepare("SELECT allowAge, allowSex FROM observation_reference WHERE observationReferenceId = :id LIMIT 1");
        $stmt->execute([':id' => $oldObservationReferenceId]);
        $ref = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ref) {
            $allowAge = (int) $ref['allowAge'];
            $allowSex = (int) $ref['allowSex'];
        }
    } catch (PDOException $e) {
        error_log('[UpdateObservation] Get ref data error: ' . $e->getMessage());
    }

    // --- 17. Hitung ulang interpertasi ---
    $InterpertationByAge = null;
    $InterpertationByCoded = null;
    $InterpertationByRange = null;

    // 17a. Jika allowAge true, hitung usia
    if ($allowAge && $patientBirthDate) {
        $birth = DateTime::createFromFormat('Y-m-d', $patientBirthDate);
        $obs = DateTime::createFromFormat('Y-m-d H:i:s', $observationAt);
        if ($birth && $obs) {
            $stmt = $Conn->prepare("
                SELECT observationReferenceAgeId, ageMin, ageMax, ageUnit
                FROM observation_reference_age
                WHERE observationReferenceId = :refId
                ORDER BY ageMin ASC
            ");
            $stmt->execute([':refId' => $oldObservationReferenceId]);
            $ages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ageInYears = $birth->diff($obs)->y;
            $ageInMonths = $birth->diff($obs)->m + ($birth->diff($obs)->y * 12);
            $ageInDays = $birth->diff($obs)->days;

            foreach ($ages as $age) {
                $unit = $age['ageUnit'];
                $ageValue = null;
                if ($unit === 'Year') $ageValue = $ageInYears;
                elseif ($unit === 'Month') $ageValue = $ageInMonths;
                elseif ($unit === 'Day') $ageValue = $ageInDays;
                else continue;

                $min = $age['ageMin'];
                $max = $age['ageMax'];
                $match = false;
                if ($max === null) {
                    if ($ageValue >= $min) $match = true;
                } else {
                    if ($ageValue >= $min && $ageValue <= $max) $match = true;
                }
                if ($match) {
                    $InterpertationByAge = $age['observationReferenceAgeId'];
                    break;
                }
            }
        }
    }

    // 17b. Jika resultType Coded, cari coded matching
    if ($resultType === 'Coded' && !empty($resultCoded)) {
        $sql = "SELECT `observationReferenceCodedId`
                FROM `observation_reference_coded`
                WHERE `observationReferenceId` = :refId
                AND `valueResult` = :value";
        if ($InterpertationByAge === null) {
            $sql .= " AND `observationReferenceAgeId` IS NULL";
        } else {
            $sql .= " AND `observationReferenceAgeId` = :ageId";
        }
        $sql .= " AND (`groupGender` = :gender OR `groupGender` = 'All')
                LIMIT 1";
        $stmt = $Conn->prepare($sql);
        $params = [':refId' => $oldObservationReferenceId, ':value' => $resultCoded, ':gender' => $patientGender];
        if ($InterpertationByAge !== null) {
            $params[':ageId'] = $InterpertationByAge;
        }
        $stmt->execute($params);
        $codedMatch = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($codedMatch) {
            $InterpertationByCoded = $codedMatch['observationReferenceCodedId'];
        }
    }

    // 17c. Jika resultType Numeric/Decimal, cari range matching
    if (($resultType === 'Numeric' || $resultType === 'Decimal') && $resultValue !== null) {
        $sql = "SELECT `observationResultRangeId`, `minValue`, `maxValue`, `rangeOperator`
                FROM `observation_reference_range`
                WHERE `observationReferenceId` = :refId";
        if ($InterpertationByAge === null) {
            $sql .= " AND `observationReferenceAgeId` IS NULL";
        } else {
            $sql .= " AND `observationReferenceAgeId` = :ageId";
        }
        $sql .= " AND (`groupGender` = :gender OR `groupGender` = 'All')
                ORDER BY `normalResult` DESC, `observationResultRangeId` ASC";
        $stmt = $Conn->prepare($sql);
        $params = [':refId' => $oldObservationReferenceId, ':gender' => $patientGender];
        if ($InterpertationByAge !== null) {
            $params[':ageId'] = $InterpertationByAge;
        }
        $stmt->execute($params);
        $ranges = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ranges as $range) {
            $match = false;
            $op = $range['rangeOperator'];
            $min = ($range['minValue'] !== null) ? (float) $range['minValue'] : null;
            $max = ($range['maxValue'] !== null) ? (float) $range['maxValue'] : null;
            if ($op === 'Less' && $max !== null && $resultValue < $max) $match = true;
            elseif ($op === 'More' && $min !== null && $resultValue > $min) $match = true;
            elseif ($op === 'Between' && $min !== null && $max !== null && $resultValue >= $min && $resultValue <= $max) $match = true;
            if ($match) {
                $InterpertationByRange = $range['observationResultRangeId'];
                break;
            }
        }
    }

    // --- 18. Update data di database ---
    try {
        $updatedDate = $nowUtc;

        $sql = "UPDATE observation_result SET
                    medicalPersonelId = :medicalPersonelId,
                    observationAt = :observationAt,
                    resultNumeric = :resultNumeric,
                    resultDecimal = :resultDecimal,
                    resultCoded = :resultCoded,
                    resultText = :resultText,
                    InterpertationByAge = :InterpertationByAge,
                    InterpertationByCoded = :InterpertationByCoded,
                    InterpertationByRange = :InterpertationByRange,
                    OtherDetail = :OtherDetail,
                    updateAt = :updateAt,
                    updateBy = :updateBy
                WHERE observationResumeId = :id";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':medicalPersonelId' => $medicalPersonelId,
            ':observationAt' => $observationAt,
            ':resultNumeric' => $resultNumeric,
            ':resultDecimal' => $resultDecimal,
            ':resultCoded' => $resultCoded,
            ':resultText' => $resultText,
            ':InterpertationByAge' => $InterpertationByAge,
            ':InterpertationByCoded' => $InterpertationByCoded,
            ':InterpertationByRange' => $InterpertationByRange,
            ':OtherDetail' => $OtherDetail,
            ':updateAt' => $updatedDate,
            ':updateBy' => $loggedInAccountId,
            ':id' => $observationResumeId
        ]);

    } catch (PDOException $e) {
        error_log('[UpdateObservation] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 19. Sinkronisasi ke SATUSEHAT ---
    $satusehatSyncStatus = 'skipped';
    $satusehatMessage = 'Sinkronisasi SATUSEHAT tidak dilakukan';
    $satusehatCode = $oldSatusehatCode; // default

    try {
        // Ambil data lengkap untuk sync
        $stmt = $Conn->prepare("
            SELECT 
                ors.*,
                p.satuSehatCode AS patientSatuSehat,
                e.satuSehatCode AS encounterSatuSehat,
                mp.id_practitioner,
                ref.categoryCode,
                ref.categoryDisplay,
                ref.categorySystem,
                ref.observationCode,
                ref.observationDisplay,
                ref.observationSystem,
                ref.unitCode,
                ref.unitDisplay,
                ref.unitSystem,
                ref.resultType AS resultType,  -- PERBAIKAN: ambil dari ref, bukan ors
                coded.codeResult AS interpCode,
                coded.displayResult AS interpDisplay,
                coded.systemResult AS interpSystem,
                rng.InterpertationCode AS rangeCode,
                rng.InterpertationDisplay AS rangeDisplay,
                rng.InterpertationSystem AS rangeSystem
            FROM observation_result ors
            LEFT JOIN patient p ON ors.patientId = p.patientId
            LEFT JOIN encounter e ON ors.encounterId = e.encounterId
            LEFT JOIN medical_personel mp ON ors.medicalPersonelId = mp.medicalPersonelId
            LEFT JOIN observation_reference ref ON ors.observationReferenceId = ref.observationReferenceId
            LEFT JOIN observation_reference_coded coded ON ors.InterpertationByCoded = coded.observationReferenceCodedId
            LEFT JOIN observation_reference_range rng ON ors.InterpertationByRange = rng.observationResultRangeId
            WHERE ors.observationResumeId = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $observationResumeId]);
        $syncData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($syncData) {
            $canSync = true;
            $missing = [];

            // Syarat
            if (empty($syncData['categoryCode']) || empty($syncData['categoryDisplay']) || empty($syncData['categorySystem'])) {
                $canSync = false;
                $missing[] = 'Kategori tidak lengkap (code, display, system)';
            }
            if (empty($syncData['observationCode']) || empty($syncData['observationDisplay']) || empty($syncData['observationSystem'])) {
                $canSync = false;
                $missing[] = 'Kode observasi tidak lengkap (code, display, system)';
            }
            if (!empty($syncData['unitCode']) || !empty($syncData['unitDisplay']) || !empty($syncData['unitSystem'])) {
                if (empty($syncData['unitCode']) || empty($syncData['unitDisplay']) || empty($syncData['unitSystem'])) {
                    $canSync = false;
                    $missing[] = 'Unit tidak lengkap (code, display, system)';
                }
            }
            if (empty($syncData['patientSatuSehat'])) {
                $canSync = false;
                $missing[] = 'Pasien tidak memiliki satuSehatCode';
            }
            if (empty($syncData['encounterSatuSehat'])) {
                $canSync = false;
                $missing[] = 'Kunjungan tidak memiliki satuSehatCode';
            }

            // Interpretasi
            $hasInterpretation = false;
            $interpCode = null;
            $interpDisplay = null;
            $interpSystem = null;
            if (!empty($syncData['interpCode']) && !empty($syncData['interpDisplay']) && !empty($syncData['interpSystem'])) {
                $interpCode = $syncData['interpCode'];
                $interpDisplay = $syncData['interpDisplay'];
                $interpSystem = $syncData['interpSystem'];
                $hasInterpretation = true;
            } elseif (!empty($syncData['rangeCode']) && !empty($syncData['rangeDisplay']) && !empty($syncData['rangeSystem'])) {
                $interpCode = $syncData['rangeCode'];
                $interpDisplay = $syncData['rangeDisplay'];
                $interpSystem = $syncData['rangeSystem'];
                $hasInterpretation = true;
            }
            if ($hasInterpretation && (empty($interpCode) || empty($interpDisplay) || empty($interpSystem))) {
                $canSync = false;
                $missing[] = 'Interpertasi tidak lengkap (code, display, system)';
            }

            if ($canSync) {
                // Ambil resultType dari syncData
                $resultType = $syncData['resultType'];

                // Payload
                $payload = [
                    'resourceType' => 'Observation',
                    'status' => 'final',
                    'category' => [
                        [
                            'coding' => [
                                [
                                    'system' => $syncData['categorySystem'],
                                    'code' => $syncData['categoryCode'],
                                    'display' => $syncData['categoryDisplay']
                                ]
                            ]
                        ]
                    ],
                    'code' => [
                        'coding' => [
                            [
                                'system' => $syncData['observationSystem'],
                                'code' => $syncData['observationCode'],
                                'display' => $syncData['observationDisplay']
                            ]
                        ]
                    ],
                    'subject' => ['reference' => 'Patient/' . $syncData['patientSatuSehat']],
                    'encounter' => ['reference' => 'Encounter/' . $syncData['encounterSatuSehat']],
                    'effectiveDateTime' => gmdate('Y-m-d\TH:i:s\Z', strtotime($syncData['observationAt'])),
                    'issued' => gmdate('Y-m-d\TH:i:s\Z'),
                    'performer' => [['reference' => 'Practitioner/' . $syncData['id_practitioner']]]
                ];

                // Value
                if ($resultType === 'Numeric' || $resultType === 'Decimal') {
                    $value = (float) ($syncData['resultDecimal'] ?: $syncData['resultNumeric']);
                    $payload['valueQuantity'] = [
                        'value' => $value,
                        'unit' => $syncData['unitDisplay'],
                        'system' => $syncData['unitSystem'],
                        'code' => $syncData['unitCode']
                    ];
                } elseif ($resultType === 'Coded') {
                    if ($syncData['InterpertationByCoded']) {
                        $stmt2 = $Conn->prepare("SELECT systemResult, codeResult, displayResult FROM observation_reference_coded WHERE observationReferenceCodedId = :id LIMIT 1");
                        $stmt2->execute([':id' => $syncData['InterpertationByCoded']]);
                        $codedDetail = $stmt2->fetch(PDO::FETCH_ASSOC);
                        if ($codedDetail) {
                            $payload['valueCodeableConcept'] = [
                                'coding' => [
                                    [
                                        'system' => $codedDetail['systemResult'],
                                        'code' => $codedDetail['codeResult'],
                                        'display' => $codedDetail['displayResult']
                                    ]
                                ],
                                'text' => $syncData['resultCoded']
                            ];
                        } else {
                            $payload['valueCodeableConcept'] = ['text' => $syncData['resultCoded']];
                        }
                    } else {
                        $payload['valueCodeableConcept'] = ['text' => $syncData['resultCoded']];
                    }
                } elseif ($resultType === 'Text') {
                    $payload['valueString'] = $syncData['resultText'];
                }

                // Interpretasi
                if ($hasInterpretation) {
                    $payload['interpretation'] = [
                        [
                            'coding' => [
                                [
                                    'system' => $interpSystem,
                                    'code' => $interpCode,
                                    'display' => $interpDisplay
                                ]
                            ]
                        ]
                    ];
                }
                if (!empty($syncData['OtherDetail'])) {
                    $payload['note'] = [['text' => $syncData['OtherDetail']]];
                }

                // Kirim ke SATUSEHAT
                $satusehatSyncStatus = 'failed';
                $satusehatMessage = 'Gagal mengirim ke SATUSEHAT';

                $credStmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
                $credStmt->execute();
                $credential = $credStmt->fetch(PDO::FETCH_ASSOC);
                if ($credential) {
                    $tokenResult = generateTokenSatusehat($Conn);
                    if ($tokenResult['status'] === 'success') {
                        $accessToken = $tokenResult['token'];
                        $baseUrl = rtrim($credential['baseUrl'], '/');

                        // Tentukan metode: PUT jika sudah ada satuSehatCode, POST jika belum
                        $method = empty($oldSatusehatCode) ? 'POST' : 'PUT';
                        $url = $baseUrl . '/fhir-r4/v1/Observation';
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
                                $satusehatCode = $result['id'];
                                if (empty($oldSatusehatCode)) {
                                    $updStmt = $Conn->prepare("UPDATE observation_result SET satuSehatCode = :code WHERE observationResumeId = :id");
                                    $updStmt->execute([':code' => $satusehatCode, ':id' => $observationResumeId]);
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
            } else {
                $satusehatMessage = 'Syarat tidak terpenuhi: ' . implode(', ', $missing);
                $satusehatSyncStatus = 'skipped';
            }
        }
    } catch (Exception $e) {
        error_log('[UpdateObservation] SATUSEHAT integration error: ' . $e->getMessage());
        $satusehatMessage = 'Exception: ' . $e->getMessage();
        $satusehatSyncStatus = 'failed';
    }

    // --- 20. Ambil data terbaru untuk response ---
    try {
        $stmt = $Conn->prepare("
            SELECT ors.*,
                p.name AS patientName,
                e.EncounterCode,
                mp.name AS medicalPersonelName,
                ca.name AS createdName,
                ua.name AS updatedName
            FROM observation_result ors
            LEFT JOIN patient p ON ors.patientId = p.patientId
            LEFT JOIN encounter e ON ors.encounterId = e.encounterId
            LEFT JOIN medical_personel mp ON ors.medicalPersonelId = mp.medicalPersonelId
            LEFT JOIN account ca ON ors.creatBy = ca.accountId
            LEFT JOIN account ua ON ors.updateBy = ua.accountId
            WHERE ors.observationResumeId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $observationResumeId]);
        $updatedData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($updatedData) {
            $updatedData['observationResumeId'] = (int) $updatedData['observationResumeId'];
            $updatedData['observationReferenceId'] = (int) $updatedData['observationReferenceId'];
            $updatedData['patientId'] = (int) $updatedData['patientId'];
            $updatedData['encounterId'] = (int) $updatedData['encounterId'];
            $updatedData['medicalPersonelId'] = (int) $updatedData['medicalPersonelId'];
            $updatedData['InterpertationByAge'] = $updatedData['InterpertationByAge'] !== null ? (int) $updatedData['InterpertationByAge'] : null;
            $updatedData['InterpertationByCoded'] = $updatedData['InterpertationByCoded'] !== null ? (int) $updatedData['InterpertationByCoded'] : null;
            $updatedData['InterpertationByRange'] = $updatedData['InterpertationByRange'] !== null ? (int) $updatedData['InterpertationByRange'] : null;
            $updatedData['creatBy'] = $updatedData['creatBy'] !== null ? (int) $updatedData['creatBy'] : null;
            $updatedData['updateBy'] = $updatedData['updateBy'] !== null ? (int) $updatedData['updateBy'] : null;
            $updatedData['resultNumeric'] = $updatedData['resultNumeric'] !== null ? (int) $updatedData['resultNumeric'] : null;
            $updatedData['resultDecimal'] = $updatedData['resultDecimal'] !== null ? (float) $updatedData['resultDecimal'] : null;
            if ($updatedData['patientName'] === null) unset($updatedData['patientName']);
            if ($updatedData['EncounterCode'] === null) unset($updatedData['EncounterCode']);
            if ($updatedData['medicalPersonelName'] === null) unset($updatedData['medicalPersonelName']);
            if ($updatedData['createdName'] === null) unset($updatedData['createdName']);
            if ($updatedData['updatedName'] === null) unset($updatedData['updatedName']);
        }

        // --- 21. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Data hasil observasi berhasil diperbarui",
                "code" => 200
            ],
            "metadata" => [
                "observationResumeId" => $observationResumeId,
                "satuSehatCode" => $satusehatCode,
                "satusehat_sync" => [
                    "status" => $satusehatSyncStatus,
                    "message" => $satusehatMessage
                ],
                "updated_at" => $nowUtc . ' GMT'
            ],
            "data" => $updatedData
        ]);

    } catch (PDOException $e) {
        error_log('[UpdateObservation] Fetch updated error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }
?>