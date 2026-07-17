<?php
    /**
     * Update Medical Personel
     * Endpoint: PUT /_API/Reference/MedicalPersonel/UpdateMedicalPersonel.php?medicalPersonelId={id}
     * Header: token, account_token
     * Body: JSON (field yang ingin diubah)
     */

    // --- 1. Response Header ---
    header('Content-Type: application/json');
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: PUT");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, token, account_token");

    date_default_timezone_set('UTC');

    // --- 2. Include Dependencies ---
    include "../../../_Config/Connection.php";
    include "../../../_Config/Helper.php";
    require "../../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("update_medical_personel", 5, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

    // --- 6. Validasi Parameter medicalPersonelId ---
    if (!isset($_GET['medicalPersonelId']) || !is_numeric($_GET['medicalPersonelId']) || (int)$_GET['medicalPersonelId'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "Parameter medicalPersonelId wajib diisi dengan angka positif",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }
    $medicalPersonelId = (int) $_GET['medicalPersonelId'];

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

        // Validasi Permission (fitur update_medical_personel)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'update_medical_personel']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Fitur update_medical_personel tidak ditemukan",
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
                    "message" => "Tidak memiliki izin untuk mengubah tenaga medis",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[UpdateMedicalPersonel] DB/Permission error: ' . $e->getMessage());
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

    // --- 8. Ambil dan Decode Body JSON ---
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

    // --- 9. Cek apakah data dengan ID tersebut ada ---
    try {
        $stmt = $Conn->prepare("SELECT * FROM medical_personel WHERE medicalPersonelId = :id LIMIT 1");
        $stmt->execute([':id' => $medicalPersonelId]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingData) {
            http_response_code(404);
            echo json_encode([
                "response" => [
                    "message" => "Tenaga medis tidak ditemukan",
                    "code" => 404
                ],
                "metadata" => []
            ]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[UpdateMedicalPersonel] Check existence error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 10. Definisi Daftar Nilai yang Diizinkan ---
    $allowedCategories = [
        'Dokter Umum', 'Dokter Spesialis', 'Perawat', 'Bidan',
        'Rekam Medis', 'Administrasi', 'Apoteker', 'Analis Laboratorium',
        'Radiografer', 'Terapis', 'Gizi', 'Penata Anestesi',
        'Elektromedis', 'Sanitarian', 'Epidemiolog'
    ];
    $allowedGender = ['Male', 'Female'];
    $allowedCitizenship = ['WNI', 'WNA'];
    $allowedStatus = [0, 1];

    // --- 11. Ambil dan Trim Nilai dari Body ---
    // Semua field opsional, jika tidak ada gunakan nilai lama
    $medicalPersonelCode = isset($input['medicalPersonelCode']) ? trim($input['medicalPersonelCode']) : $existingData['medicalPersonelCode'];
    $id_practitioner     = isset($input['id_practitioner']) ? trim($input['id_practitioner']) : $existingData['id_practitioner'];
    $medicalPersonelCategory = isset($input['medicalPersonelCategory']) ? trim($input['medicalPersonelCategory']) : $existingData['medicalPersonelCategory'];
    $nik                 = isset($input['nik']) ? trim($input['nik']) : $existingData['nik'];
    $name                = isset($input['name']) ? trim($input['name']) : $existingData['name'];
    $gender              = isset($input['gender']) ? trim($input['gender']) : $existingData['gender'];
    $email               = isset($input['email']) ? trim($input['email']) : $existingData['email'];
    $phone               = isset($input['phone']) ? trim($input['phone']) : $existingData['phone'];
    $citizenshipStatus   = isset($input['citizenshipStatus']) ? trim($input['citizenshipStatus']) : $existingData['citizenshipStatus'];
    $provinceId          = isset($input['provinceId']) ? (int) $input['provinceId'] : $existingData['provinceId'];
    $cityId              = isset($input['cityId']) ? (int) $input['cityId'] : $existingData['cityId'];
    $districtId          = isset($input['districtId']) ? (int) $input['districtId'] : $existingData['districtId'];
    $villageId           = isset($input['villageId']) ? (int) $input['villageId'] : $existingData['villageId'];
    $postalCode          = isset($input['postalCode']) ? trim($input['postalCode']) : $existingData['postalCode'];
    $address             = isset($input['address']) ? trim($input['address']) : $existingData['address'];
    $status              = isset($input['status']) ? (int) $input['status'] : (int) $existingData['status'];
    $accountId           = isset($input['accountId']) ? (int) $input['accountId'] : $existingData['accountId'];

    // --- 12. Validasi Field yang Diubah (jika ada perubahan) ---

    // medicalPersonelCode: max 20, unik kecuali dirinya sendiri
    if ($medicalPersonelCode !== $existingData['medicalPersonelCode']) {
        if (strlen($medicalPersonelCode) > 20) {
            http_response_code(400);
            echo json_encode([
                "response" => [
                    "message" => "medicalPersonelCode maksimal 20 karakter",
                    "code" => 400
                ],
                "metadata" => []
            ]);
            exit;
        }
        try {
            $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE medicalPersonelCode = :code AND medicalPersonelId != :id LIMIT 1");
            $stmt->execute([':code' => $medicalPersonelCode, ':id' => $medicalPersonelId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode([
                    "response" => [
                        "message" => "medicalPersonelCode sudah digunakan oleh tenaga medis lain",
                        "code" => 409
                    ],
                    "metadata" => []
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateMedicalPersonel] Check code error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // id_practitioner: jika diubah, cek duplikat (opsional, tapi kita cek)
    if ($id_practitioner !== $existingData['id_practitioner'] && $id_practitioner !== null && $id_practitioner !== '') {
        try {
            $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE id_practitioner = :id_practitioner AND medicalPersonelId != :id LIMIT 1");
            $stmt->execute([':id_practitioner' => $id_practitioner, ':id' => $medicalPersonelId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode([
                    "response" => [
                        "message" => "id_practitioner sudah digunakan oleh tenaga medis lain",
                        "code" => 409
                    ],
                    "metadata" => []
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateMedicalPersonel] Check id_practitioner error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // NIK: jika diubah, cek duplikat dan format 16 digit
    if ($nik !== $existingData['nik'] && $nik !== null && $nik !== '') {
        if (!preg_match('/^\d{16}$/', $nik)) {
            http_response_code(400);
            echo json_encode([
                "response" => [
                    "message" => "NIK harus terdiri dari 16 digit angka",
                    "code" => 400
                ],
                "metadata" => []
            ]);
            exit;
        }
        try {
            $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE nik = :nik AND medicalPersonelId != :id LIMIT 1");
            $stmt->execute([':nik' => $nik, ':id' => $medicalPersonelId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode([
                    "response" => [
                        "message" => "NIK sudah terdaftar untuk tenaga medis lain",
                        "code" => 409
                    ],
                    "metadata" => []
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateMedicalPersonel] Check NIK error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // Email: jika diubah, cek duplikat dan format
    if ($email !== $existingData['email'] && $email !== null && $email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode([
                "response" => [
                    "message" => "Format email tidak valid",
                    "code" => 400
                ],
                "metadata" => []
            ]);
            exit;
        }
        try {
            $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE email = :email AND medicalPersonelId != :id LIMIT 1");
            $stmt->execute([':email' => $email, ':id' => $medicalPersonelId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode([
                    "response" => [
                        "message" => "Email sudah digunakan oleh tenaga medis lain",
                        "code" => 409
                    ],
                    "metadata" => []
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateMedicalPersonel] Check email error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // Phone: jika diubah, cek duplikat (opsional, tapi kita cek)
    if ($phone !== $existingData['phone'] && $phone !== null && $phone !== '') {
        // Bisa tambahkan validasi format phone jika diperlukan
        try {
            $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE phone = :phone AND medicalPersonelId != :id LIMIT 1");
            $stmt->execute([':phone' => $phone, ':id' => $medicalPersonelId]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode([
                    "response" => [
                        "message" => "Nomor telepon sudah digunakan oleh tenaga medis lain",
                        "code" => 409
                    ],
                    "metadata" => []
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateMedicalPersonel] Check phone error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // accountId: jika diubah, cek keberadaan dan duplikat
    if ($accountId !== $existingData['accountId']) {
        if ($accountId !== null) {
            try {
                $stmt = $Conn->prepare("SELECT accountId FROM account WHERE accountId = :id LIMIT 1");
                $stmt->execute([':id' => $accountId]);
                if (!$stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode([
                        "response" => [
                            "message" => "accountId tidak ditemukan",
                            "code" => 400
                        ],
                        "metadata" => []
                    ]);
                    exit;
                }
                // Cek apakah accountId sudah terhubung dengan tenaga medis lain
                $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE accountId = :accountId AND medicalPersonelId != :id LIMIT 1");
                $stmt->execute([':accountId' => $accountId, ':id' => $medicalPersonelId]);
                if ($stmt->fetch()) {
                    http_response_code(409);
                    echo json_encode([
                        "response" => [
                            "message" => "accountId sudah terhubung dengan tenaga medis lain",
                            "code" => 409
                        ],
                        "metadata" => []
                    ]);
                    exit;
                }
            } catch (PDOException $e) {
                error_log('[UpdateMedicalPersonel] Check accountId error: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
                exit;
            }
        }
    }

    // --- 13. Validasi field lainnya (kategori, gender, citizenship, status, wilayah) ---
    if (!in_array($medicalPersonelCategory, $allowedCategories, true)) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "medicalPersonelCategory tidak valid. Nilai yang diizinkan: " . implode(', ', $allowedCategories),
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    if (!in_array($gender, $allowedGender, true)) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "gender harus Male atau Female",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    if (!in_array($citizenshipStatus, $allowedCitizenship, true)) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "citizenshipStatus harus WNI atau WNA",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    if (!in_array($status, $allowedStatus, true)) {
        http_response_code(400);
        echo json_encode([
            "response" => [
                "message" => "status harus 0 atau 1",
                "code" => 400
            ],
            "metadata" => []
        ]);
        exit;
    }

    // Validasi wilayah jika ada nilai
    if ($provinceId !== null) {
        try {
            $stmt = $Conn->prepare("SELECT provinceId FROM region_province WHERE provinceId = :id LIMIT 1");
            $stmt->execute([':id' => $provinceId]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode([
                    "response" => [
                        "message" => "provinceId tidak ditemukan",
                        "code" => 400
                    ],
                    "metadata" => []
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateMedicalPersonel] Check province error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }
    if ($cityId !== null) {
        try {
            $stmt = $Conn->prepare("SELECT cityId FROM region_city WHERE cityId = :id AND provinceId = :provinceId LIMIT 1");
            $stmt->execute([':id' => $cityId, ':provinceId' => $provinceId]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode([
                    "response" => [
                        "message" => "cityId tidak ditemukan atau tidak sesuai dengan provinceId",
                        "code" => 400
                    ],
                    "metadata" => []
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateMedicalPersonel] Check city error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }
    if ($districtId !== null) {
        try {
            $stmt = $Conn->prepare("SELECT districtId FROM region_district WHERE districtId = :id AND cityId = :cityId LIMIT 1");
            $stmt->execute([':id' => $districtId, ':cityId' => $cityId]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode([
                    "response" => [
                        "message" => "districtId tidak ditemukan atau tidak sesuai dengan cityId",
                        "code" => 400
                    ],
                    "metadata" => []
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateMedicalPersonel] Check district error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }
    if ($villageId !== null) {
        try {
            $stmt = $Conn->prepare("SELECT villageId FROM region_village WHERE villageId = :id AND districtId = :districtId LIMIT 1");
            $stmt->execute([':id' => $villageId, ':districtId' => $districtId]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode([
                    "response" => [
                        "message" => "villageId tidak ditemukan atau tidak sesuai dengan districtId",
                        "code" => 400
                    ],
                    "metadata" => []
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[UpdateMedicalPersonel] Check village error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 14. Update Data ---
    try {
        $updatedDate = $nowUtc;
        $sql = "UPDATE medical_personel SET
                    medicalPersonelCode = :medicalPersonelCode,
                    id_practitioner = :id_practitioner,
                    medicalPersonelCategory = :medicalPersonelCategory,
                    nik = :nik,
                    name = :name,
                    gender = :gender,
                    email = :email,
                    phone = :phone,
                    citizenshipStatus = :citizenshipStatus,
                    provinceId = :provinceId,
                    cityId = :cityId,
                    districtId = :districtId,
                    villageId = :villageId,
                    postalCode = :postalCode,
                    address = :address,
                    status = :status,
                    updatedBy = :updatedBy,
                    updatedDate = :updatedDate,
                    accountId = :accountId
                WHERE medicalPersonelId = :id";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':medicalPersonelCode' => $medicalPersonelCode,
            ':id_practitioner' => $id_practitioner,
            ':medicalPersonelCategory' => $medicalPersonelCategory,
            ':nik' => $nik,
            ':name' => $name,
            ':gender' => $gender,
            ':email' => $email,
            ':phone' => $phone,
            ':citizenshipStatus' => $citizenshipStatus,
            ':provinceId' => $provinceId,
            ':cityId' => $cityId,
            ':districtId' => $districtId,
            ':villageId' => $villageId,
            ':postalCode' => $postalCode,
            ':address' => $address,
            ':status' => $status,
            ':updatedBy' => $loggedInAccountId,
            ':updatedDate' => $updatedDate,
            ':accountId' => $accountId,
            ':id' => $medicalPersonelId
        ]);

        // --- 15. Response Sukses ---
        http_response_code(200);
        echo json_encode([
            "response" => [
                "message" => "Tenaga medis berhasil diperbarui",
                "code" => 200
            ],
            "metadata" => [
                "medicalPersonelId" => $medicalPersonelId,
                "updated_at" => $updatedDate . ' GMT'
            ],
            "data" => [
                "medicalPersonelId" => $medicalPersonelId,
                "medicalPersonelCode" => $medicalPersonelCode,
                "id_practitioner" => $id_practitioner,
                "medicalPersonelCategory" => $medicalPersonelCategory,
                "nik" => $nik,
                "name" => $name,
                "gender" => $gender,
                "email" => $email,
                "phone" => $phone,
                "citizenshipStatus" => $citizenshipStatus,
                "provinceId" => $provinceId,
                "cityId" => $cityId,
                "districtId" => $districtId,
                "villageId" => $villageId,
                "postalCode" => $postalCode,
                "address" => $address,
                "photo" => $existingData['photo'], // foto tetap seperti sebelumnya
                "status" => $status,
                "createdBy" => (int) $existingData['createdBy'],
                "createdDate" => $existingData['createdDate'],
                "updatedBy" => $loggedInAccountId,
                "updatedDate" => $updatedDate,
                "accountId" => $accountId
            ]
        ]);

    } catch (PDOException $e) {
        error_log('[UpdateMedicalPersonel] Update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "response" => [
                "message" => "Gagal memperbarui data: " . $e->getMessage(),
                "code" => 500
            ],
            "metadata" => []
        ]);
        exit;
    }
?>