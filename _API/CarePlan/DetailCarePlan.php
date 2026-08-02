<?php
    /**
     * Detail Care Plan
     * Endpoint: GET /_API/CarePlan/DetailCarePlan.php?carePlanId={id}
     * Header: token, account_token
     *
     * Menampilkan detail Care Plan termasuk informasi:
     * - Data Care Plan
     * - Informasi Pasien (patient)
     * - Informasi Kunjungan (encounter)
     * - Informasi Tenaga Medis (medical_personel)
     * - Informasi Pembuat dan Pengubah (account)
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
    $Limiter->check("detail_care_plan", 10, 60);

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

    // --- 6. Validasi Parameter carePlanId ---
    if (!isset($_GET['carePlanId']) || !is_numeric($_GET['carePlanId']) || (int)$_GET['carePlanId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => ["message" => "Parameter carePlanId wajib diisi dengan angka positif", "code" => 400],
            "metadata" => []
        ]);
        exit;
    }
    $carePlanId = (int) $_GET['carePlanId'];

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

        // Validasi Permission (fitur detail_care_plan)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'detail_care_plan']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Fitur detail_care_plan tidak ditemukan", "code" => 403], "metadata" => []]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk melihat detail Care Plan", "code" => 403], "metadata" => []]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[DetailCarePlan] DB/Permission error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 8. Query detail dengan JOIN ke tabel terkait ---
    try {
        $sql = "SELECT
                    cp.carePlanId,
                    cp.patientId,
                    p.name AS patientName,
                    p.noMedicalRecord,
                    p.gender AS patientGender,
                    p.birthDate AS patientBirthDate,
                    p.nik AS patientNik,
                    p.phone AS patientPhone,
                    cp.encounterId,
                    e.EncounterCode,
                    e.registrationDatetime,
                    e.status AS encounterStatus,
                    cp.medicalPersonelId,
                    mp.name AS medicalPersonelName,
                    mp.medicalPersonelCategory,
                    mp.id_practitioner,
                    cp.satuSehatCode,
                    cp.carePlanTitle,
                    cp.carePlanStatus,
                    cp.carePlanIntent,
                    cp.carePlanCategoryName,
                    cp.carePlanCategoryCode,
                    cp.carePlanCategoryDisplay,
                    cp.carePlanCategorySystem,
                    cp.carePlaneDescription,
                    cp.creatAt,
                    cp.updateAt,
                    cp.creatBy,
                    cAccount.name AS createdName,
                    cp.updateBy,
                    uAccount.name AS updatedName
                FROM care_plan cp
                LEFT JOIN patient p ON cp.patientId = p.patientId
                LEFT JOIN encounter e ON cp.encounterId = e.encounterId
                LEFT JOIN medical_personel mp ON cp.medicalPersonelId = mp.medicalPersonelId
                LEFT JOIN account cAccount ON cp.creatBy = cAccount.accountId
                LEFT JOIN account uAccount ON cp.updateBy = uAccount.accountId
                WHERE cp.carePlanId = :id
                LIMIT 1";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([':id' => $carePlanId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            http_response_code(404);
            echo json_encode([
                "response" => ["message" => "Care Plan tidak ditemukan", "code" => 404],
                "metadata" => []
            ]);
            exit;
        }

        // --- 9. Format data ---
        $data['carePlanId'] = (int) $data['carePlanId'];
        $data['patientId'] = (int) $data['patientId'];
        $data['encounterId'] = (int) $data['encounterId'];
        $data['medicalPersonelId'] = (int) $data['medicalPersonelId'];
        $data['creatBy'] = $data['creatBy'] !== null ? (int) $data['creatBy'] : null;
        $data['updateBy'] = $data['updateBy'] !== null ? (int) $data['updateBy'] : null;

        // Hapus null values untuk field opsional
        $nullFields = [
            'patientName', 'noMedicalRecord', 'patientGender', 'patientBirthDate', 'patientNik', 'patientPhone',
            'EncounterCode', 'registrationDatetime', 'encounterStatus',
            'medicalPersonelName', 'medicalPersonelCategory', 'id_practitioner',
            'carePlanCategoryName', 'carePlanCategoryCode', 'carePlanCategoryDisplay', 'carePlanCategorySystem',
            'carePlaneDescription', 'createdName', 'updatedName'
        ];
        foreach ($nullFields as $field) {
            if (isset($data[$field]) && $data[$field] === null) {
                unset($data[$field]);
            }
        }

        // --- 10. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Detail Care Plan berhasil diambil",
                "code" => 200
            ],
            "metadata" => [
                "carePlanId" => $carePlanId,
                "retrieved_at" => $nowUtc . ' GMT'
            ],
            "data" => $data
        ]);

    } catch (PDOException $e) {
        error_log('[DetailCarePlan] Query error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }
?>