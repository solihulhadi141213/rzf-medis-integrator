<?php
    /**
     * Create Medication Numerator Reference
     * Endpoint: POST /_API/Reference/MedicationNumerator/CreateMedicationNumerator.php
     * Header: token, account_token
     * Body: JSON {
     *     "medicationNumeratorName": "Miligram",
     *     "medicationNumeratorUnit": "miligram",
     *     "medicationNumeratorCode": "mg",
     *     "medicationNumeratorSystem": "http://unitsofmeasure.org"
     * }
     *
     * - Validasi mandatory.
     * - medicationNumeratorCode harus unik.
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
    $Limiter->check("create_medication_numerator", 5, 60);

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_medication_numerator' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah referensi numerator", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateMedicationNumerator] Auth error: ' . $e->getMessage());
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
    $medicationNumeratorName = isset($input['medicationNumeratorName']) ? trim($input['medicationNumeratorName']) : '';
    $medicationNumeratorUnit = isset($input['medicationNumeratorUnit']) ? trim($input['medicationNumeratorUnit']) : '';
    $medicationNumeratorCode = isset($input['medicationNumeratorCode']) ? trim($input['medicationNumeratorCode']) : '';
    $medicationNumeratorSystem = isset($input['medicationNumeratorSystem']) ? trim($input['medicationNumeratorSystem']) : '';

    // --- 9. Validasi Field Wajib ---
    $required = ['medicationNumeratorName', 'medicationNumeratorUnit', 'medicationNumeratorCode', 'medicationNumeratorSystem'];
    foreach ($required as $field) {
        if (empty($$field)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Field '$field' wajib diisi", "code" => 422], "metadata" => []]);
            exit;
        }
    }

    // --- 10. Validasi duplikat medicationNumeratorCode ---
    try {
        $stmt = $Conn->prepare("SELECT medicationNumeratorId FROM medication_numerator WHERE medicationNumeratorCode = :code LIMIT 1");
        $stmt->execute([':code' => $medicationNumeratorCode]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(["response" => ["message" => "medicationNumeratorCode sudah digunakan", "code" => 409], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateMedicationNumerator] Check duplicate error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 11. Insert Data ---
    try {
        $sql = "INSERT INTO medication_numerator (
                    medicationNumeratorName,
                    medicationNumeratorUnit,
                    medicationNumeratorCode,
                    medicationNumeratorSystem
                ) VALUES (
                    :medicationNumeratorName,
                    :medicationNumeratorUnit,
                    :medicationNumeratorCode,
                    :medicationNumeratorSystem
                )";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':medicationNumeratorName' => $medicationNumeratorName,
            ':medicationNumeratorUnit' => $medicationNumeratorUnit,
            ':medicationNumeratorCode' => $medicationNumeratorCode,
            ':medicationNumeratorSystem' => $medicationNumeratorSystem
        ]);
        $newId = (int) $Conn->lastInsertId();

        // --- 12. Ambil data yang baru ditambahkan ---
        $stmt = $Conn->prepare("SELECT * FROM medication_numerator WHERE medicationNumeratorId = :id LIMIT 1");
        $stmt->execute([':id' => $newId]);
        $newData = $stmt->fetch(PDO::FETCH_ASSOC);
        $newData['medicationNumeratorId'] = (int) $newData['medicationNumeratorId'];

        // --- 13. Response Sukses ---
        http_response_code(201);
        echo json_encode([
            "response" => [
                "message" => "Referensi numerator berhasil ditambahkan",
                "code" => 201
            ],
            "metadata" => [
                "medicationNumeratorId" => $newId,
                "created_at" => $nowUtc . ' GMT'
            ],
            "data" => $newData
        ]);

    } catch (PDOException $e) {
        error_log('[CreateMedicationNumerator] Insert error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>