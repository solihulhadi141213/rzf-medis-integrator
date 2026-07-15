<?php
    // Response Header
    header('Content-Type: application/json');
    header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: GET");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, x-token, token");

    date_default_timezone_set('UTC');

    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";

    // Ratelimit
    require "../../_Config/RateLimiter.php";

    $Limiter = new RateLimiter($Conn);

    // Maksimal 5 request setiap 60 detik
    $Limiter->check("list_service_feature",5,60);


    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            "response" => ["message" => "Metode request tidak diizinkan","code" => 405],
            "metadata" => []
        ]);
        exit;
    }

    function getRequestHeader(string $name): string
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $value) {
                if (strtolower($key) === strtolower($name)) return trim($value);
            }
        }
        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$headerKey]) ? trim($_SERVER[$headerKey]) : '';
    }

    $apiToken = getRequestHeader('token');
    if (empty($apiToken)) {
        http_response_code(401);
        echo json_encode(["response"=>["message"=>"Token tidak ditemukan atau tidak valid","code"=>401],"metadata"=>[]]);
        exit;
    }

    // read and sanitize query params
    $limit      = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    $page       = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $order_by   = isset($_GET['order_by']) ? validateAndSanitizeInput($_GET['order_by']) : 'id_service_feature';
    $short_by   = isset($_GET['short_by']) ? strtoupper(validateAndSanitizeInput($_GET['short_by'])) : 'ASC';
    $keyword_by = isset($_GET['keyword_by']) ? validateAndSanitizeInput($_GET['keyword_by']) : '';
    $keyword    = isset($_GET['keyword']) ? validateAndSanitizeInput($_GET['keyword']) : '';

    // enforce limits
    if ($limit < 10) $limit = 10;
    if ($limit > 100) $limit = 100;
    if ($page < 1) $page = 1;

    $allowedColumns = ['id_service_feature','feature_name','feature_category','feature_description','datetime_creat'];
    if (!in_array($order_by, $allowedColumns, true)) {
        http_response_code(400);
        echo json_encode(["response"=>["message"=>"order_by tidak valid","code"=>400],"metadata"=>[]]);
        exit;
    }

    if (!in_array($short_by, ['ASC','DESC'], true)) {
        http_response_code(400);
        echo json_encode(["response"=>["message"=>"short_by harus ASC atau DESC","code"=>400],"metadata"=>[]]);
        exit;
    }

    if ($keyword_by !== '' && !in_array($keyword_by, $allowedColumns, true)) {
        http_response_code(400);
        echo json_encode(["response"=>["message"=>"keyword_by tidak valid","code"=>400],"metadata"=>[]]);
        exit;
    }

    $offset = ($page - 1) * $limit;

    try {
        // validate token
        $stmt = $Conn->prepare("SELECT t.*, k.client_id, k.api_name, k.id_api_key FROM api_token t JOIN api_key k ON t.id_api_key = k.id_api_key WHERE t.token = :token LIMIT 1");
        $stmt->execute([':token' => $apiToken]);
        $tokenData = $stmt->fetch();

        if (!$tokenData) {
            http_response_code(401);
            echo json_encode(["response"=>["message"=>"Token tidak valid","code"=>401],"metadata"=>[]]);
            exit;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        if ($tokenData['datetime_expired'] < $nowUtc) {
            http_response_code(401);
            echo json_encode(["response"=>["message"=>"Token sudah kedaluwarsa","code"=>401],"metadata"=>[]]);
            exit;
        }

        // build where clause
        $where = "WHERE 1";
        $params = [];
        if ($keyword !== '' && $keyword_by !== '') {
            $where .= " AND `{$keyword_by}` LIKE :keyword";
            $params[':keyword'] = "%{$keyword}%";
        }

        // total count
        $countSql = "SELECT COUNT(*) AS total FROM service_feature " . $where;
        $countStmt = $Conn->prepare($countSql);
        $countStmt->execute($params);
        $countRow = $countStmt->fetch();
        $total = (int) ($countRow['total'] ?? 0);

        // data query
        $sql = "SELECT id_service_feature, feature_name, feature_category, feature_description FROM service_feature " . $where;
        $sql .= " ORDER BY `{$order_by}` {$short_by} LIMIT {$offset}, {$limit}";

        $stmt = $Conn->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            "response" => ["message" => "List service_feature berhasil diambil","code" => 200],
            "metadata" => [
                "total" => $total,
                "page" => $page,
                "limit" => $limit,
                "order_by" => $order_by,
                "short_by" => $short_by,
                "keyword_by" => $keyword_by,
                "keyword" => $keyword,
                "retrieved_at" => gmdate('Y-m-d H:i:s') . ' GMT'
            ],
            "data" => $rows
        ]);

    } catch (Exception $e) {
        // Log exception for debugging
        error_log('[ListsServiceFeature] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response"=>["message"=>"Internal Server Error","code"=>500],"metadata"=>["error"=> $e->getMessage()]]);
    }
