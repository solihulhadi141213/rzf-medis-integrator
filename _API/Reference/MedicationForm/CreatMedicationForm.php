<?php
    /**
     * Create Medication Form Reference
     * Endpoint: POST /_API/Reference/MedicationForm/CreateMedicationForm.php
     * Header: token, account_token
     * Body: JSON {
     *     "medicationFormCode": "MF000001",
     *     "medicationFormDisplay": "Cairan Obat Luar",
     *     "medicationFormSystem": "https://terminology.kemkes.go.id/CodeSystem/medication-form",
     *     "medicationFormCategory": "Cairan Luar",
     *     "medicationFormGroup": "Obat"
     * }
     *
     * - Validasi mandatory.
     * - medicationFormCode harus unik.
     * - medicationFormGroup harus salah satu dari enum (Alkes/Obat).
     * - Tidak ada integrasi SATUSEHAT.
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
    $Limiter->check("create_medication_form", 5, 60);

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_medication_form' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah referensi sediaan obat", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateMedicationForm] Auth error: ' . $e->getMessage());
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
    $medicationFormCode = isset($input['medicationFormCode']) ? trim($input['medicationFormCode']) : '';
    $medicationFormDisplay = isset($input['medicationFormDisplay']) ? trim($input['medicationFormDisplay']) : '';
    $medicationFormSystem = isset($input['medicationFormSystem']) ? trim($input['medicationFormSystem']) : '';
    $medicationFormCategory = isset($input['medicationFormCategory']) ? trim($input['medicationFormCategory']) : '';
    $medicationFormGroup = isset($input['medicationFormGroup']) ? trim($input['medicationFormGroup']) : '';

    // --- 9. Validasi Field Wajib ---
    $required = ['medicationFormCode', 'medicationFormDisplay', 'medicationFormSystem', 'medicationFormCategory', 'medicationFormGroup'];
    foreach ($required as $field) {
        if (empty($$field)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Field '$field' wajib diisi", "code" => 422], "metadata" => []]);
            exit;
        }
    }

    // --- 10. Validasi medicationFormGroup (enum) ---
    $allowedGroups = ['Alkes', 'Obat'];
    if (!in_array($medicationFormGroup, $allowedGroups, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "medicationFormGroup harus salah satu dari: " . implode(', ', $allowedGroups),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 11. Validasi duplikat medicationFormCode ---
    try {
        $stmt = $Conn->prepare("SELECT medicationFormId FROM medication_form_reference WHERE medicationFormCode = :code LIMIT 1");
        $stmt->execute([':code' => $medicationFormCode]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(["response" => ["message" => "medicationFormCode sudah digunakan", "code" => 409], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateMedicationForm] Check duplicate error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 12. Insert Data ---
    try {
        $sql = "INSERT INTO medication_form_reference (
                    medicationFormCode,
                    medicationFormDisplay,
                    medicationFormSystem,
                    medicationFormCategory,
                    medicationFormGroup
                ) VALUES (
                    :medicationFormCode,
                    :medicationFormDisplay,
                    :medicationFormSystem,
                    :medicationFormCategory,
                    :medicationFormGroup
                )";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':medicationFormCode' => $medicationFormCode,
            ':medicationFormDisplay' => $medicationFormDisplay,
            ':medicationFormSystem' => $medicationFormSystem,
            ':medicationFormCategory' => $medicationFormCategory,
            ':medicationFormGroup' => $medicationFormGroup
        ]);
        $newId = (int) $Conn->lastInsertId();

        // --- 13. Ambil data yang baru ditambahkan ---
        $stmt = $Conn->prepare("SELECT * FROM medication_form_reference WHERE medicationFormId = :id LIMIT 1");
        $stmt->execute([':id' => $newId]);
        $newData = $stmt->fetch(PDO::FETCH_ASSOC);
        $newData['medicationFormId'] = (int) $newData['medicationFormId'];

        // --- 14. Response Sukses ---
        http_response_code(201);
        echo json_encode([
            "response" => [
                "message" => "Referensi sediaan obat berhasil ditambahkan",
                "code" => 201
            ],
            "metadata" => [
                "medicationFormId" => $newId,
                "created_at" => $nowUtc . ' GMT'
            ],
            "data" => $newData
        ]);

    } catch (PDOException $e) {
        error_log('[CreateMedicationForm] Insert error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>