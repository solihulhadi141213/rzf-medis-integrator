<?php
    /**
     * Create Allergen
     * Endpoint: POST /_API/Allergen/CreateAllergen.php
     * Header: token, account_token
     * Body: JSON {
     *     "allergenCategory": "Food",
     *     "allergenName": "Kepiting",
     *     "allergenCode": "256350002",
     *     "allergenDisplay": "Crab",
     *     "allergenSystem": "http://snomed.info/sct"
     * }
     *
     * - Validasi allergenCategory (enum) dan allergenName wajib.
     * - Field lainnya opsional.
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
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("create_allergen", 5, 60);

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
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = 'create_allergen' LIMIT 1");
        $stmt->execute();
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature || !ValidatePermission($Conn, $loggedInAccountId, $feature['id_service_feature'])) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah alergen", "code" => 403], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateAllergen] Auth error: ' . $e->getMessage());
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
    $allergenCategory = isset($input['allergenCategory']) ? trim($input['allergenCategory']) : '';
    $allergenName     = isset($input['allergenName']) ? trim($input['allergenName']) : '';
    $allergenCode     = isset($input['allergenCode']) ? trim($input['allergenCode']) : null;
    $allergenDisplay  = isset($input['allergenDisplay']) ? trim($input['allergenDisplay']) : null;
    $allergenSystem   = isset($input['allergenSystem']) ? trim($input['allergenSystem']) : null;

    // --- 9. Validasi Field Wajib ---
    if (empty($allergenCategory)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "allergenCategory wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }
    if (empty($allergenName)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "allergenName wajib diisi", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 10. Validasi allergenCategory (enum) ---
    $allowedCategories = ['Food', 'Medication', 'Environment', 'Biologic'];
    if (!in_array($allergenCategory, $allowedCategories, true)) {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "allergenCategory harus salah satu dari: " . implode(', ', $allowedCategories),
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }

    // --- 11. Validasi panjang field (opsional) ---
    if (strlen($allergenName) > 255) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "allergenName maksimal 255 karakter", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($allergenCode !== null && strlen($allergenCode) > 20) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "allergenCode maksimal 20 karakter", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($allergenDisplay !== null && strlen($allergenDisplay) > 255) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "allergenDisplay maksimal 255 karakter", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 12. Insert Data ---
    try {
        $createdDate = $nowUtc;

        $sql = "INSERT INTO allergen (
                    allergenCategory,
                    allergenName,
                    allergenCode,
                    allergenDisplay,
                    allergenSystem,
                    creatAt,
                    updateAt,
                    creatBy,
                    updateBy
                ) VALUES (
                    :allergenCategory,
                    :allergenName,
                    :allergenCode,
                    :allergenDisplay,
                    :allergenSystem,
                    :creatAt,
                    :updateAt,
                    :creatBy,
                    :updateBy
                )";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':allergenCategory' => $allergenCategory,
            ':allergenName' => $allergenName,
            ':allergenCode' => $allergenCode,
            ':allergenDisplay' => $allergenDisplay,
            ':allergenSystem' => $allergenSystem,
            ':creatAt' => $createdDate,
            ':updateAt' => $createdDate,
            ':creatBy' => $loggedInAccountId,
            ':updateBy' => $loggedInAccountId
        ]);

        $newId = (int) $Conn->lastInsertId();

        // --- 13. Response Sukses ---
        http_response_code(201);
        echo json_encode([
            "response" => [
                "message" => "Alergen berhasil ditambahkan",
                "code" => 201
            ],
            "metadata" => [
                "AllergenId" => $newId,
                "created_at" => $createdDate . ' GMT'
            ],
            "data" => [
                "AllergenId" => $newId,
                "allergenCategory" => $allergenCategory,
                "allergenName" => $allergenName,
                "allergenCode" => $allergenCode,
                "allergenDisplay" => $allergenDisplay,
                "allergenSystem" => $allergenSystem,
                "creatBy" => $loggedInAccountId,
                "creatAt" => $createdDate,
                "updateBy" => $loggedInAccountId,
                "updateAt" => $createdDate
            ]
        ]);

    } catch (PDOException $e) {
        error_log('[CreateAllergen] Insert error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>