<?php
    /**
     * Update Procedure Performer
     * Endpoint: PUT /_API/Procedure/Performer/UpdatePerformer.php?procedurePerformerId={id}
     * Header: token, account_token
     * Body: JSON {
     *     "medicalPersonelId": 5,
     *     "performerType": "Assistant",
     *     "performerNote": "Tindakan berjalan dengan baik"
     * }
     *
     * - Validasi procedurePerformerId, medicalPersonelId, performerType.
     * - Mengupdate id_practitioner, performerNik, performerName dari medical_personel.
     * - Field yang tidak dikirim akan mempertahankan nilai lama.
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
    $Limiter->check("update_procedure_performer", 5, 60);

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

    // --- 6. Validasi Parameter procedurePerformerId ---
    if (!isset($_GET['procedurePerformerId']) || !is_numeric($_GET['procedurePerformerId']) || (int)$_GET['procedurePerformerId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter procedurePerformerId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $procedurePerformerId = (int) $_GET['procedurePerformerId'];

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'update_procedure_performer' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk mengubah performer tindakan", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdatePerformer] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil data performer existing ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM procedure_performer WHERE procedurePerformerId = :id LIMIT 1");
        $stmt->execute([':id' => $procedurePerformerId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Data performer tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }
        $procedureId = $existingData['procedureId'];
    } catch (PDOException $e) {
        error_log('[UpdatePerformer] Fetch existing error: ' . $e->getMessage());
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
    $medicalPersonelId = isset($input['medicalPersonelId']) ? (int) $input['medicalPersonelId'] : (int) $existingData['medicalPersonelId'];
    $performerType = isset($input['performerType']) ? trim($input['performerType']) : $existingData['performerType'];
    $performerNote = isset($input['performerNote']) ? trim($input['performerNote']) : $existingData['performerNote'];

    // --- 11. Validasi Field ---
    if ($medicalPersonelId <= 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "medicalPersonelId harus diisi dengan angka positif", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($performerType)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "performerType wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi performerType ---
    $allowedTypes = ['Primary', 'Assistant'];
    if (!in_array($performerType, $allowedTypes, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "performerType harus salah satu dari: " . implode(', ', $allowedTypes),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 13. Validasi medicalPersonelId ---
    try {
        $stmt = $Conn->prepare("SELECT medicalPersonelId, name, nik, id_practitioner FROM medical_personel WHERE medicalPersonelId = :id AND status = 1 LIMIT 1");
        $stmt->execute([':id' => $medicalPersonelId]);
        $practitionerData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$practitionerData) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "medicalPersonelId tidak ditemukan atau tidak aktif", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdatePerformer] Check medicalPersonelId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 14. Update Data ---
    try {
        $sql = "UPDATE procedure_performer SET
                    medicalPersonelId = :medicalPersonelId,
                    performerType = :performerType,
                    id_practitioner = :id_practitioner,
                    performerNik = :performerNik,
                    performerName = :performerName,
                    performerNote = :performerNote
                WHERE procedurePerformerId = :id";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':medicalPersonelId' => $medicalPersonelId,
            ':performerType' => $performerType,
            ':id_practitioner' => $practitionerData['id_practitioner'],
            ':performerNik' => $practitionerData['nik'],
            ':performerName' => $practitionerData['name'],
            ':performerNote' => $performerNote,
            ':id' => $procedurePerformerId
        ]);

        // --- 15. Ambil data terbaru untuk response ---
        $stmt = $Conn->prepare("
            SELECT pp.*,
                mp.name AS medicalPersonelName,
                mp.medicalPersonelCategory,
                e.procedureId
            FROM procedure_performer pp
            LEFT JOIN medical_personel mp ON pp.medicalPersonelId = mp.medicalPersonelId
            LEFT JOIN procedure_encounter e ON pp.procedureId = e.procedureId
            WHERE pp.procedurePerformerId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $procedurePerformerId]);
        $updatedData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($updatedData) {
            $updatedData['procedurePerformerId'] = (int) $updatedData['procedurePerformerId'];
            $updatedData['procedureId'] = (int) $updatedData['procedureId'];
            $updatedData['medicalPersonelId'] = $updatedData['medicalPersonelId'] !== null ? (int) $updatedData['medicalPersonelId'] : null;
            if ($updatedData['medicalPersonelName'] === null) unset($updatedData['medicalPersonelName']);
            if ($updatedData['medicalPersonelCategory'] === null) unset($updatedData['medicalPersonelCategory']);
        }

        // --- 16. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Performer berhasil diperbarui",
                "code" => 200
            ],
            "metadata" => [
                "procedurePerformerId" => $procedurePerformerId,
                "updated_at" => $nowUtc . ' GMT'
            ],
            "data" => $updatedData
        ]);

    } catch (PDOException $e) {
        error_log('[UpdatePerformer] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal memperbarui data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>