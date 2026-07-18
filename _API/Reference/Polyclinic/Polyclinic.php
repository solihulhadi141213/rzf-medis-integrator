<?php
/**
 * List Polyclinic
 * Endpoint: GET /_API/Reference/Polyclinic/Polyclinic.php
 * Header: token, account_token
 * Menampilkan semua data poliklinik (tanpa pagging/filter)
 */

// --- 1. Response Header ---
header('Content-Type: application/json');
header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
header("Cache-Control: no-store, no-cache, must-revalidate");
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: false');
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, token, account_token");

date_default_timezone_set('UTC');

// --- 2. Include Dependencies ---
include "../../../_Config/Connection.php";
include "../../../_Config/Helper.php";
require "../../../_Config/RateLimiter.php";

// --- 3. Rate Limiter ---
$Limiter = new RateLimiter($Conn);
$Limiter->check("list_polyclinic", 10, 60); // Maks 10 request per menit

// --- 4. Validasi Method ---
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

    // Validasi Permission (fitur list_polyclinic)
    $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
    $stmt->execute([':feature_name' => 'list_polyclinic']);
    $feature = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$feature) {
        http_response_code(403);
        echo json_encode([
            "response" => [
                "message" => "Fitur list_polyclinic tidak ditemukan",
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
                "message" => "Tidak memiliki izin untuk melihat daftar poliklinik",
                "code" => 403
            ],
            "metadata" => []
        ]);
        exit;
    }

} catch (PDOException $e) {
    error_log('[ListPolyclinic] DB/Permission error: ' . $e->getMessage());
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

// --- 7. Ambil Data Poliklinik ---
try {
    $sql = "SELECT
                p.polyclinicId,
                p.satuSehatCode,
                p.polyclinicCode,
                p.name,
                p.status,
                p.createdBy,
                ca.name AS createdName,
                p.createdDate,
                p.updatedBy,
                ua.name AS updatedName,
                p.updatedDate
            FROM polyclinic p
            LEFT JOIN account ca ON p.createdBy = ca.accountId
            LEFT JOIN account ua ON p.updatedBy = ua.accountId
            ORDER BY p.name ASC";

    $stmt = $Conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    foreach ($rows as &$row) {
        $row['polyclinicId'] = (int) $row['polyclinicId'];
        $row['status'] = (int) $row['status'];
        $row['status_display'] = $row['status'] === 1 ? 'Aktif' : 'Tidak Aktif';
        $row['createdBy'] = $row['createdBy'] !== null ? (int) $row['createdBy'] : null;
        $row['updatedBy'] = $row['updatedBy'] !== null ? (int) $row['updatedBy'] : null;

        // Hapus null values untuk createdName dan updatedName
        if ($row['createdName'] === null) {
            unset($row['createdName']);
        }
        if ($row['updatedName'] === null) {
            unset($row['updatedName']);
        }
    }
    unset($row);

    $total = count($rows);

    // --- 8. Response Sukses ---
    http_response_code(200);
    echo json_encode([
        "response" => [
            "message" => "Daftar poliklinik berhasil diambil",
            "code" => 200
        ],
        "metadata" => [
            "total" => $total,
            "retrieved_at" => $nowUtc . ' GMT'
        ],
        "data" => $rows
    ]);

} catch (PDOException $e) {
    error_log('[ListPolyclinic] Query error: ' . $e->getMessage());
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
?>