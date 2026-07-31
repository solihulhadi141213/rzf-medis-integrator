<?php
    /**
     * Create Observation Reference Coded
     * Endpoint: POST /_API/Reference/Observation/Coded/CreateCoded.php?observationReferenceId={id}
     * Header: token, account_token
     * Body: JSON {
     *     "observationReferenceAgeId": null,
     *     "groupGender": "All",
     *     "valueResult": "A",
     *     "labelResult": "A",
     *     "displayResult": "A",
     *     "codeResult": "A",
     *     "systemResult": "http://terminology.hl7.org/CodeSystem/v2-0201",
     *     "normalResult": false
     * }
     *
     * - Validasi observationReferenceId ada.
     * - observationReferenceAgeId opsional, jika diisi harus valid dan terkait dengan reference yang sama.
     * - groupGender enum, valueResult dan labelResult wajib.
     * - Insert ke observation_reference_coded.
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
    include "../../../../_Config/Connection.php";
    include "../../../../_Config/Helper.php";
    require "../../../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("create_coded", 5, 60);

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_coded' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah coded value", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateCoded] Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Validasi observationReferenceId ada ---
    try {
        $stmt = $Conn->prepare("SELECT observationReferenceId FROM observation_reference WHERE observationReferenceId = :id LIMIT 1");
        $stmt->execute([':id' => $observationReferenceId]);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "observationReferenceId tidak ditemukan", "code" => 422], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateCoded] Check observationReferenceId error: ' . $e->getMessage());
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

    // --- 10. Ambil nilai dari body ---
    $observationReferenceAgeId = isset($input['observationReferenceAgeId']) && $input['observationReferenceAgeId'] !== null && $input['observationReferenceAgeId'] !== '' ? (int) $input['observationReferenceAgeId'] : null;
    $groupGender = isset($input['groupGender']) ? trim($input['groupGender']) : 'All';
    $valueResult = isset($input['valueResult']) ? trim($input['valueResult']) : '';
    $labelResult = isset($input['labelResult']) ? trim($input['labelResult']) : '';
    $displayResult = isset($input['displayResult']) ? trim($input['displayResult']) : null;
    $codeResult = isset($input['codeResult']) ? trim($input['codeResult']) : null;
    $systemResult = isset($input['systemResult']) ? trim($input['systemResult']) : null;
    $normalResult = isset($input['normalResult']) ? (int) $input['normalResult'] : 0;

    // --- 11. Validasi Field Wajib ---
    if (empty($valueResult)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "valueResult wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($labelResult)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "labelResult wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 12. Validasi groupGender ---
    $allowedGender = ['Male', 'Female', 'All'];
    if (!in_array($groupGender, $allowedGender, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "groupGender harus salah satu dari: " . implode(', ', $allowedGender),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 13. Validasi normalResult ---
    if (!in_array($normalResult, [0, 1], true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "normalResult hanya boleh 0 atau 1", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 14. Validasi observationReferenceAgeId (jika diisi) ---
    if ($observationReferenceAgeId !== null) {
        try {
            $stmt = $Conn->prepare("SELECT observationReferenceAgeId FROM observation_reference_age WHERE observationReferenceAgeId = :id AND observationReferenceId = :refId LIMIT 1");
            $stmt->execute([':id' => $observationReferenceAgeId, ':refId' => $observationReferenceId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "observationReferenceAgeId tidak ditemukan atau tidak terkait dengan observation ini", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[CreateCoded] Check observationReferenceAgeId error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 15. Insert data ---
    try {
        $sql = "INSERT INTO `observation_reference_coded` (
                    `observationReferenceId`,
                    `observationReferenceAgeId`,
                    `groupGender`,
                    `valueResult`,
                    `labelResult`,
                    `displayResult`,
                    `codeResult`,
                    `systemResult`,
                    `normalResult`
                ) VALUES (
                    :observationReferenceId,
                    :observationReferenceAgeId,
                    :groupGender,
                    :valueResult,
                    :labelResult,
                    :displayResult,
                    :codeResult,
                    :systemResult,
                    :normalResult
                )";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':observationReferenceId' => $observationReferenceId,
            ':observationReferenceAgeId' => $observationReferenceAgeId,
            ':groupGender' => $groupGender,
            ':valueResult' => $valueResult,
            ':labelResult' => $labelResult,
            ':displayResult' => $displayResult,
            ':codeResult' => $codeResult,
            ':systemResult' => $systemResult,
            ':normalResult' => $normalResult
        ]);
        $newId = (int) $Conn->lastInsertId();

        // --- 16. Ambil data yang baru ditambahkan ---
        $stmt = $Conn->prepare("SELECT * FROM observation_reference_coded WHERE observationReferenceCodedId = :id LIMIT 1");
        $stmt->execute([':id' => $newId]);
        $newData = $stmt->fetch(PDO::FETCH_ASSOC);
        $newData['observationReferenceCodedId'] = (int) $newData['observationReferenceCodedId'];
        $newData['observationReferenceId'] = (int) $newData['observationReferenceId'];
        $newData['observationReferenceAgeId'] = $newData['observationReferenceAgeId'] !== null ? (int) $newData['observationReferenceAgeId'] : null;
        $newData['normalResult'] = (int) $newData['normalResult'];

        // --- 17. Response Sukses ---
        http_response_code(201);
        echo json_encode([
            "response" => [
                "message" => "Coded value berhasil ditambahkan",
                "code" => 201
            ],
            "metadata" => [
                "observationReferenceCodedId" => $newId,
                "created_at" => $nowUtc . ' GMT'
            ],
            "data" => $newData
        ]);

    } catch (PDOException $e) {
        error_log('[CreateCoded] Insert error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>