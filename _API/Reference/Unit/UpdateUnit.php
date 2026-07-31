<?php
    /**
     * Update Unit Reference
     * Endpoint: POST /_API/Reference/Unit/UpdateUnit.php?unitReferenceId={id}
     * Header: token, account_token
     * Body: JSON {
     *     "unitName": "Miligram per Desiliter",
     *     "unitDisplay": "mg/dL",
     *     "unitCode": "mg/dL",
     *     "unitSystem": "http://unitsofmeasure.org"
     * }
     *
     * - Validasi mandatory: unitName, unitDisplay, unitCode, unitSystem.
     * - Validasi duplikat unitCode (kecuali dirinya sendiri).
     * - Tidak ada integrasi SATUSEHAT.
     * - Field yang tidak dikirim akan mempertahankan nilai lama.
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
    include "../../../_Config/Connection.php";
    include "../../../_Config/Helper.php";
    require "../../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("update_unit_reference", 5, 60);

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

    // --- 6. Validasi Parameter unitReferenceId ---
    if (!isset($_GET['unitReferenceId']) || !is_numeric($_GET['unitReferenceId']) || (int)$_GET['unitReferenceId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter unitReferenceId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $unitReferenceId = (int) $_GET['unitReferenceId'];

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_unit_reference' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah satuan", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateUnit] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data existing ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM unit_reference WHERE unitReferenceId = :id LIMIT 1");
        $stmt->execute([':id' => $unitReferenceId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Data satuan tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateUnit] Fetch existing error: ' . $e->getMessage());
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
    $unitName    = isset($input['unitName']) ? trim($input['unitName']) : $existingData['unitName'];
    $unitDisplay = isset($input['unitDisplay']) ? trim($input['unitDisplay']) : $existingData['unitDisplay'];
    $unitCode    = isset($input['unitCode']) ? trim($input['unitCode']) : $existingData['unitCode'];
    $unitSystem  = isset($input['unitSystem']) ? trim($input['unitSystem']) : $existingData['unitSystem'];

    // --- 11. Validasi Field Wajib ---
    $requiredFields = ['unitName', 'unitDisplay', 'unitCode', 'unitSystem'];
    foreach ($requiredFields as $field) {
        if (empty($$field)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Field '$field' wajib diisi", "code" => 422], "metadata" => []]);
            exit;
        }
    }

    // --- 12. Validasi duplikat unitCode (jika berubah) ---
    if ($unitCode !== $existingData['unitCode']) {
        try {
            $stmt = $Conn->prepare("SELECT unitReferenceId FROM unit_reference WHERE unitCode = :code AND unitReferenceId != :id LIMIT 1");
            $stmt->execute([':code' => $unitCode, ':id' => $unitReferenceId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(["response" => ["message" => "unitCode sudah digunakan oleh satuan lain", "code" => 409], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateUnit] Check unitCode error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 13. Update Data ---
    try {
        $sql = "UPDATE unit_reference SET
                    unitName = :unitName,
                    unitDisplay = :unitDisplay,
                    unitCode = :unitCode,
                    unitSystem = :unitSystem
                WHERE unitReferenceId = :id";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':unitName' => $unitName,
            ':unitDisplay' => $unitDisplay,
            ':unitCode' => $unitCode,
            ':unitSystem' => $unitSystem,
            ':id' => $unitReferenceId
        ]);

        // --- 14. Ambil data terbaru ---
        $stmt = $Conn->prepare("SELECT * FROM unit_reference WHERE unitReferenceId = :id LIMIT 1");
        $stmt->execute([':id' => $unitReferenceId]);
        $updatedData = $stmt->fetch(PDO::FETCH_ASSOC);
        $updatedData['unitReferenceId'] = (int) $updatedData['unitReferenceId'];

        // --- 15. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Satuan berhasil diperbarui",
                "code" => 200
            ],
            "metadata" => [
                "unitReferenceId" => $unitReferenceId
            ],
            "data" => $updatedData
        ]);

    } catch (PDOException $e) {
        error_log('[UpdateUnit] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>