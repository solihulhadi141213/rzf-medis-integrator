<?php
    /**
     * Update Observation Reference Age Group
     * Endpoint: PUT /_API/Reference/Observation/AgeGroup/UpdateAgeGroup.php?observationReferenceAgeId={id}
     * Header: token, account_token
     * Body: JSON {
     *     "ageCategory": "Bayi",
     *     "ageMin": 0,
     *     "ageMax": 1,
     *     "ageUnit": "Year"
     * }
     *
     * - Validasi observationReferenceAgeId ada.
     * - Validasi mandatory: ageCategory, ageMin, ageUnit (ageMax optional).
     * - ageMin >= 0, ageUnit enum.
     * - Update ke observation_reference_age.
     * - Tidak ada integrasi SATUSEHAT.
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
    $Limiter->check("update_age_group", 5, 60);

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

    // --- 6. Validasi Parameter observationReferenceAgeId ---
    if (!isset($_GET['observationReferenceAgeId']) || !is_numeric($_GET['observationReferenceAgeId']) || (int)$_GET['observationReferenceAgeId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter observationReferenceAgeId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $observationReferenceAgeId = (int) $_GET['observationReferenceAgeId'];

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_age_group' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah kelompok usia", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateAgeGroup] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data existing ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM observation_reference_age WHERE observationReferenceAgeId = :id LIMIT 1");
        $stmt->execute([':id' => $observationReferenceAgeId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Data kelompok usia tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
        // Simpan observationReferenceId untuk validasi opsional
        $observationReferenceId = (int) $existingData['observationReferenceId'];
    } catch (PDOException $e) {
        error_log('[UpdateAgeGroup] Fetch existing error: ' . $e->getMessage());
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
    $ageCategory = isset($input['ageCategory']) ? trim($input['ageCategory']) : $existingData['ageCategory'];
    $ageMin = isset($input['ageMin']) && $input['ageMin'] !== '' ? (int) $input['ageMin'] : (int) $existingData['ageMin'];
    $ageMax = isset($input['ageMax']) && $input['ageMax'] !== '' ? (int) $input['ageMax'] : $existingData['ageMax'];
    $ageUnit = isset($input['ageUnit']) ? trim($input['ageUnit']) : $existingData['ageUnit'];

    // --- 11. Validasi Field Wajib ---
    if (empty($ageCategory)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "ageCategory wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($ageMin < 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "ageMin minimal 0", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($ageUnit)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "ageUnit wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi ageUnit ---
    $allowedUnits = ['Year', 'Month', 'Day'];
    if (!in_array($ageUnit, $allowedUnits, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "ageUnit harus salah satu dari: " . implode(', ', $allowedUnits),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 13. Validasi ageMax (jika diisi) ---
    if ($ageMax !== null && $ageMin > $ageMax) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "ageMin tidak boleh lebih besar dari ageMax", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 14. Update data ---
    try {
        $sql = "UPDATE `observation_reference_age` SET
                    `ageCategory` = :ageCategory,
                    `ageMin` = :ageMin,
                    `ageMax` = :ageMax,
                    `ageUnit` = :ageUnit
                WHERE `observationReferenceAgeId` = :id";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':ageCategory' => $ageCategory,
            ':ageMin' => $ageMin,
            ':ageMax' => $ageMax,
            ':ageUnit' => $ageUnit,
            ':id' => $observationReferenceAgeId
        ]);

        // --- 15. Ambil data terbaru untuk response ---
        $stmt = $Conn->prepare("SELECT * FROM observation_reference_age WHERE observationReferenceAgeId = :id LIMIT 1");
        $stmt->execute([':id' => $observationReferenceAgeId]);
        $updatedData = $stmt->fetch(PDO::FETCH_ASSOC);
        $updatedData['observationReferenceAgeId'] = (int) $updatedData['observationReferenceAgeId'];
        $updatedData['observationReferenceId'] = (int) $updatedData['observationReferenceId'];
        $updatedData['ageMin'] = (int) $updatedData['ageMin'];
        $updatedData['ageMax'] = $updatedData['ageMax'] !== null ? (int) $updatedData['ageMax'] : null;

        // --- 16. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Kelompok usia berhasil diperbarui",
                "code" => 200
            ],
            "metadata" => [
                "observationReferenceAgeId" => $observationReferenceAgeId,
                "updated_at" => $nowUtc . ' GMT'
            ],
            "data" => $updatedData
        ]);

    } catch (PDOException $e) {
        error_log('[UpdateAgeGroup] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>