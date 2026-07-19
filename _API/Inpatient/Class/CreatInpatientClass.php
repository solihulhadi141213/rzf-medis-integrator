<?php
/**
 * Create Inpatient Class
 * Endpoint: POST /_API/Inpatient/Class/CreateInpatientClass.php
 * Header: token, account_token
 * Body: JSON { "inpatientClassCode": "KL3", "inpatientClassName": "Kelas III" }
 * 
 * Sistem akan membuat Location di SATUSEHAT dan menyimpan ID-nya ke satuSehatCode.
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
$Limiter->check("create_inpatient_class", 5, 60);

// --- 4. Validasi Method ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "response" => [
            "message" => "Metode request tidak diizinkan",
            "code" => 405
        ],
        "metadata" => []
    ]);
    exit;
}

// --- 5. Validasi Header Token & Account Token ---
$apiToken     = getRequestHeader('token');
$accountToken = getRequestHeader('account_token');

if (empty($apiToken)) {
    http_response_code(401);
    echo json_encode([
        "response" => [
            "message" => "Token Kredensial Aplikasi Tidak Boleh Kosong",
            "code" => 401
        ],
        "metadata" => []
    ]);
    exit;
}

if (empty($accountToken)) {
    http_response_code(401);
    echo json_encode([
        "response" => [
            "message" => "Token Sesi Akses Tidak Boleh Kosong",
            "code" => 401
        ],
        "metadata" => []
    ]);
    exit;
}

// --- 6. Validasi Token dan Permission ---
$nowUtc = gmdate('Y-m-d H:i:s');
try {
    // Validasi API Token
    $stmt = $Conn->prepare("
        SELECT t.*, k.client_id, k.api_name, k.id_api_key 
        FROM api_token t 
        JOIN api_key k ON t.id_api_key = k.id_api_key 
        WHERE t.token = :token LIMIT 1
    ");
    $stmt->execute([':token' => $apiToken]);
    $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenData) {
        http_response_code(401);
        echo json_encode([
            "response" => [
                "message" => "Token tidak valid",
                "code" => 401
            ],
            "metadata" => []
        ]);
        exit;
    }

    if ($tokenData['datetime_expired'] < $nowUtc) {
        http_response_code(401);
        echo json_encode([
            "response" => [
                "message" => "Token sudah kedaluwarsa",
                "code" => 401
            ],
            "metadata" => []
        ]);
        exit;
    }

    // Validasi Account Token
    $stmt = $Conn->prepare("
        SELECT accountId 
        FROM account_token 
        WHERE account_token = :account_token 
        AND datetime_expired >= :nowUtc LIMIT 1
    ");
    $stmt->execute([
        ':account_token' => $accountToken,
        ':nowUtc' => $nowUtc
    ]);
    $accountTokenData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$accountTokenData) {
        http_response_code(401);
        echo json_encode([
            "response" => [
                "message" => "account_token tidak valid",
                "code" => 401
            ],
            "metadata" => []
        ]);
        exit;
    }

    $loggedInAccountId = (int) $accountTokenData['accountId'];

    // Validasi Permission (fitur create_inpatient_class)
    $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
    $stmt->execute([':feature_name' => 'create_inpatient_class']);
    $feature = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$feature) {
        http_response_code(403);
        echo json_encode([
            "response" => [
                "message" => "Fitur create_inpatient_class tidak ditemukan",
                "code" => 403
            ],
            "metadata" => []
        ]);
        exit;
    }
    $id_service_feature = (int) $feature['id_service_feature'];
    if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
        http_response_code(403);
        echo json_encode([
            "response" => [
                "message" => "Tidak memiliki izin untuk menambah kelas rawat inap",
                "code" => 403
            ],
            "metadata" => []
        ]);
        exit;
    }

} catch (PDOException $e) {
    error_log('[CreateInpatientClass] DB/Permission error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "response" => [
            "message" => "Internal Server Error",
            "code" => 500
        ],
        "metadata" => []
    ]);
    exit;
}

// --- 7. Ambil dan Decode Body JSON ---
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        "response" => [
            "message" => "Invalid JSON payload: " . json_last_error_msg(),
            "code" => 400
        ],
        "metadata" => []
    ]);
    exit;
}

// --- 8. Validasi Field Wajib ---
$requiredFields = ['inpatientClassCode', 'inpatientClassName'];
foreach ($requiredFields as $field) {
    if (!isset($input[$field]) || trim($input[$field]) === '') {
        http_response_code(422);
        echo json_encode([
            "response" => [
                "message" => "Field '$field' wajib diisi",
                "code" => 422
            ],
            "metadata" => []
        ]);
        exit;
    }
}

// --- 9. Ambil dan Trim Nilai ---
$inpatientClassCode = trim($input['inpatientClassCode']);
$inpatientClassName = trim($input['inpatientClassName']);
$status = isset($input['status']) ? (int) $input['status'] : 1;

// --- 10. Validasi inpatientClassCode (unik, max 20) ---
if (strlen($inpatientClassCode) > 20) {
    http_response_code(422);
    echo json_encode([
        "response" => [
            "message" => "inpatientClassCode maksimal 20 karakter",
            "code" => 422
        ],
        "metadata" => []
    ]);
    exit;
}
try {
    $stmt = $Conn->prepare("SELECT inpatientClassId FROM inpatient_class WHERE inpatientClassCode = :code LIMIT 1");
    $stmt->execute([':code' => $inpatientClassCode]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode([
            "response" => [
                "message" => "inpatientClassCode sudah digunakan",
                "code" => 409
            ],
            "metadata" => []
        ]);
        exit;
    }
} catch (PDOException $e) {
    error_log('[CreateInpatientClass] Check code error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "response" => [
            "message" => "Internal Server Error",
            "code" => 500
        ],
        "metadata" => []
    ]);
    exit;
}

// --- 11. Validasi status ---
if (!in_array($status, [0, 1], true)) {
    http_response_code(422);
    echo json_encode([
        "response" => [
            "message" => "status hanya boleh 0 atau 1",
            "code" => 422
        ],
        "metadata" => []
    ]);
    exit;
}

// --- 12. Kirim ke SATUSEHAT untuk membuat Location ---
try {
    // Ambil credential SATUSEHAT yang aktif
    $credStmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
    $credStmt->execute();
    $credential = $credStmt->fetch(PDO::FETCH_ASSOC);
    if (!$credential) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Tidak ada kredensial SATUSEHAT yang aktif",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    // Dapatkan token SATUSEHAT
    $tokenResult = generateTokenSatusehat($Conn);
    if ($tokenResult['status'] !== 'success') {
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Gagal mendapatkan token SATUSEHAT: " . $tokenResult['message'],
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }
    $accessToken    = $tokenResult['token'];
    $organizationId = $credential['organizationId'];
    $baseUrl        = rtrim($credential['baseUrl'], '/');

    // Siapkan data untuk Location (mengikuti pola CreatePolyclinic)
    $LocationData = [
        "resourceType" => "Location",
        "identifier" => [
            [
                "system" => "http://sys-ids.kemkes.go.id/location/$organizationId",
                "value" => $inpatientClassCode
            ]
        ],
        "name" => $inpatientClassName,
        "status" => "active"
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $baseUrl . '/fhir-r4/v1/Location',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($LocationData),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Gagal menghubungi API SATUSEHAT: " . $curlError,
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }

    if ($httpCode !== 201 && $httpCode !== 200) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "SATUSEHAT API error: " . substr($response, 0, 500),
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "JSON Error: " . json_last_error_msg(),
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }

    // Ambil ID Location dari response
    if (!isset($result['id']) || empty($result['id'])) {
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "SATUSEHAT tidak mengembalikan ID Location",
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }

    $satuSehatCode = $result['id'];

} catch (Exception $e) {
    error_log('[CreateInpatientClass] SATUSEHAT error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "response" => [
            "message" => "Internal Server Error: " . $e->getMessage(),
            "code" => 500
        ],
        "metadata" => []
    ]);
    exit;
}

// --- 13. Insert Data ke Database ---
try {
    $createdDate = $nowUtc;

    $sql = "INSERT INTO inpatient_class (
                inpatientClassCode,
                satuSehatCode,
                inpatientClassName,
                status,
                creatBy,
                creatAt,
                updateBy,
                updatedAt
            ) VALUES (
                :inpatientClassCode,
                :satuSehatCode,
                :inpatientClassName,
                :status,
                :creatBy,
                :creatAt,
                :updateBy,
                :updatedAt
            )";

    $stmt = $Conn->prepare($sql);
    $stmt->execute([
        ':inpatientClassCode' => $inpatientClassCode,
        ':satuSehatCode' => $satuSehatCode,
        ':inpatientClassName' => $inpatientClassName,
        ':status' => $status,
        ':creatBy' => $loggedInAccountId,
        ':creatAt' => $createdDate,
        ':updateBy' => $loggedInAccountId,
        ':updatedAt' => $createdDate
    ]);

    $newId = (int) $Conn->lastInsertId();

    // --- 14. Response Sukses ---
    http_response_code(201);
    echo json_encode([
        "response" => [
            "message" => "Kelas rawat inap berhasil ditambahkan dan disinkronkan ke SATUSEHAT",
            "code" => 201
        ],
        "metadata" => [
            "inpatientClassId" => $newId,
            "satuSehatCode" => $satuSehatCode,
            "created_at" => $createdDate . ' GMT'
        ],
        "data" => [
            "inpatientClassId" => $newId,
            "inpatientClassCode" => $inpatientClassCode,
            "satuSehatCode" => $satuSehatCode,
            "inpatientClassName" => $inpatientClassName,
            "status" => $status,
            "status_display" => $status === 1 ? 'Aktif' : 'Tidak Aktif',
            "creatBy" => $loggedInAccountId,
            "creatAt" => $createdDate,
            "updateBy" => $loggedInAccountId,
            "updatedAt" => $createdDate
        ]
    ]);

} catch (PDOException $e) {
    error_log('[CreateInpatientClass] Insert error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "response" => [
            "message" => "Gagal menyimpan data: " . $e->getMessage(),
            "code" => 500
        ],
        "metadata" => []
    ]);
    exit;
}
?>