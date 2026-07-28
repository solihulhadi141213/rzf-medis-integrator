<?php
    /**
     * Update Procedure Reference
     * Endpoint: PUT /_API/Reference/Procedure/UpdateProcedureReference.php?procedureReferenceId={id}
     * Header: token, account_token
     * Body: JSON (lihat contoh)
     *
     * - Semua field opsional, yang tidak dikirim tetap mempertahankan nilai lama.
     * - Jika procedureCode diubah, validasi duplikat (kecuali dirinya sendiri).
     * - status=0 untuk soft delete (tidak aktif), status=1 aktif.
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
    $Limiter->check("update_procedure_reference", 5, 60);

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

    // --- 6. Validasi Parameter procedureReferenceId ---
    if (!isset($_GET['procedureReferenceId']) || !is_numeric($_GET['procedureReferenceId']) || (int)$_GET['procedureReferenceId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter procedureReferenceId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $procedureReferenceId = (int) $_GET['procedureReferenceId'];

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_procedure_reference' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah referensi tindakan", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateProcedureReference] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data existing ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM procedure_reference WHERE procedureReferenceId = :id LIMIT 1");
        $stmt->execute([':id' => $procedureReferenceId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Referensi tindakan tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateProcedureReference] Fetch existing error: ' . $e->getMessage());
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
    $procedureCategoryName       = isset($input['procedureCategoryName']) ? trim($input['procedureCategoryName']) : $existingData['procedureCategoryName'];
    $procedureCategoryCode       = isset($input['procedureCategoryCode']) ? trim($input['procedureCategoryCode']) : $existingData['procedureCategoryCode'];
    $procedureCategoryDipsplay   = isset($input['procedureCategoryDipsplay']) ? trim($input['procedureCategoryDipsplay']) : $existingData['procedureCategoryDipsplay'];
    $procedureCategorySystem     = isset($input['procedureCategorySystem']) ? trim($input['procedureCategorySystem']) : $existingData['procedureCategorySystem'];
    $procedureName               = isset($input['procedureName']) ? trim($input['procedureName']) : $existingData['procedureName'];
    $procedureCode               = isset($input['procedureCode']) ? trim($input['procedureCode']) : $existingData['procedureCode'];
    $procedureDisplay            = isset($input['procedureDisplay']) ? trim($input['procedureDisplay']) : $existingData['procedureDisplay'];
    $procedureSystem             = isset($input['procedureSystem']) ? trim($input['procedureSystem']) : $existingData['procedureSystem'];
    $bodySiteName                = isset($input['bodySiteName']) ? trim($input['bodySiteName']) : $existingData['bodySiteName'];
    $bodySiteCode                = isset($input['bodySiteCode']) ? trim($input['bodySiteCode']) : $existingData['bodySiteCode'];
    $bodySiteDisplay             = isset($input['bodySiteDisplay']) ? trim($input['bodySiteDisplay']) : $existingData['bodySiteDisplay'];
    $bodySiteSystem              = isset($input['bodySiteSystem']) ? trim($input['bodySiteSystem']) : $existingData['bodySiteSystem'];
    $icd9Code                    = isset($input['icd9Code']) ? trim($input['icd9Code']) : $existingData['icd9Code'];
    $icd9Description             = isset($input['icd9Description']) ? trim($input['icd9Description']) : $existingData['icd9Description'];
    $status                      = isset($input['status']) ? (int) $input['status'] : (int) $existingData['status'];

    // --- 11. Validasi Field Wajib (jika diubah) ---
    // Karena PUT, jika ada field yang kosong dan sebelumnya tidak kosong, bisa jadi error. Tapi kita tidak wajibkan semua field.
    // Namun, untuk field yang diisi, harus valid.
    // Kita hanya validasi jika field diisi, misalnya procedureCategoryName tidak boleh kosong jika dikirim.
    if (isset($input['procedureCategoryName']) && empty($procedureCategoryName)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "procedureCategoryName tidak boleh kosong jika dikirim", "code" => 422], "metadata" => []]);
        exit;
    }
    if (isset($input['procedureName']) && empty($procedureName)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "procedureName tidak boleh kosong jika dikirim", "code" => 422], "metadata" => []]);
        exit;
    }
    if (isset($input['bodySiteName']) && empty($bodySiteName)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "bodySiteName tidak boleh kosong jika dikirim", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi status ---
    if (!in_array($status, [0, 1], true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "status hanya boleh 0 atau 1", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 13. Validasi duplikat procedureCode (jika diubah) ---
    if ($procedureCode !== $existingData['procedureCode'] && !empty($procedureCode)) {
        try {
            $stmt = $Conn->prepare("SELECT procedureReferenceId FROM procedure_reference WHERE procedureCode = :code AND procedureReferenceId != :id LIMIT 1");
            $stmt->execute([':code' => $procedureCode, ':id' => $procedureReferenceId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(["response" => ["message" => "procedureCode sudah digunakan oleh referensi lain", "code" => 409], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateProcedureReference] Check procedureCode error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 14. Update Data ---
    try {
        $updatedDate = $nowUtc;

        $sql = "UPDATE procedure_reference SET
                    procedureCategoryName = :procedureCategoryName,
                    procedureCategoryCode = :procedureCategoryCode,
                    procedureCategoryDipsplay = :procedureCategoryDipsplay,
                    procedureCategorySystem = :procedureCategorySystem,
                    procedureName = :procedureName,
                    procedureCode = :procedureCode,
                    procedureDisplay = :procedureDisplay,
                    procedureSystem = :procedureSystem,
                    bodySiteName = :bodySiteName,
                    bodySiteCode = :bodySiteCode,
                    bodySiteDisplay = :bodySiteDisplay,
                    bodySiteSystem = :bodySiteSystem,
                    icd9Code = :icd9Code,
                    icd9Description = :icd9Description,
                    status = :status,
                    updateAt = :updateAt,
                    updateBy = :updateBy
                WHERE procedureReferenceId = :id";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':procedureCategoryName' => $procedureCategoryName,
            ':procedureCategoryCode' => $procedureCategoryCode,
            ':procedureCategoryDipsplay' => $procedureCategoryDipsplay,
            ':procedureCategorySystem' => $procedureCategorySystem,
            ':procedureName' => $procedureName,
            ':procedureCode' => $procedureCode,
            ':procedureDisplay' => $procedureDisplay,
            ':procedureSystem' => $procedureSystem,
            ':bodySiteName' => $bodySiteName,
            ':bodySiteCode' => $bodySiteCode,
            ':bodySiteDisplay' => $bodySiteDisplay,
            ':bodySiteSystem' => $bodySiteSystem,
            ':icd9Code' => $icd9Code,
            ':icd9Description' => $icd9Description,
            ':status' => $status,
            ':updateAt' => $updatedDate,
            ':updateBy' => $loggedInAccountId,
            ':id' => $procedureReferenceId
        ]);

        // --- 15. Ambil data terbaru untuk response ---
        $stmt = $Conn->prepare("
            SELECT pr.*,
                ca.name AS createdName,
                ua.name AS updatedName
            FROM procedure_reference pr
            LEFT JOIN account ca ON pr.creatBy = ca.accountId
            LEFT JOIN account ua ON pr.updateBy = ua.accountId
            WHERE pr.procedureReferenceId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $procedureReferenceId]);
        $updatedData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($updatedData) {
            $updatedData['procedureReferenceId'] = (int) $updatedData['procedureReferenceId'];
            $updatedData['status'] = (int) $updatedData['status'];
            $updatedData['creatBy'] = $updatedData['creatBy'] !== null ? (int) $updatedData['creatBy'] : null;
            $updatedData['updateBy'] = $updatedData['updateBy'] !== null ? (int) $updatedData['updateBy'] : null;
            if ($updatedData['createdName'] === null) unset($updatedData['createdName']);
            if ($updatedData['updatedName'] === null) unset($updatedData['updatedName']);
        }

        // --- 16. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Referensi tindakan berhasil diperbarui",
                "code" => 200
            ],
            "metadata" => [
                "procedureReferenceId" => $procedureReferenceId,
                "updated_at" => $updatedDate . ' GMT'
            ],
            "data" => $updatedData
        ]);

    } catch (PDOException $e) {
        error_log('[UpdateProcedureReference] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>