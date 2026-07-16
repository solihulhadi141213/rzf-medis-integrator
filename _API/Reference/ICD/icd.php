<?php
    // Response Header
    header('Content-Type: application/json');
    header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: GET");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, token");

    // Set Time Zone
    date_default_timezone_set('UTC');

    // Include-Require Resource
    include "../../../_Config/Connection.php";
    include "../../../_Config/Helper.php";
    require "../../../_Config/RateLimiter.php";

    // Limiter
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("get_icd", 5, 60);

    // Validate Method
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

    // Menangkap data dari header (token)
    $apiToken = getRequestHeader('token');

    // Validasi token
    if (empty($apiToken)) {
        http_response_code(401);
        echo json_encode(["response" => ["message" => "Token Kredensial Aplikasi Tidak Boleh Kosong", "code" => 401], "metadata" => []]);
        exit;
    }

    // Params
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    if ($limit < 10) $limit = 10;
    if ($limit > 100) $limit = 100;

    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    if ($page < 1) $page = 1;

    $allowedOrder = ['kode', 'long_des', 'short_des', 'icd'];
    $order_by = isset($_GET['order_by']) ? validateAndSanitizeInput($_GET['order_by']) : 'kode';
    if (!in_array($order_by, $allowedOrder)) $order_by = 'kode';

    $short_by = isset($_GET['short_by']) ? strtoupper(validateAndSanitizeInput($_GET['short_by'])) : 'DESC';
    if ($short_by !== 'ASC') $short_by = 'DESC';

    $keyword_by = isset($_GET['keyword_by']) ? validateAndSanitizeInput($_GET['keyword_by']) : '';
    $keyword = isset($_GET['keyword']) ? validateAndSanitizeInput($_GET['keyword']) : '';

    try {
        $stmt = $Conn->prepare("SELECT t.*, k.client_id, k.api_name, k.id_api_key FROM api_token t JOIN api_key k ON t.id_api_key = k.id_api_key WHERE t.token = :token LIMIT 1");
        $stmt->execute([':token' => $apiToken]);
        $tokenData = $stmt->fetch();
        if (!$tokenData) {
            http_response_code(401);
            echo json_encode(["response" => ["message" => "Token tidak valid", "code" => 401], "metadata" => []]);
            exit;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        if ($tokenData['datetime_expired'] < $nowUtc) {
            http_response_code(401);
            echo json_encode(["response" => ["message" => "Token sudah kedaluwarsa", "code" => 401], "metadata" => []]);
            exit;
        }

        $where = '';
        $params = [];
        if (!empty($keyword_by) && !empty($keyword)) {
            if (!in_array($keyword_by, $allowedOrder)) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "keyword_by tidak valid", "code" => 422], "metadata" => []]);
                exit;
            }
            $where = "WHERE `$keyword_by` LIKE :keyword";
            $params[':keyword'] = "%{$keyword}%";
        }

        // Total
        $countSql = "SELECT COUNT(*) as total FROM icd $where";
        $stmt = $Conn->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $offset = ($page - 1) * $limit;

        $sql = "SELECT id_icd, kode, long_des, short_des, icd FROM icd $where ORDER BY `$order_by` $short_by LIMIT :limit OFFSET :offset";
        $stmt = $Conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            "response" => ["message" => "Data ICD berhasil diambil", "code" => 200],
            "metadata" => ["total" => $total, "page" => $page, "limit" => $limit, "order_by" => $order_by, "short_by" => $short_by],
            "data" => $rows
        ]);
    } catch (Exception $e) {
        error_log('[ICD] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
    }
?>
