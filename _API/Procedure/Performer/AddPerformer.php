<?php
    /**
     * Add Performer to Procedure
     * Endpoint: POST /_API/Procedure/Performer/AddPerformer.php?procedureId={id}
     * Header: token, account_token
     * Body: JSON {
     *     "medicalPersonelId": 5,
     *     "performerType": "Assistant",
     *     "performerNote": "Tindakan berjalan dengan baik"
     * }
     *
     * - Validasi procedureId, medicalPersonelId, performerType.
     * - Mengisi id_practitioner, performerNik, performerName dari medical_personel.
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
    $Limiter->check("add_procedure_performer", 5, 60);

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'add_procedure_performer' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah performer tindakan", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[AddPerformer] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Parse JSON Body ---
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["response" => ["message" => "Invalid JSON payload", "code" => 400], "metadata" => []]);
        exit;
    }

    // --- 9. Ambil nilai dari body ---
    $medicalPersonelId = isset($input['medicalPersonelId']) ? (int) $input['medicalPersonelId'] : 0;
    $performerType = isset($input['performerType']) ? trim($input['performerType']) : '';
    $performerNote = isset($input['performerNote']) ? trim($input['performerNote']) : null;

    // --- 10. Validasi Field Wajib ---
    if ($medicalPersonelId <= 0) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "medicalPersonelId wajib diisi dengan angka positif", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($performerType)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "performerType wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 11. Validasi performerType ---
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

    // --- 12. Validasi procedureId ---
    try {
        $stmt = $Conn->prepare("SELECT procedureId FROM procedure_encounter WHERE procedureId = :id LIMIT 1");
        $stmt->execute([':id' => $procedureId]);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "procedureId tidak ditemukan", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[AddPerformer] Check procedureId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 13. Validasi medicalPersonelId dan ambil data ---
    $practitionerData = null;
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
        error_log('[AddPerformer] Check medicalPersonelId error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 14. Insert performer ---
    try {
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
            ':medicalPersonelId' => $medicalPersonelId,
            ':performerType' => $performerType,
            ':id_practitioner' => $practitionerData['id_practitioner'],
            ':performerNik' => $practitionerData['nik'],
            ':performerName' => $practitionerData['name'],
            ':performerNote' => $performerNote
        ]);

        $newId = (int) $Conn->lastInsertId();

        // --- 15. Ambil data performer yang baru ditambahkan ---
        $stmt = $Conn->prepare("
            SELECT pp.*,
                mp.name AS medicalPersonelName,
                mp.medicalPersonelCategory
            FROM procedure_performer pp
            LEFT JOIN medical_personel mp ON pp.medicalPersonelId = mp.medicalPersonelId
            WHERE pp.procedurePerformerId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $newId]);
        $newPerformer = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($newPerformer) {
            $newPerformer['procedurePerformerId'] = (int) $newPerformer['procedurePerformerId'];
            $newPerformer['procedureId'] = (int) $newPerformer['procedureId'];
            $newPerformer['medicalPersonelId'] = $newPerformer['medicalPersonelId'] !== null ? (int) $newPerformer['medicalPersonelId'] : null;
            if ($newPerformer['medicalPersonelName'] === null) unset($newPerformer['medicalPersonelName']);
            if ($newPerformer['medicalPersonelCategory'] === null) unset($newPerformer['medicalPersonelCategory']);
        }

        // --- 16. Response Sukses ---
        http_response_code(201);
        echo json_encode([
            "response" => [
                "message" => "Performer berhasil ditambahkan",
                "code" => 201
            ],
            "metadata" => [
                "procedurePerformerId" => $newId,
                "procedureId" => $procedureId,
                "created_at" => $nowUtc . ' GMT'
            ],
            "data" => $newPerformer
        ]);

    } catch (PDOException $e) {
        error_log('[AddPerformer] Insert error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan data performer: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>