<?php
    /**
     * Update Medication Numerator Reference
     * Endpoint: PUT /_API/Reference/MedicationNumerator/UpdateMedicationNumerator.php?medicationNumeratorId={id}
     * Header: token, account_token
     * Body: JSON {
     *     "medicationNumeratorName": "Miligram",
     *     "medicationNumeratorUnit": "miligram",
     *     "medicationNumeratorCode": "mg",
     *     "medicationNumeratorSystem": "http://unitsofmeasure.org"
     * }
     *
     * - Validasi mandatory.
     * - Jika medicationNumeratorCode diubah, cek duplikat (kecuali dirinya sendiri).
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
    include "../../../_Config/Connection.php";
    include "../../../_Config/Helper.php";
    require "../../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("update_medication_numerator", 5, 60);

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

    // --- 6. Validasi Parameter medicationNumeratorId ---
    if (!isset($_GET['medicationNumeratorId']) || !is_numeric($_GET['medicationNumeratorId']) || (int)$_GET['medicationNumeratorId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter medicationNumeratorId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $medicationNumeratorId = (int) $_GET['medicationNumeratorId'];

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_medication_numerator' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah referensi numerator", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateMedicationNumerator] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data existing ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM medication_numerator WHERE medicationNumeratorId = :id LIMIT 1");
        $stmt->execute([':id' => $medicationNumeratorId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Referensi numerator tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateMedicationNumerator] Fetch existing error: ' . $e->getMessage());
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
    $medicationNumeratorName = isset($input['medicationNumeratorName']) ? trim($input['medicationNumeratorName']) : $existingData['medicationNumeratorName'];
    $medicationNumeratorUnit = isset($input['medicationNumeratorUnit']) ? trim($input['medicationNumeratorUnit']) : $existingData['medicationNumeratorUnit'];
    $medicationNumeratorCode = isset($input['medicationNumeratorCode']) ? trim($input['medicationNumeratorCode']) : $existingData['medicationNumeratorCode'];
    $medicationNumeratorSystem = isset($input['medicationNumeratorSystem']) ? trim($input['medicationNumeratorSystem']) : $existingData['medicationNumeratorSystem'];

    // --- 11. Validasi Field Wajib ---
    $required = ['medicationNumeratorName', 'medicationNumeratorUnit', 'medicationNumeratorCode', 'medicationNumeratorSystem'];
    foreach ($required as $field) {
        if (empty($$field)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Field '$field' wajib diisi", "code" => 422], "metadata" => []]);
            exit;
        }
    }

    // --- 12. Validasi duplikat medicationNumeratorCode (jika berubah) ---
    if ($medicationNumeratorCode !== $existingData['medicationNumeratorCode']) {
        try {
            $stmt = $Conn->prepare("SELECT medicationNumeratorId FROM medication_numerator WHERE medicationNumeratorCode = :code AND medicationNumeratorId != :id LIMIT 1");
            $stmt->execute([':code' => $medicationNumeratorCode, ':id' => $medicationNumeratorId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(["response" => ["message" => "medicationNumeratorCode sudah digunakan oleh data lain", "code" => 409], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateMedicationNumerator] Check duplicate error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 13. Update Data ---
    try {
        $sql = "UPDATE medication_numerator SET
                    medicationNumeratorName = :medicationNumeratorName,
                    medicationNumeratorUnit = :medicationNumeratorUnit,
                    medicationNumeratorCode = :medicationNumeratorCode,
                    medicationNumeratorSystem = :medicationNumeratorSystem
                WHERE medicationNumeratorId = :id";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':medicationNumeratorName' => $medicationNumeratorName,
            ':medicationNumeratorUnit' => $medicationNumeratorUnit,
            ':medicationNumeratorCode' => $medicationNumeratorCode,
            ':medicationNumeratorSystem' => $medicationNumeratorSystem,
            ':id' => $medicationNumeratorId
        ]);

        // --- 14. Ambil data terbaru untuk response ---
        $stmt = $Conn->prepare("SELECT * FROM medication_numerator WHERE medicationNumeratorId = :id LIMIT 1");
        $stmt->execute([':id' => $medicationNumeratorId]);
        $updatedData = $stmt->fetch(PDO::FETCH_ASSOC);
        $updatedData['medicationNumeratorId'] = (int) $updatedData['medicationNumeratorId'];

        // --- 15. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Referensi numerator berhasil diperbarui",
                "code" => 200
            ],
            "metadata" => [
                "medicationNumeratorId" => $medicationNumeratorId,
                "updated_at" => $nowUtc . ' GMT'
            ],
            "data" => $updatedData
        ]);

    } catch (PDOException $e) {
        error_log('[UpdateMedicationNumerator] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>