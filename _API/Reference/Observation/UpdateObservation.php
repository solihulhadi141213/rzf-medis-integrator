<?php
    /**
     * Update Observation Reference
     * Endpoint: PUT /_API/Reference/Observation/UpdateObservation.php?observationReferenceId={id}
     * Header: token, account_token
     * Body: JSON {
     *     "categoryName": "Tanda Vital",
     *     "categoryCode": "vital-signs",
     *     "categoryDisplay": "Vital Signs",
     *     "categorySystem": "http://terminology.hl7.org/CodeSystem/observation-category",
     *     "observationName": "Tekanan Darah Sistol",
     *     "observationCode": "8480-6",
     *     "observationDisplay": "Systolic blood pressure",
     *     "observationSystem": "http://loinc.org",
     *     "unitReferenceId": 6,
     *     "resultType": "Decimal",
     *     "allowSex": false,
     *     "allowAge": false
     * }
     *
     * - Hanya update data utama observation_reference, tidak mengubah child tables.
     * - Field opsional: jika tidak dikirim, nilai tetap dipertahankan.
     * - unitReferenceId boleh null/0, jika diisi harus valid.
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
    include "../../../_Config/Connection.php";
    include "../../../_Config/Helper.php";
    require "../../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("update_observation_reference", 5, 60);

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

    // --- 6. Validasi Parameter observationReferenceId ---
    if (!isset($_GET['observationReferenceId']) || !is_numeric($_GET['observationReferenceId']) || (int)$_GET['observationReferenceId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter observationReferenceId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $observationReferenceId = (int) $_GET['observationReferenceId'];

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_observation_reference' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah referensi pemeriksaan", "code" => 403], "metadata" => []]);
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
        $stmt = $Conn->prepare("SELECT * FROM observation_reference WHERE observationReferenceId = :id LIMIT 1");
        $stmt->execute([':id' => $observationReferenceId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Data referensi pemeriksaan tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
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
    $categoryName   = isset($input['categoryName']) ? trim($input['categoryName']) : $existingData['categoryName'];
    $categoryCode   = isset($input['categoryCode']) ? trim($input['categoryCode']) : $existingData['categoryCode'];
    $categoryDisplay = isset($input['categoryDisplay']) ? trim($input['categoryDisplay']) : $existingData['categoryDisplay'];
    $categorySystem = isset($input['categorySystem']) ? trim($input['categorySystem']) : $existingData['categorySystem'];
    $observationName = isset($input['observationName']) ? trim($input['observationName']) : $existingData['observationName'];
    $observationCode = isset($input['observationCode']) ? trim($input['observationCode']) : $existingData['observationCode'];
    $observationDisplay = isset($input['observationDisplay']) ? trim($input['observationDisplay']) : $existingData['observationDisplay'];
    $observationSystem = isset($input['observationSystem']) ? trim($input['observationSystem']) : $existingData['observationSystem'];
    $unitReferenceId = isset($input['unitReferenceId']) && $input['unitReferenceId'] !== '' ? (int) $input['unitReferenceId'] : 0;
    $resultType     = isset($input['resultType']) ? trim($input['resultType']) : $existingData['resultType'];
    $allowSex       = isset($input['allowSex']) ? (int) $input['allowSex'] : (int) $existingData['allowSex'];
    $allowAge       = isset($input['allowAge']) ? (int) $input['allowAge'] : (int) $existingData['allowAge'];
    $status         = isset($input['status']) ? (int) $input['status'] : (int) $existingData['status'];

    // --- 11. Validasi Field Wajib (field yang wajib diisi) ---
    $requiredFields = ['categoryName', 'observationName', 'resultType'];
    foreach ($requiredFields as $field) {
        if (empty($$field)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Field '$field' wajib diisi", "code" => 422], "metadata" => []]);
            exit;
        }
    }
    if ($unitReferenceId < 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "unitReferenceId tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }
    if (!in_array($allowSex, [0, 1], true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "allowSex hanya boleh 0 atau 1", "code" => 422], "metadata" => []]);
        exit;
    }
    if (!in_array($allowAge, [0, 1], true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "allowAge hanya boleh 0 atau 1", "code" => 422], "metadata" => []]);
        exit;
    }
    if (!in_array($status, [0, 1], true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "status hanya boleh 0 atau 1", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi resultType ---
    $allowedResultTypes = ['Numeric', 'Decimal', 'Coded', 'Text'];
    if (!in_array($resultType, $allowedResultTypes, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "resultType harus salah satu dari: " . implode(', ', $allowedResultTypes),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 13. Validasi unitReferenceId (jika > 0) ---
    $unitData = null;
    if ($unitReferenceId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT unitName, unitCode, unitDisplay, unitSystem FROM unit_reference WHERE unitReferenceId = :id LIMIT 1");
            $stmt->execute([':id' => $unitReferenceId]);
            $unitData = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$unitData) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "unitReferenceId tidak ditemukan", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateObservation] Check unitReferenceId error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    } else {
        $unitData = null; // tidak ada unit
    }

    // --- 14. Validasi allowAge & allowSex terhadap existing data anak (tidak diubah, hanya peringatan) ---
    // Tidak perlu validasi ketat, karena child table tidak diupdate di sini.
    // Bisa saja user mengubah allowAge dari true ke false, namun child data tetap ada, itu dibiarkan.

    // --- 15. Update Data ---
    try {
        $updatedDate = $nowUtc;

        $sql = "UPDATE `observation_reference` SET
                    `categoryName` = :categoryName,
                    `categoryCode` = :categoryCode,
                    `categoryDisplay` = :categoryDisplay,
                    `categorySystem` = :categorySystem,
                    `observationName` = :observationName,
                    `observationCode` = :observationCode,
                    `observationDisplay` = :observationDisplay,
                    `observationSystem` = :observationSystem,
                    `unitName` = :unitName,
                    `unitCode` = :unitCode,
                    `unitDisplay` = :unitDisplay,
                    `unitSystem` = :unitSystem,
                    `resultType` = :resultType,
                    `allowSex` = :allowSex,
                    `allowAge` = :allowAge,
                    `status` = :status,
                    `updateAt` = :updateAt,
                    `updateBy` = :updateBy
                WHERE `observationReferenceId` = :id";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':categoryName' => $categoryName,
            ':categoryCode' => $categoryCode,
            ':categoryDisplay' => $categoryDisplay,
            ':categorySystem' => $categorySystem,
            ':observationName' => $observationName,
            ':observationCode' => $observationCode,
            ':observationDisplay' => $observationDisplay,
            ':observationSystem' => $observationSystem,
            ':unitName' => $unitData ? $unitData['unitName'] : null,
            ':unitCode' => $unitData ? $unitData['unitCode'] : null,
            ':unitDisplay' => $unitData ? $unitData['unitDisplay'] : null,
            ':unitSystem' => $unitData ? $unitData['unitSystem'] : null,
            ':resultType' => $resultType,
            ':allowSex' => $allowSex,
            ':allowAge' => $allowAge,
            ':status' => $status,
            ':updateAt' => $updatedDate,
            ':updateBy' => $loggedInAccountId,
            ':id' => $observationReferenceId
        ]);

        // --- 16. Ambil data terbaru untuk response ---
        $stmt = $Conn->prepare("
            SELECT * FROM observation_reference WHERE observationReferenceId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $observationReferenceId]);
        $updatedData = $stmt->fetch(PDO::FETCH_ASSOC);
        $updatedData['observationReferenceId'] = (int) $updatedData['observationReferenceId'];
        $updatedData['allowSex'] = (int) $updatedData['allowSex'];
        $updatedData['allowAge'] = (int) $updatedData['allowAge'];
        $updatedData['status'] = (int) $updatedData['status'];
        $updatedData['creatBy'] = $updatedData['creatBy'] !== null ? (int) $updatedData['creatBy'] : null;
        $updatedData['updateBy'] = $updatedData['updateBy'] !== null ? (int) $updatedData['updateBy'] : null;

        // --- 17. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Referensi pemeriksaan berhasil diperbarui",
                "code" => 200
            ],
            "metadata" => [
                "observationReferenceId" => $observationReferenceId,
                "updated_at" => $updatedDate . ' GMT'
            ],
            "data" => $updatedData
        ]);

    } catch (PDOException $e) {
        error_log('[UpdateObservation] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>