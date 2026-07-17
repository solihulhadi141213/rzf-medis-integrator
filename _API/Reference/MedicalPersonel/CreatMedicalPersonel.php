<?php
    /**
     * Create Medical Personel
     * Endpoint: POST /_API/Reference/MedicalPersonel/CreatMedicalPersonel.php
     * Header: token, account_token
     * Body: JSON
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
    include "../../../_Config/Connection.php";      // Harus menyediakan objek PDO $Conn
    include "../../../_Config/Helper.php";
    require "../../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("create_medical_personel", 5, 60); // Maks 5 request per menit

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

    // --- 6. Validasi API Token ---
    $nowUtc = gmdate('Y-m-d H:i:s');
    try {
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

        // --- 7. Validasi Account Token ---
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
    } catch (PDOException $e) {
        error_log('[CreateMedicalPersonel] DB error: ' . $e->getMessage());
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

    // --- 7.5 Validasi Permission ---
    try {
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'create_medical_personel']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode([
                "response" => [
                    "message" => "Fitur create_medical_personel tidak ditemukan",
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
                    "message" => "Tidak memiliki izin untuk menambah tenaga medis",
                    "code" => 403
                ],
                "metadata" => []
            ]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateMedicalPersonel] Permission check error: ' . $e->getMessage());
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

    // --- 9. Validasi Field Wajib ---
    $requiredFields = ['medicalPersonelCode', 'medicalPersonelCategory', 'name', 'gender', 'citizenshipStatus'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || trim($input[$field]) === '') {
            http_response_code(400);
            echo json_encode([
                "response" => [
                    "message" => "Field '$field' wajib diisi",
                    "code" => 400
                ],
                "metadata" => []
            ]);
            exit;
        }
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

    // --- 11. Ambil dan Trim Nilai ---
    $medicalPersonelCode = trim($input['medicalPersonelCode']);
    $id_practitioner      = isset($input['id_practitioner']) ? trim($input['id_practitioner']) : null;
    $medicalPersonelCategory = trim($input['medicalPersonelCategory']);
    $nik                  = isset($input['nik']) ? trim($input['nik']) : null;
    $name                 = trim($input['name']);
    $gender               = trim($input['gender']);
    $email                = isset($input['email']) ? trim($input['email']) : null;
    $phone                = isset($input['phone']) ? trim($input['phone']) : null;
    $citizenshipStatus    = trim($input['citizenshipStatus']);
    $provinceId           = isset($input['provinceId']) ? (int) $input['provinceId'] : null;
    $cityId               = isset($input['cityId']) ? (int) $input['cityId'] : null;
    $districtId           = isset($input['districtId']) ? (int) $input['districtId'] : null;
    $villageId            = isset($input['villageId']) ? (int) $input['villageId'] : null;
    $postalCode           = isset($input['postalCode']) ? trim($input['postalCode']) : null;
    $address              = isset($input['address']) ? trim($input['address']) : null;
    $photo                = isset($input['photo']) ? trim($input['photo']) : null;
    $status               = isset($input['status']) ? (int) $input['status'] : 1;
    $accountId            = isset($input['accountId']) ? (int) $input['accountId'] : null;

    // --- 12. Validasi medicalPersonelCode (max 20, unik) ---
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
        $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE medicalPersonelCode = :code LIMIT 1");
        $stmt->execute([':code' => $medicalPersonelCode]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode([
                "response" => [
                    "message" => "medicalPersonelCode sudah digunakan",
                    "code" => 409
                ],
                "metadata" => []
            ]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreateMedicalPersonel] Check code error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 13. Validasi Kategori ---
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

    // --- 14. Validasi Gender ---
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

    // --- 15. Validasi Citizenship Status ---
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

    // --- 16. Validasi Status ---
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

    // --- 17. Validasi NIK (jika diisi) ---
    if ($nik !== null && $nik !== '') {
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
        // Cek apakah NIK sudah digunakan oleh tenaga medis lain (unik)
        try {
            $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE nik = :nik LIMIT 1");
            $stmt->execute([':nik' => $nik]);
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
            error_log('[CreateMedicalPersonel] Check NIK error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 18. Validasi Email (jika diisi) ---
    if ($email !== null && $email !== '') {
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
            $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
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
            error_log('[CreateMedicalPersonel] Check email error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 19. Validasi Wilayah (jika ada) ---
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
            error_log('[CreateMedicalPersonel] Check province error: ' . $e->getMessage());
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
            error_log('[CreateMedicalPersonel] Check city error: ' . $e->getMessage());
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
            error_log('[CreateMedicalPersonel] Check district error: ' . $e->getMessage());
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
            error_log('[CreateMedicalPersonel] Check village error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 20. Validasi accountId (jika diisi) ---
    if (!empty($accountId)) {
        try {
            $stmt = $Conn->prepare("SELECT accountId FROM account WHERE accountId = :id LIMIT 1");
            $stmt->execute([':id' => $accountId]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode([
                    "response" => [
                        "message" => "accountId $accountId tidak ditemukan",
                        "code" => 400
                    ],
                    "metadata" => []
                ]);
                exit;
            }
            // Cek apakah accountId sudah terhubung dengan tenaga medis lain
            $stmt = $Conn->prepare("SELECT medicalPersonelId FROM medical_personel WHERE accountId = :id LIMIT 1");
            $stmt->execute([':id' => $accountId]);
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
            error_log('[CreateMedicalPersonel] Check accountId error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 21. Proses Upload Foto ---
    $photoFilename = null;
    if ($photo !== null && $photo !== '') {
        // Ekstrak data URI
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $photo, $matches)) {
            $imageType = strtolower($matches[1]);
            $base64Data = $matches[2];
            $binaryData = base64_decode($base64Data);
            if ($binaryData === false) {
                http_response_code(400);
                echo json_encode([
                    "response" => [
                        "message" => "Data base64 foto tidak valid",
                        "code" => 400
                    ],
                    "metadata" => []
                ]);
                exit;
            }
            // Validasi tipe gambar yang diizinkan
            $allowedImageTypes = ['jpeg', 'png', 'webp'];
            if (!in_array($imageType, $allowedImageTypes, true)) {
                http_response_code(400);
                echo json_encode([
                    "response" => [
                        "message" => "Format foto hanya diperbolehkan: jpeg, png, webp",
                        "code" => 400
                    ],
                    "metadata" => []
                ]);
                exit;
            }
            // Tentukan ekstensi
            $ext = $imageType === 'jpeg' ? 'jpg' : $imageType;
            // Buat nama file unik
            $photoFilename = md5(uniqid() . '_' . time()) . '.' . $ext;
            // Tentukan path tujuan (root/Storage/Img/MedicalPersonel)
            $uploadDir = __DIR__ . '/../../../Storage/Img/MedicalPersonel/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    http_response_code(500);
                    echo json_encode([
                        "response" => [
                            "message" => "Gagal membuat direktori penyimpanan foto",
                            "code" => 500
                        ],
                        "metadata" => []
                    ]);
                    exit;
                }
            }
            $filePath = $uploadDir . $photoFilename;
            if (file_put_contents($filePath, $binaryData) === false) {
                http_response_code(500);
                echo json_encode([
                    "response" => [
                        "message" => "Gagal menyimpan file foto",
                        "code" => 500
                    ],
                    "metadata" => []
                ]);
                exit;
            }
            // Opsional: compress/resize? skip.
        } else {
            http_response_code(400);
            echo json_encode([
                "response" => [
                    "message" => "Format foto harus berupa data URI base64 yang valid (data:image/...)",
                    "code" => 400
                ],
                "metadata" => []
            ]);
            exit;
        }
    }

    // --- 22. Insert Data ---
    try {
        $sql = "INSERT INTO medical_personel (
                    medicalPersonelCode,
                    id_practitioner,
                    medicalPersonelCategory,
                    nik,
                    name,
                    gender,
                    email,
                    phone,
                    citizenshipStatus,
                    provinceId,
                    cityId,
                    districtId,
                    villageId,
                    postalCode,
                    address,
                    photo,
                    status,
                    createdBy,
                    createdDate,
                    updatedBy,
                    updatedDate,
                    accountId
                ) VALUES (
                    :medicalPersonelCode,
                    :id_practitioner,
                    :medicalPersonelCategory,
                    :nik,
                    :name,
                    :gender,
                    :email,
                    :phone,
                    :citizenshipStatus,
                    :provinceId,
                    :cityId,
                    :districtId,
                    :villageId,
                    :postalCode,
                    :address,
                    :photo,
                    :status,
                    :createdBy,
                    :createdDate,
                    :updatedBy,
                    :updatedDate,
                    :accountId
                )";

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
            ':photo' => $photoFilename,
            ':status' => $status,
            ':createdBy' => $loggedInAccountId,
            ':createdDate' => $nowUtc,
            ':updatedBy' => $loggedInAccountId,
            ':updatedDate' => $nowUtc,
            ':accountId' => $accountId
        ]);

        $newId = (int) $Conn->lastInsertId();

        // --- 23. Response Sukses ---
        http_response_code(201);
        echo json_encode([
            "response" => [
                "message" => "Tenaga medis berhasil ditambahkan",
                "code" => 201
            ],
            "metadata" => [
                "medicalPersonelId" => $newId,
                "created_at" => $nowUtc . ' GMT'
            ],
            "data" => [
                "medicalPersonelId" => $newId,
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
                "photo" => $photoFilename,
                "status" => $status,
                "createdBy" => $loggedInAccountId,
                "createdDate" => $nowUtc,
                "updatedBy" => $loggedInAccountId,
                "updatedDate" => $nowUtc,
                "accountId" => $accountId
            ]
        ]);

    } catch (PDOException $e) {
        error_log('[CreateMedicalPersonel] Insert error: ' . $e->getMessage());
        // Jika gagal, hapus foto yang sudah diupload (jika ada)
        if ($photoFilename && file_exists($uploadDir . $photoFilename)) {
            unlink($uploadDir . $photoFilename);
        }
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