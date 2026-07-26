<?php
    /**
     * API Allergy List
     * Method: GET
     * Headers: token, account_token
     * Parameters:
     *   - limit     (int, default 10, min 10, max 100)
     *   - page      (int, default 1)
     *   - order_by  (string, default 'allergyId')
     *   - short_by  (string, ASC/DESC, default DESC)
     *   - keyword_by (string, field to search, default 'all')
     *   - keyword   (string, search term)
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
    include "../../_Config/Connection.php";     // $Conn adalah PDO
    include "../../_Config/Helper.php";        // fungsi ValidatePermission, getRequestHeader, dll
    require "../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("list_allergy", 10, 60);

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
    $headers = getallheaders();
    $apiToken     = $headers['token'] ?? '';
    $accountToken = $headers['account_token'] ?? '';

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

    // --- 6. Validasi Token dan Permission (menggunakan PDO) ---
    $nowUtc = gmdate('Y-m-d H:i:s');

    try {
        // 6a. Validasi API Token
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

        // 6b. Validasi Account Token
        $stmt = $Conn->prepare("
            SELECT accountId 
            FROM account_token 
            WHERE account_token = :account_token 
            AND datetime_expired >= :nowUtc 
            LIMIT 1
        ");
        $stmt->execute([
            ':account_token' => $accountToken,
            ':nowUtc'        => $nowUtc
        ]);
        $accountTokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$accountTokenData) {
            http_response_code(401);
            echo json_encode(["response" => ["message" => "account_token tidak valid", "code" => 401], "metadata" => []]);
            exit;
        }
        $loggedInAccountId = (int) $accountTokenData['accountId'];

        // 6c. Validasi Permission (fitur list_allergy)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'list_allergy']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Fitur list_allergy tidak ditemukan", "code" => 403], "metadata" => []]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk melihat data allergy", "code" => 403], "metadata" => []]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[ListAllergy] DB/Permission error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 7. Ambil Parameter GET ---
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $limit = max(10, min(100, $limit)); // min 10, max 100

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $page = max(1, $page);
    $offset = ($page - 1) * $limit;

    $order_by = isset($_GET['order_by']) ? $_GET['order_by'] : 'allergyId';
    $short_by = isset($_GET['short_by']) ? strtoupper($_GET['short_by']) : 'DESC';

    $keyword_by = isset($_GET['keyword_by']) ? $_GET['keyword_by'] : 'all';
    $keyword    = isset($_GET['keyword']) ? $_GET['keyword'] : '';

    // --- 8. Validasi Order dan Sort ---
    $allowed_order = ['allergyId', 'patientId', 'encounterId', 'allergenName', 'clinicalStatus', 'verificationStatus', 'creatAt'];
    $order_by = in_array($order_by, $allowed_order) ? $order_by : 'allergyId';
    $short_by = ($short_by === 'ASC') ? 'ASC' : 'DESC';

    // --- 9. Bangun Query Dasar ---
    $sql = "SELECT a.*, 
                p.name AS patient_name, 
                p.nik AS patient_nik,
                e.EncounterCode AS encounter_code,
                mp.name AS medical_personel_name
            FROM allergy a
            LEFT JOIN patient p ON a.patientId = p.patientId
            LEFT JOIN encounter e ON a.encounterId = e.encounterId
            LEFT JOIN medical_personel mp ON a.medicalPersonelId = mp.medicalPersonelId
            WHERE 1=1";

    $params = [];

    // --- 10. Filter Pencarian ---
    if (!empty($keyword)) {
        $keywordLike = "%$keyword%";
        if ($keyword_by === 'patient_name') {
            $sql .= " AND p.name LIKE :keyword";
            $params[':keyword'] = $keywordLike;
        } elseif ($keyword_by === 'allergen_name') {
            $sql .= " AND a.allergenName LIKE :keyword";
            $params[':keyword'] = $keywordLike;
        } elseif ($keyword_by === 'allergen_category') {
            $sql .= " AND a.allergenCategory LIKE :keyword";
            $params[':keyword'] = $keywordLike;
        } elseif ($keyword_by === 'clinical_status') {
            $sql .= " AND a.clinicalStatus LIKE :keyword";
            $params[':keyword'] = $keywordLike;
        } elseif ($keyword_by === 'verification_status') {
            $sql .= " AND a.verificationStatus LIKE :keyword";
            $params[':keyword'] = $keywordLike;
        } else {
            // Pencarian global
            $sql .= " AND (p.name LIKE :k1 OR a.allergenName LIKE :k2 
                        OR a.allergenCategory LIKE :k3 OR a.clinicalStatus LIKE :k4 
                        OR a.verificationStatus LIKE :k5)";
            $params[':k1'] = $keywordLike;
            $params[':k2'] = $keywordLike;
            $params[':k3'] = $keywordLike;
            $params[':k4'] = $keywordLike;
            $params[':k5'] = $keywordLike;
        }
    }

    // --- 11. Hitung Total Data (tanpa limit) ---
    $countSql = "SELECT COUNT(*) AS total FROM (" . $sql . ") AS count_query";
    $stmtCount = $Conn->prepare($countSql);
    foreach ($params as $key => &$val) {
        $stmtCount->bindParam($key, $val);
    }
    $stmtCount->execute();
    $totalData = (int) $stmtCount->fetchColumn();

    // --- 12. Ambil Data dengan Order dan Limit ---
    $sql .= " ORDER BY a.$order_by $short_by LIMIT :limit OFFSET :offset";
    $params[':limit']  = $limit;
    $params[':offset'] = $offset;

    $stmt = $Conn->prepare($sql);
    foreach ($params as $key => &$val) {
        $stmt->bindParam($key, $val);
    }
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format tanggal
    foreach ($data as &$row) {
        $row['creatAt'] = date('Y-m-d H:i:s', strtotime($row['creatAt']));
        if (!empty($row['updateAt'])) {
            $row['updateAt'] = date('Y-m-d H:i:s', strtotime($row['updateAt']));
        }
    }
    unset($row);

    // --- 13. Response ---
    $totalPages = ($limit > 0) ? ceil($totalData / $limit) : 0;
    http_response_code(200);
    echo json_encode([
        'status'  => 'success',
        'message' => 'Data alergi berhasil diambil.',
        'data'    => $data,
        'pagination' => [
            'current_page' => $page,
            'per_page'     => $limit,
            'total_data'   => $totalData,
            'total_pages'  => $totalPages,
            'next_page'    => ($page < $totalPages) ? $page + 1 : null,
            'prev_page'    => ($page > 1) ? $page - 1 : null
        ]
    ]);
    exit;
?>