<?php
    /**
     * Create Procedure Reference
     * Endpoint: POST /_API/Reference/Procedure/CreateProcedureReference.php
     * Header: token, account_token
     * Body: JSON (lihat contoh)
     *
     * - Validasi mandatory: procedureCategoryName, procedureName, bodySiteName
     * - Validasi duplikat: procedureCode harus unik (jika diisi)
     * - Status default 1 jika tidak diset
     * - Menyimpan creatAt, updateAt, creatBy, updateBy dari akun yang login.
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
    $Limiter->check("create_procedure_reference", 5, 60);

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

    // --- 6. Validasi Token & Permission ---
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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_procedure_reference' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah referensi tindakan", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateProcedureReference] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 7. Parse JSON Body ---
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["response" => ["message" => "Invalid JSON payload", "code" => 400], "metadata" => []]);
        exit;
    }

    // --- 8. Ambil nilai dari body ---
    $procedureCategoryName       = isset($input['procedureCategoryName']) ? trim($input['procedureCategoryName']) : '';
    $procedureCategoryCode       = isset($input['procedureCategoryCode']) ? trim($input['procedureCategoryCode']) : null;
    $procedureCategoryDipsplay   = isset($input['procedureCategoryDipsplay']) ? trim($input['procedureCategoryDipsplay']) : null;
    $procedureCategorySystem     = isset($input['procedureCategorySystem']) ? trim($input['procedureCategorySystem']) : null;
    $procedureName               = isset($input['procedureName']) ? trim($input['procedureName']) : '';
    $procedureCode               = isset($input['procedureCode']) ? trim($input['procedureCode']) : null;
    $procedureDisplay            = isset($input['procedureDisplay']) ? trim($input['procedureDisplay']) : null;
    $procedureSystem             = isset($input['procedureSystem']) ? trim($input['procedureSystem']) : null;
    $bodySiteName                = isset($input['bodySiteName']) ? trim($input['bodySiteName']) : '';
    $bodySiteCode                = isset($input['bodySiteCode']) ? trim($input['bodySiteCode']) : null;
    $bodySiteDisplay             = isset($input['bodySiteDisplay']) ? trim($input['bodySiteDisplay']) : null;
    $bodySiteSystem              = isset($input['bodySiteSystem']) ? trim($input['bodySiteSystem']) : null;
    $icd9Code                    = isset($input['icd9Code']) ? trim($input['icd9Code']) : null;
    $icd9Description             = isset($input['icd9Description']) ? trim($input['icd9Description']) : null;
    $status                      = isset($input['status']) ? (int) $input['status'] : 1;

    // --- 9. Validasi Field Wajib ---
    if (empty($procedureCategoryName)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "procedureCategoryName wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($procedureName)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "procedureName wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($bodySiteName)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "bodySiteName wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 10. Validasi status ---
    if (!in_array($status, [0, 1], true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "status hanya boleh 0 atau 1", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 11. Validasi duplikat procedureCode (jika diisi) ---
    if (!empty($procedureCode)) {
        try {
            $stmt = $Conn->prepare("SELECT procedureReferenceId FROM procedure_reference WHERE procedureCode = :code LIMIT 1");
            $stmt->execute([':code' => $procedureCode]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(["response" => ["message" => "procedureCode sudah digunakan", "code" => 409], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[CreateProcedureReference] Check procedureCode error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 12. Insert Data ---
    try {
        $createdDate = $nowUtc;

        $sql = "INSERT INTO procedure_reference (
                    procedureCategoryName,
                    procedureCategoryCode,
                    procedureCategoryDipsplay,
                    procedureCategorySystem,
                    procedureName,
                    procedureCode,
                    procedureDisplay,
                    procedureSystem,
                    bodySiteName,
                    bodySiteCode,
                    bodySiteDisplay,
                    bodySiteSystem,
                    icd9Code,
                    icd9Description,
                    status,
                    creatAt,
                    updateAt,
                    creatBy,
                    updateBy
                ) VALUES (
                    :procedureCategoryName,
                    :procedureCategoryCode,
                    :procedureCategoryDipsplay,
                    :procedureCategorySystem,
                    :procedureName,
                    :procedureCode,
                    :procedureDisplay,
                    :procedureSystem,
                    :bodySiteName,
                    :bodySiteCode,
                    :bodySiteDisplay,
                    :bodySiteSystem,
                    :icd9Code,
                    :icd9Description,
                    :status,
                    :creatAt,
                    :updateAt,
                    :creatBy,
                    :updateBy
                )";

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
            ':creatAt' => $createdDate,
            ':updateAt' => $createdDate,
            ':creatBy' => $loggedInAccountId,
            ':updateBy' => $loggedInAccountId
        ]);

        $newId = (int) $Conn->lastInsertId();

        // --- 13. Ambil data yang baru dibuat untuk response ---
        $stmt = $Conn->prepare("
            SELECT pr.*,
                ca.name AS createdName,
                ua.name AS updatedName
            FROM procedure_reference pr
            LEFT JOIN account ca ON pr.creatBy = ca.accountId
            LEFT JOIN account ua ON pr.updateBy = ua.accountId
            WHERE pr.procedureReferenceId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $newId]);
        $newData = $stmt->fetch(PDO::FETCH_ASSOC);

        // Format response
        if ($newData) {
            $newData['procedureReferenceId'] = (int) $newData['procedureReferenceId'];
            $newData['status'] = (int) $newData['status'];
            $newData['creatBy'] = $newData['creatBy'] !== null ? (int) $newData['creatBy'] : null;
            $newData['updateBy'] = $newData['updateBy'] !== null ? (int) $newData['updateBy'] : null;
            if ($newData['createdName'] === null) unset($newData['createdName']);
            if ($newData['updatedName'] === null) unset($newData['updatedName']);
        }

        // --- 14. Response Sukses ---
        http_response_code(201);
        echo json_encode([
            "response" => [
                "message" => "Referensi tindakan berhasil ditambahkan",
                "code" => 201
            ],
            "metadata" => [
                "procedureReferenceId" => $newId,
                "created_at" => $createdDate . ' GMT'
            ],
            "data" => $newData
        ]);

    } catch (PDOException $e) {
        error_log('[CreateProcedureReference] Insert error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>