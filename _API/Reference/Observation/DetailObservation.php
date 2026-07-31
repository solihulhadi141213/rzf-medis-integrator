<?php
/**
 * Detail Observation Reference
 * Endpoint: GET /_API/Reference/Observation/DetailObservation.php?observationReferenceId={id}
 * Header: token, account_token
 *
 * Menampilkan detail referensi pemeriksaan (observation_reference) beserta:
 * - Data observation_reference
 * - List observation_reference_age
 * - List observation_reference_coded
 * - List observation_reference_range
 * - Informasi pembuat/update
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
$Limiter->check("detail_observation_reference", 10, 60);

// --- 4. Validasi Method ---
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        "response" => ["message" => "Metode request tidak diizinkan", "code" => 405],
        "metadata" => []
    ]);
    exit;
}

// --- 5. Validasi Header Token & Account Token ---
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

// --- 7. Validasi Token dan Permission ---
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
        echo json_encode(["response" => ["message" => "Token tidak valid", "code" => 401], "metadata" => []]);
        exit;
    }
    if ($tokenData['datetime_expired'] < $nowUtc) {
        http_response_code(401);
        echo json_encode(["response" => ["message" => "Token sudah kedaluwarsa", "code" => 401], "metadata" => []]);
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
        echo json_encode(["response" => ["message" => "account_token tidak valid", "code" => 401], "metadata" => []]);
        exit;
    }
    $loggedInAccountId = (int) $accountTokenData['accountId'];

    // Validasi Permission (fitur detail_observation_reference)
    $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
    $stmt->execute([':feature_name' => 'detail_observation_reference']);
    $feature = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$feature) {
        http_response_code(403);
        echo json_encode(["response" => ["message" => "Fitur detail_observation_reference tidak ditemukan", "code" => 403], "metadata" => []]);
        exit;
    }
    $id_service_feature = (int) $feature['id_service_feature'];
    if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
        http_response_code(403);
        echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk melihat detail referensi pemeriksaan", "code" => 403], "metadata" => []]);
        exit;
    }

} catch (PDOException $e) {
    error_log('[DetailObservationReference] Auth error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "response" => [
            "message" => "Internal Server Error - Auth: " . $e->getMessage(),
            "code" => 500
        ],
        "metadata" => []
    ]);
    exit;
}

// --- 8. Query utama observation_reference ---
try {
    $sql = "SELECT
                `o`.`observationReferenceId`,
                `o`.`categoryName`,
                `o`.`categoryCode`,
                `o`.`categoryDisplay`,
                `o`.`categorySystem`,
                `o`.`observationName`,
                `o`.`observationCode`,
                `o`.`observationDisplay`,
                `o`.`observationSystem`,
                `o`.`unitName`,
                `o`.`unitCode`,
                `o`.`unitDisplay`,
                `o`.`unitSystem`,
                `o`.`resultType`,
                `o`.`allowSex`,
                `o`.`allowAge`,
                `o`.`status`,
                `o`.`creatAt`,
                `o`.`updateAt`,
                `o`.`creatBy`,
                `cAccount`.`name` AS `createdName`,
                `o`.`updateBy`,
                `uAccount`.`name` AS `updatedName`
            FROM `observation_reference` `o`
            LEFT JOIN `account` `cAccount` ON `o`.`creatBy` = `cAccount`.`accountId`
            LEFT JOIN `account` `uAccount` ON `o`.`updateBy` = `uAccount`.`accountId`
            WHERE `o`.`observationReferenceId` = :id
            LIMIT 1";

    $stmt = $Conn->prepare($sql);
    $stmt->execute([':id' => $observationReferenceId]);
    $mainData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mainData) {
        http_response_code(404);
        echo json_encode([
            "response" => ["message" => "Data referensi pemeriksaan tidak ditemukan", "code" => 404],
            "metadata" => []
        ]);
        exit;
    }

    // Format main data
    $mainData['observationReferenceId'] = (int) $mainData['observationReferenceId'];
    $mainData['allowSex'] = (int) $mainData['allowSex'];
    $mainData['allowAge'] = (int) $mainData['allowAge'];
    $mainData['status'] = (int) $mainData['status'];
    $mainData['creatBy'] = $mainData['creatBy'] !== null ? (int) $mainData['creatBy'] : null;
    $mainData['updateBy'] = $mainData['updateBy'] !== null ? (int) $mainData['updateBy'] : null;
    if ($mainData['createdName'] === null) unset($mainData['createdName']);
    if ($mainData['updatedName'] === null) unset($mainData['updatedName']);

} catch (PDOException $e) {
    error_log('[DetailObservationReference] Query main error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "response" => [
            "message" => "Internal Server Error - Main Query: " . $e->getMessage(),
            "code" => 500
        ],
        "metadata" => []
    ]);
    exit;
}

// --- 9. Query observation_reference_age ---
try {
    $sql = "SELECT
                `observationReferenceAgeId`,
                `observationReferenceId`,
                `ageCategory`,
                `ageMin`,
                `ageMax`,
                `ageUnit`
            FROM `observation_reference_age`
            WHERE `observationReferenceId` = :id
            ORDER BY `ageMin` ASC";
    $stmt = $Conn->prepare($sql);
    $stmt->execute([':id' => $observationReferenceId]);
    $ageData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ageData as &$age) {
        $age['observationReferenceAgeId'] = (int) $age['observationReferenceAgeId'];
        $age['observationReferenceId'] = (int) $age['observationReferenceId'];
        $age['ageMin'] = (int) $age['ageMin'];
        $age['ageMax'] = $age['ageMax'] !== null ? (int) $age['ageMax'] : null;
    }
    unset($age);
} catch (PDOException $e) {
    error_log('[DetailObservationReference] Query age error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "response" => [
            "message" => "Internal Server Error - Age Query: " . $e->getMessage(),
            "code" => 500
        ],
        "metadata" => []
    ]);
    exit;
}

// --- 10. Query observation_reference_coded ---
try {
    $sql = "SELECT
                `observationReferenceCodedId`,
                `observationReferenceId`,
                `observationReferenceAgeId`,
                `groupGender`,
                `valueResult`,
                `labelResult`,
                `displayResult`,
                `codeResult`,
                `systemResult`,
                `normalResult`
            FROM `observation_reference_coded`
            WHERE `observationReferenceId` = :id
            ORDER BY `groupGender`, `labelResult`";
    $stmt = $Conn->prepare($sql);
    $stmt->execute([':id' => $observationReferenceId]);
    $codedData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($codedData as &$coded) {
        $coded['observationReferenceCodedId'] = (int) $coded['observationReferenceCodedId'];
        $coded['observationReferenceId'] = (int) $coded['observationReferenceId'];
        $coded['observationReferenceAgeId'] = $coded['observationReferenceAgeId'] !== null ? (int) $coded['observationReferenceAgeId'] : null;
        $coded['normalResult'] = (int) $coded['normalResult'];
    }
    unset($coded);
} catch (PDOException $e) {
    error_log('[DetailObservationReference] Query coded error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "response" => [
            "message" => "Internal Server Error - Coded Query: " . $e->getMessage(),
            "code" => 500
        ],
        "metadata" => []
    ]);
    exit;
}

// --- 11. Query observation_reference_range ---
try {
    $sql = "SELECT
                `observationResultRangeId`,
                `observationReferenceId`,
                `observationReferenceAgeId`,
                `groupGender`,
                `minValue`,
                `maxValue`,
                `rangeOperator`,
                `InterpertationLabel`,
                `InterpertationDisplay`,
                `InterpertationCode`,
                `InterpertationSystem`,
                `InterpertationConclusion`,
                `normalResult`
            FROM `observation_reference_range`
            WHERE `observationReferenceId` = :id
            ORDER BY `groupGender`, `rangeOperator`";
    $stmt = $Conn->prepare($sql);
    $stmt->execute([':id' => $observationReferenceId]);
    $rangeData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rangeData as &$range) {
        $range['observationResultRangeId'] = (int) $range['observationResultRangeId'];
        $range['observationReferenceId'] = (int) $range['observationReferenceId'];
        $range['observationReferenceAgeId'] = $range['observationReferenceAgeId'] !== null ? (int) $range['observationReferenceAgeId'] : null;
        $range['normalResult'] = (int) $range['normalResult'];
        $range['minValue'] = $range['minValue'] !== null ? (float) $range['minValue'] : null;
        $range['maxValue'] = $range['maxValue'] !== null ? (float) $range['maxValue'] : null;
    }
    unset($range);
} catch (PDOException $e) {
    error_log('[DetailObservationReference] Query range error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "response" => [
            "message" => "Internal Server Error - Range Query: " . $e->getMessage(),
            "code" => 500
        ],
        "metadata" => []
    ]);
    exit;
}

// --- 12. Response Sukses ---
http_response_code(200);
echo json_encode([
    "response" => [
        "message" => "Detail referensi pemeriksaan berhasil diambil",
        "code" => 200
    ],
    "metadata" => [
        "observationReferenceId" => $observationReferenceId,
        "retrieved_at" => $nowUtc . ' GMT'
    ],
    "data" => [
        "observation_reference" => $mainData,
        "observation_reference_age" => $ageData,
        "observation_reference_coded" => $codedData,
        "observation_reference_range" => $rangeData
    ]
]);
?>