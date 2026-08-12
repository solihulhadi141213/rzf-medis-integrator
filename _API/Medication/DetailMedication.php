<?php
    /**
     * Detail Medication
     * Endpoint: GET /_API/Medication/DetailMedication.php?medicationId={id}
     * Header: token, account_token
     *
     * Menampilkan detail obat/alkes beserta multi_form dan selling_price.
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
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("detail_medication", 10, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(["response" => ["message" => "Metode request tidak diizinkan", "code" => 405], "metadata" => []]);
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

    // --- 6. Validasi Parameter medicationId ---
    if (!isset($_GET['medicationId']) || !is_numeric($_GET['medicationId']) || (int)$_GET['medicationId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter medicationId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $medicationId = (int) $_GET['medicationId'];

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

        // Validasi Permission (fitur detail_medication)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'detail_medication']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Fitur detail_medication tidak ditemukan", "code" => 403], "metadata" => []]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk melihat detail obat/alkes", "code" => 403], "metadata" => []]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[DetailMedication] DB/Permission error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Query detail medication ---
    try {
        $sql = "SELECT
                    m.*,
                    ca.name AS createdName,
                    ua.name AS updatedName
                FROM medication m
                LEFT JOIN account ca ON m.creatBy = ca.accountId
                LEFT JOIN account ua ON m.updateBy = ua.accountId
                WHERE m.medicationId = :id
                LIMIT 1";
        $stmt = $Conn->prepare($sql);
        $stmt->execute([':id' => $medicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(["response" => ["message" => "Data obat/alkes tidak ditemukan", "code" => 404], "metadata" => []]);
            exit;
        }

        // Format data utama
        $row['medicationId'] = (int) $row['medicationId'];
        $row['CostPrice'] = $row['CostPrice'] !== null ? (float) $row['CostPrice'] : null;
        $row['ActualStock'] = $row['ActualStock'] !== null ? (float) $row['ActualStock'] : null;
        $row['MinimumStock'] = $row['MinimumStock'] !== null ? (float) $row['MinimumStock'] : null;
        $row['creatBy'] = $row['creatBy'] !== null ? (int) $row['creatBy'] : null;
        $row['updateBy'] = $row['updateBy'] !== null ? (int) $row['updateBy'] : null;
        // Decode medicationIngredient
        $row['medicationIngredient'] = $row['medicationIngredient'] ? json_decode($row['medicationIngredient'], true) : [];
        // Hapus null names
        if ($row['createdName'] === null) unset($row['createdName']);
        if ($row['updatedName'] === null) unset($row['updatedName']);

        // --- 9. Ambil multi_form ---
        $stmt2 = $Conn->prepare("SELECT * FROM medication_multi_form WHERE medicationId = :id");
        $stmt2->execute([':id' => $medicationId]);
        $multiForms = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        foreach ($multiForms as &$mf) {
            $mf['medicationMultiFormId'] = (int) $mf['medicationMultiFormId'];
            $mf['medicationId'] = (int) $mf['medicationId'];
            $mf['conversionFactor'] = (float) $mf['conversionFactor'];
        }
        $row['multiForms'] = $multiForms;

        // --- 10. Ambil selling_price ---
        $stmt3 = $Conn->prepare("SELECT * FROM medication_selling_price WHERE medicationId = :id");
        $stmt3->execute([':id' => $medicationId]);
        $sellingPrices = $stmt3->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sellingPrices as &$sp) {
            $sp['medicationSellingPriceId'] = (int) $sp['medicationSellingPriceId'];
            $sp['medicationId'] = (int) $sp['medicationId'];
            $sp['medicationSellingPrice'] = (float) $sp['medicationSellingPrice'];
        }
        $row['sellingPrices'] = $sellingPrices;

        // --- 11. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Detail obat/alkes berhasil diambil",
                "code" => 200
            ],
            "metadata" => [
                "medicationId" => $medicationId,
                "retrieved_at" => $nowUtc . ' GMT'
            ],
            "data" => $row
        ]);

    } catch (PDOException $e) {
        error_log('[DetailMedication] Query error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }
?>