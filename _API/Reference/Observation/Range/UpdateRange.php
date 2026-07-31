<?php
    /**
     * Update Observation Reference Range
     * Endpoint: PUT /_API/Reference/Observation/Range/UpdateRange.php?observationResultRangeId={id}
     * Header: token, account_token
     * Body: JSON {
     *     "observationReferenceAgeId": null,
     *     "groupGender": "All",
     *     "minValue": null,
     *     "maxValue": 90,
     *     "rangeOperator": "Less",
     *     "InterpertationLabel": "Rendah",
     *     "InterpertationDisplay": "Low",
     *     "InterpertationCode": "L",
     *     "InterpertationSystem": "http://terminology.hl7.org/CodeSystem/v3-ObservationInterpretation",
     *     "InterpertationConclusion": "Hipotensi",
     *     "normalResult": 0
     * }
     *
     * - Validasi observationResultRangeId ada.
     * - observationReferenceAgeId opsional, jika diisi harus valid dan terkait dengan reference yang sama.
     * - groupGender enum, rangeOperator enum.
     * - InterpertationLabel dan InterpertationDisplay wajib.
     * - normalResult 0/1.
     * - Update ke observation_reference_range.
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
    include "../../../../_Config/Connection.php";
    include "../../../../_Config/Helper.php";
    require "../../../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("update_range", 5, 60);

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

    // --- 6. Validasi Parameter observationResultRangeId ---
    if (!isset($_GET['observationResultRangeId']) || !is_numeric($_GET['observationResultRangeId']) || (int)$_GET['observationResultRangeId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter observationResultRangeId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $observationResultRangeId = (int) $_GET['observationResultRangeId'];

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_range' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah range value", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateRange] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data existing range ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM observation_reference_range WHERE observationResultRangeId = :id LIMIT 1");
        $stmt->execute([':id' => $observationResultRangeId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Data range tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
        $observationReferenceId = (int) $existingData['observationReferenceId'];
    } catch (PDOException $e) {
        error_log('[UpdateRange] Fetch existing error: ' . $e->getMessage());
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
    $observationReferenceAgeId = isset($input['observationReferenceAgeId']) && $input['observationReferenceAgeId'] !== null && $input['observationReferenceAgeId'] !== '' ? (int) $input['observationReferenceAgeId'] : null;
    $groupGender = isset($input['groupGender']) ? trim($input['groupGender']) : $existingData['groupGender'];
    $minValue = isset($input['minValue']) && $input['minValue'] !== '' ? (float) $input['minValue'] : null;
    $maxValue = isset($input['maxValue']) && $input['maxValue'] !== '' ? (float) $input['maxValue'] : null;
    $rangeOperator = isset($input['rangeOperator']) ? trim($input['rangeOperator']) : $existingData['rangeOperator'];
    $InterpertationLabel = isset($input['InterpertationLabel']) ? trim($input['InterpertationLabel']) : $existingData['InterpertationLabel'];
    $InterpertationDisplay = isset($input['InterpertationDisplay']) ? trim($input['InterpertationDisplay']) : $existingData['InterpertationDisplay'];
    $InterpertationCode = isset($input['InterpertationCode']) ? trim($input['InterpertationCode']) : $existingData['InterpertationCode'];
    $InterpertationSystem = isset($input['InterpertationSystem']) ? trim($input['InterpertationSystem']) : $existingData['InterpertationSystem'];
    $InterpertationConclusion = isset($input['InterpertationConclusion']) ? trim($input['InterpertationConclusion']) : $existingData['InterpertationConclusion'];
    $normalResult = isset($input['normalResult']) ? (int) $input['normalResult'] : (int) $existingData['normalResult'];

    // --- 11. Validasi Field Wajib ---
    if (empty($InterpertationLabel)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "InterpertationLabel wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($InterpertationDisplay)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "InterpertationDisplay wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi groupGender ---
    $allowedGender = ['Male', 'Female', 'All'];
    if (!in_array($groupGender, $allowedGender, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "groupGender harus salah satu dari: " . implode(', ', $allowedGender),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 13. Validasi rangeOperator ---
    $allowedOperators = ['Less', 'More', 'Between'];
    if (!in_array($rangeOperator, $allowedOperators, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "rangeOperator harus salah satu dari: " . implode(', ', $allowedOperators),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 14. Validasi normalResult ---
    if (!in_array($normalResult, [0, 1], true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "normalResult hanya boleh 0 atau 1", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 15. Validasi logika minValue/maxValue berdasarkan rangeOperator ---
    if ($rangeOperator === 'Between' && ($minValue === null || $maxValue === null)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "Untuk range operator Between, minValue dan maxValue harus diisi", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($rangeOperator === 'Less' && $maxValue === null) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "Untuk range operator Less, maxValue harus diisi", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($rangeOperator === 'More' && $minValue === null) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "Untuk range operator More, minValue harus diisi", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($minValue !== null && $maxValue !== null && $minValue > $maxValue) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "minValue tidak boleh lebih besar dari maxValue", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 16. Validasi observationReferenceAgeId (jika diisi) ---
    if ($observationReferenceAgeId !== null) {
        try {
            $stmt = $Conn->prepare("SELECT observationReferenceAgeId FROM observation_reference_age WHERE observationReferenceAgeId = :id AND observationReferenceId = :refId LIMIT 1");
            $stmt->execute([':id' => $observationReferenceAgeId, ':refId' => $observationReferenceId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "observationReferenceAgeId tidak ditemukan atau tidak terkait dengan observation ini", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateRange] Check observationReferenceAgeId error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 17. Update data ---
    try {
        $sql = "UPDATE `observation_reference_range` SET
                    `observationReferenceAgeId` = :observationReferenceAgeId,
                    `groupGender` = :groupGender,
                    `minValue` = :minValue,
                    `maxValue` = :maxValue,
                    `rangeOperator` = :rangeOperator,
                    `InterpertationLabel` = :InterpertationLabel,
                    `InterpertationDisplay` = :InterpertationDisplay,
                    `InterpertationCode` = :InterpertationCode,
                    `InterpertationSystem` = :InterpertationSystem,
                    `InterpertationConclusion` = :InterpertationConclusion,
                    `normalResult` = :normalResult
                WHERE `observationResultRangeId` = :id";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':observationReferenceAgeId' => $observationReferenceAgeId,
            ':groupGender' => $groupGender,
            ':minValue' => $minValue,
            ':maxValue' => $maxValue,
            ':rangeOperator' => $rangeOperator,
            ':InterpertationLabel' => $InterpertationLabel,
            ':InterpertationDisplay' => $InterpertationDisplay,
            ':InterpertationCode' => $InterpertationCode,
            ':InterpertationSystem' => $InterpertationSystem,
            ':InterpertationConclusion' => $InterpertationConclusion,
            ':normalResult' => $normalResult,
            ':id' => $observationResultRangeId
        ]);

        // --- 18. Ambil data terbaru untuk response ---
        $stmt = $Conn->prepare("SELECT * FROM observation_reference_range WHERE observationResultRangeId = :id LIMIT 1");
        $stmt->execute([':id' => $observationResultRangeId]);
        $updatedData = $stmt->fetch(PDO::FETCH_ASSOC);
        $updatedData['observationResultRangeId'] = (int) $updatedData['observationResultRangeId'];
        $updatedData['observationReferenceId'] = (int) $updatedData['observationReferenceId'];
        $updatedData['observationReferenceAgeId'] = $updatedData['observationReferenceAgeId'] !== null ? (int) $updatedData['observationReferenceAgeId'] : null;
        $updatedData['normalResult'] = (int) $updatedData['normalResult'];
        $updatedData['minValue'] = $updatedData['minValue'] !== null ? (float) $updatedData['minValue'] : null;
        $updatedData['maxValue'] = $updatedData['maxValue'] !== null ? (float) $updatedData['maxValue'] : null;

        // --- 19. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Range value berhasil diperbarui",
                "code" => 200
            ],
            "metadata" => [
                "observationResultRangeId" => $observationResultRangeId,
                "updated_at" => $nowUtc . ' GMT'
            ],
            "data" => $updatedData
        ]);

    } catch (PDOException $e) {
        error_log('[UpdateRange] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>