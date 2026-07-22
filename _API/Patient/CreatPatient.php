<?php
    /**
     * Create Patient
     * Endpoint: POST /_API/Patient/Patient.php
     * Header: token, account_token
     * Body: JSON (lihat contoh)
     * 
     * - Jika satuSehatCode kosong, cari di SATUSEHAT berdasarkan NIK.
     * - Jika NIK tidak ditemukan atau error, proses tetap lanjut dengan satuSehatCode null.
     * - Validasi duplikat untuk noMedicalRecord, email, phone, nik.
     * - Menyimpan creatBy, creatAt, updateBy, updateAt dari akun yang login.
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
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";
    require "../../_Config/RateLimiter.php";

    // --- 3. Rate Limiter ---
    $Limiter = new RateLimiter($Conn);
    $Limiter->check("create_patient", 5, 60);

    // --- 4. Validasi Method ---
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
        $stmt->execute([':account_token' => $accountToken, ':nowUtc' => $nowUtc]);
        $accountTokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$accountTokenData) {
            http_response_code(401);
            echo json_encode(["response" => ["message" => "account_token tidak valid", "code" => 401], "metadata" => []]);
            exit;
        }
        $loggedInAccountId = (int) $accountTokenData['accountId'];

        // Validasi Permission (fitur create_patient)
        $stmt = $Conn->prepare("SELECT id_service_feature FROM service_feature WHERE feature_name = :feature_name LIMIT 1");
        $stmt->execute([':feature_name' => 'create_patient']);
        $feature = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$feature) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Fitur create_patient tidak ditemukan", "code" => 403], "metadata" => []]);
            exit;
        }
        $id_service_feature = (int) $feature['id_service_feature'];
        if (!ValidatePermission($Conn, $loggedInAccountId, $id_service_feature)) {
            http_response_code(403);
            echo json_encode(["response" => ["message" => "Tidak memiliki izin untuk menambah pasien", "code" => 403], "metadata" => []]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[CreatePatient] DB/Permission error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 7. Ambil dan Decode Body JSON ---
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["response" => ["message" => "Invalid JSON payload: " . json_last_error_msg(), "code" => 400], "metadata" => []]);
        exit;
    }

    // --- 8. Validasi Field Wajib ---
    $requiredFields = ['noMedicalRecord', 'name'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || trim($input[$field]) === '') {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Field '$field' wajib diisi", "code" => 422], "metadata" => []]);
            exit;
        }
    }

    // --- 9. Ambil dan Trim Nilai ---
    $noMedicalRecord = trim($input['noMedicalRecord']);
    $satuSehatCode   = isset($input['satuSehatCode']) ? trim($input['satuSehatCode']) : '';
    $isInfant        = isset($input['isInfant']) ? (int) $input['isInfant'] : 0;
    $name            = trim($input['name']);
    $email           = isset($input['email']) ? trim($input['email']) : '';
    $phone           = isset($input['phone']) ? trim($input['phone']) : '';
    $gender          = isset($input['gender']) ? trim($input['gender']) : null;
    $birthPlace      = isset($input['birthPlace']) ? trim($input['birthPlace']) : null;
    $birthDate       = isset($input['birthDate']) ? trim($input['birthDate']) : null;
    $nik             = isset($input['nik']) ? trim($input['nik']) : '';
    $religion        = isset($input['religion']) ? trim($input['religion']) : null;
    $martialStatus   = isset($input['martialStatus']) ? trim($input['martialStatus']) : null;
    $lastEducation   = isset($input['lastEducation']) ? trim($input['lastEducation']) : null;
    $occupation      = isset($input['occupation']) ? trim($input['occupation']) : null;
    $language        = isset($input['language']) ? trim($input['language']) : null;
    $ethnic          = isset($input['ethnic']) ? trim($input['ethnic']) : null;
    $citizenshipStatus = isset($input['citizenshipStatus']) ? trim($input['citizenshipStatus']) : 'WNI';
    $provinceId      = isset($input['provinceId']) ? (int) $input['provinceId'] : null;
    $cityId          = isset($input['cityId']) ? (int) $input['cityId'] : null;
    $districtId      = isset($input['districtId']) ? (int) $input['districtId'] : null;
    $villageId       = isset($input['villageId']) ? (int) $input['villageId'] : null;
    $rt              = isset($input['rt']) ? trim($input['rt']) : null;
    $rw              = isset($input['rw']) ? trim($input['rw']) : null;
    $postalCode      = isset($input['postalCode']) ? trim($input['postalCode']) : null;
    $address         = isset($input['address']) ? trim($input['address']) : null;
    $medicalRecordStatus = isset($input['medicalRecordStatus']) ? trim($input['medicalRecordStatus']) : 'Terdaftar';
    $oldMedicalRecord = isset($input['oldMedicalRecord']) ? trim($input['oldMedicalRecord']) : null;
    $motherMedicalRecord = isset($input['motherMedicalRecord']) ? trim($input['motherMedicalRecord']) : null;
    $kkNumber        = isset($input['kkNumber']) ? trim($input['kkNumber']) : null;
    $kkName          = isset($input['kkName']) ? trim($input['kkName']) : null;
    $photo           = isset($input['photo']) ? trim($input['photo']) : '';

    // --- 10. Validasi noMedicalRecord (unik, max 20) ---
    if (strlen($noMedicalRecord) > 20) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "noMedicalRecord maksimal 20 karakter", "code" => 422], "metadata" => []]);
        exit;
    }
    try {
        $stmt = $Conn->prepare("SELECT patientId FROM patient WHERE noMedicalRecord = :record LIMIT 1");
        $stmt->execute([':record' => $noMedicalRecord]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(["response" => ["message" => "noMedicalRecord sudah digunakan", "code" => 409], "metadata" => []]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[CreatePatient] Check noMedicalRecord error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
        exit;
    }

    // --- 11. Validasi Email ---
    if (!empty($email)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Format email tidak valid", "code" => 422], "metadata" => []]);
            exit;
        }
        try {
            $stmt = $Conn->prepare("SELECT patientId FROM patient WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(["response" => ["message" => "Email sudah digunakan oleh pasien lain", "code" => 409], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[CreatePatient] Check email error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 12. Validasi Phone ---
    if (!empty($phone)) {
        if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "Nomor telepon harus terdiri dari 10-15 digit angka", "code" => 422], "metadata" => []]);
            exit;
        }
        try {
            $stmt = $Conn->prepare("SELECT patientId FROM patient WHERE phone = :phone LIMIT 1");
            $stmt->execute([':phone' => $phone]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(["response" => ["message" => "Nomor telepon sudah digunakan oleh pasien lain", "code" => 409], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[CreatePatient] Check phone error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 13. Validasi NIK ---
    if (!empty($nik)) {
        if (!preg_match('/^\d{16}$/', $nik)) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "NIK harus terdiri dari 16 digit angka", "code" => 422], "metadata" => []]);
            exit;
        }
        try {
            $stmt = $Conn->prepare("SELECT patientId FROM patient WHERE nik = :nik LIMIT 1");
            $stmt->execute([':nik' => $nik]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(["response" => ["message" => "NIK sudah digunakan oleh pasien lain", "code" => 409], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[CreatePatient] Check NIK error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["response" => ["message" => "Internal Server Error", "code" => 500], "metadata" => []]);
            exit;
        }
    }

    // --- 14. Validasi Gender (konversi 1/2 ke Male/Female jika numeric) ---
    if ($gender !== null && $gender !== '') {
        if (is_numeric($gender)) {
            $gender = (int) $gender;
            if ($gender === 1) {
                $gender = 'Male';
            } elseif ($gender === 2) {
                $gender = 'Female';
            } else {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "gender numeric harus 1 (Male) atau 2 (Female)", "code" => 422], "metadata" => []]);
                exit;
            }
        } else {
            if (!in_array($gender, ['Male', 'Female'], true)) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "gender harus Male atau Female", "code" => 422], "metadata" => []]);
                exit;
            }
        }
    }

    // --- 15. Validasi Enum fields ---
    $allowedReligions = ['Islam','Kristen Protestan','Kristen Katolik','Hindu','Buddha','Konghucu','Lainnya'];
    $allowedMartial = ['Single','Married','Widowed','Divorced'];
    $allowedEducation = ['Tidak Sekolah','SD','SMP','SMA','D1','D2','D3','D4','S1','S2','S3'];
    $allowedOccupation = ['Tidak Bekerja','Wirausaha','Karyawan Swasta','ASN','TNI','POLRI'];
    $allowedCitizenship = ['WNI','WNA'];
    $allowedMedicalRecordStatus = ['Terdaftar','Meninggal','Retensi'];

    if ($religion !== null && $religion !== '' && !in_array($religion, $allowedReligions, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "religion tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($martialStatus !== null && $martialStatus !== '' && !in_array($martialStatus, $allowedMartial, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "martialStatus tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($lastEducation !== null && $lastEducation !== '' && !in_array($lastEducation, $allowedEducation, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "lastEducation tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }
    if ($occupation !== null && $occupation !== '' && !in_array($occupation, $allowedOccupation, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "occupation tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }
    if (!in_array($citizenshipStatus, $allowedCitizenship, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "citizenshipStatus harus WNI atau WNA", "code" => 422], "metadata" => []]);
        exit;
    }
    if (!in_array($medicalRecordStatus, $allowedMedicalRecordStatus, true)) {
        http_response_code(422);
        echo json_encode(["response" => ["message" => "medicalRecordStatus tidak valid", "code" => 422], "metadata" => []]);
        exit;
    }

    // --- 16. Validasi tanggal lahir (jika diisi) ---
    if (!empty($birthDate)) {
        $date = DateTime::createFromFormat('Y-m-d', $birthDate);
        if (!$date || $date->format('Y-m-d') !== $birthDate) {
            http_response_code(422);
            echo json_encode(["response" => ["message" => "birthDate harus format YYYY-MM-DD", "code" => 422], "metadata" => []]);
            exit;
        }
    }

    // --- 17. Validasi wilayah (jika ada) ---
    if ($provinceId !== null && $provinceId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT provinceId FROM region_province WHERE provinceId = :id LIMIT 1");
            $stmt->execute([':id' => $provinceId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "provinceId tidak ditemukan", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) { error_log('[CreatePatient] ' . $e->getMessage()); }
    } else {
        $provinceId = null;
    }
    if ($cityId !== null && $cityId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT cityId FROM region_city WHERE cityId = :id AND provinceId = :provinceId LIMIT 1");
            $stmt->execute([':id' => $cityId, ':provinceId' => $provinceId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "cityId tidak ditemukan atau tidak sesuai dengan provinceId", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) { error_log('[CreatePatient] ' . $e->getMessage()); }
    } else {
        $cityId = null;
    }
    if ($districtId !== null && $districtId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT districtId FROM region_district WHERE districtId = :id AND cityId = :cityId LIMIT 1");
            $stmt->execute([':id' => $districtId, ':cityId' => $cityId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "districtId tidak ditemukan atau tidak sesuai dengan cityId", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) { error_log('[CreatePatient] ' . $e->getMessage()); }
    } else {
        $districtId = null;
    }
    if ($villageId !== null && $villageId > 0) {
        try {
            $stmt = $Conn->prepare("SELECT villageId FROM region_village WHERE villageId = :id AND districtId = :districtId LIMIT 1");
            $stmt->execute([':id' => $villageId, ':districtId' => $districtId]);
            if (!$stmt->fetch()) {
                http_response_code(422);
                echo json_encode(["response" => ["message" => "villageId tidak ditemukan atau tidak sesuai dengan districtId", "code" => 422], "metadata" => []]);
                exit;
            }
        } catch (PDOException $e) { error_log('[CreatePatient] ' . $e->getMessage()); }
    } else {
        $villageId = null;
    }

    // --- 18. Jika satuSehatCode kosong, cari berdasarkan NIK di SATUSEHAT ---
    $foundSatuSehatCode = null;
    if (empty($satuSehatCode) && !empty($nik)) {
        try {
            // Ambil credential SATUSEHAT aktif
            $credStmt = $Conn->prepare("SELECT * FROM satusehat WHERE status = 1 LIMIT 1");
            $credStmt->execute();
            $credential = $credStmt->fetch(PDO::FETCH_ASSOC);
            if ($credential) {
                $tokenResult = generateTokenSatusehat($Conn);
                if ($tokenResult['status'] === 'success') {
                    $accessToken = $tokenResult['token'];
                    $baseUrl = rtrim($credential['baseUrl'], '/');

                    // Cari pasien berdasarkan NIK
                    $searchUrl = $baseUrl . '/fhir-r4/v1/Patient?identifier=' . urlencode('https://fhir.kemkes.go.id/id/nik|' . $nik);
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $searchUrl,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => [
                            'Authorization: Bearer ' . $accessToken
                        ],
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_TIMEOUT => 20,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($httpCode === 200) {
                        $result = json_decode($response, true);
                        if (isset($result['total']) && $result['total'] > 0 && isset($result['entry'][0]['resource']['id'])) {
                            $foundSatuSehatCode = $result['entry'][0]['resource']['id'];
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[CreatePatient] SATUSEHAT search error: ' . $e->getMessage());
        }
    }
    if (!empty($foundSatuSehatCode)) {
        $satuSehatCode = $foundSatuSehatCode;
    } else {
        $satuSehatCode = null;
    }

    // --- 19. Proses Upload Foto ---
    $photoFilename = null;
    $uploadDir = __DIR__ . '/../../Storage/Img/Patient/';
    if (!empty($photo)) {
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $photo, $matches)) {
            $imageType = strtolower($matches[1]);
            $base64Data = $matches[2];
            $binaryData = base64_decode($base64Data);
            if ($binaryData !== false) {
                $allowedImageTypes = ['jpeg', 'png', 'webp'];
                if (in_array($imageType, $allowedImageTypes, true)) {
                    $ext = $imageType === 'jpeg' ? 'jpg' : $imageType;
                    $photoFilename = md5(uniqid() . '_' . time()) . '.' . $ext;
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $filePath = $uploadDir . $photoFilename;
                    if (file_put_contents($filePath, $binaryData) === false) {
                        $photoFilename = null; // gagal simpan
                    }
                }
            }
        }
    }

    // --- 20. Insert Data ke Database (dengan creatBy, creatAt, updateBy, updateAt) ---
    try {
        $createdDate = $nowUtc;

        $sql = "INSERT INTO patient (
                    noMedicalRecord,
                    satuSehatCode,
                    isInfant,
                    name,
                    email,
                    phone,
                    gender,
                    birthPlace,
                    birthDate,
                    nik,
                    religion,
                    martialStatus,
                    lastEducation,
                    occupation,
                    language,
                    ethnic,
                    citizenshipStatus,
                    provinceId,
                    cityId,
                    districtId,
                    villageId,
                    rt,
                    rw,
                    postalCode,
                    address,
                    medicalRecordStatus,
                    oldMedicalRecord,
                    motherMedicalRecord,
                    kkNumber,
                    kkName,
                    photo,
                    creatBy,
                    creatAt,
                    updateBy,
                    updateAt
                ) VALUES (
                    :noMedicalRecord,
                    :satuSehatCode,
                    :isInfant,
                    :name,
                    :email,
                    :phone,
                    :gender,
                    :birthPlace,
                    :birthDate,
                    :nik,
                    :religion,
                    :martialStatus,
                    :lastEducation,
                    :occupation,
                    :language,
                    :ethnic,
                    :citizenshipStatus,
                    :provinceId,
                    :cityId,
                    :districtId,
                    :villageId,
                    :rt,
                    :rw,
                    :postalCode,
                    :address,
                    :medicalRecordStatus,
                    :oldMedicalRecord,
                    :motherMedicalRecord,
                    :kkNumber,
                    :kkName,
                    :photo,
                    :creatBy,
                    :creatAt,
                    :updateBy,
                    :updateAt
                )";

        $stmt = $Conn->prepare($sql);
        $stmt->execute([
            ':noMedicalRecord' => $noMedicalRecord,
            ':satuSehatCode' => $satuSehatCode,
            ':isInfant' => $isInfant,
            ':name' => $name,
            ':email' => empty($email) ? null : $email,
            ':phone' => empty($phone) ? null : $phone,
            ':gender' => $gender,
            ':birthPlace' => $birthPlace,
            ':birthDate' => $birthDate,
            ':nik' => empty($nik) ? null : $nik,
            ':religion' => $religion,
            ':martialStatus' => $martialStatus,
            ':lastEducation' => $lastEducation,
            ':occupation' => $occupation,
            ':language' => $language,
            ':ethnic' => $ethnic,
            ':citizenshipStatus' => $citizenshipStatus,
            ':provinceId' => $provinceId,
            ':cityId' => $cityId,
            ':districtId' => $districtId,
            ':villageId' => $villageId,
            ':rt' => $rt,
            ':rw' => $rw,
            ':postalCode' => $postalCode,
            ':address' => $address,
            ':medicalRecordStatus' => $medicalRecordStatus,
            ':oldMedicalRecord' => empty($oldMedicalRecord) ? null : $oldMedicalRecord,
            ':motherMedicalRecord' => empty($motherMedicalRecord) ? null : $motherMedicalRecord,
            ':kkNumber' => empty($kkNumber) ? null : $kkNumber,
            ':kkName' => empty($kkName) ? null : $kkName,
            ':photo' => $photoFilename,
            ':creatBy' => $loggedInAccountId,
            ':creatAt' => $createdDate,
            ':updateBy' => $loggedInAccountId,
            ':updateAt' => $createdDate
        ]);

        $newId = (int) $Conn->lastInsertId();

        // --- 21. Response Sukses ---
        http_response_code(201);
        echo json_encode([
            "response" => [
                "message" => "Pasien berhasil ditambahkan",
                "code" => 201
            ],
            "metadata" => [
                "patientId" => $newId,
                "created_at" => $createdDate . ' GMT'
            ],
            "data" => [
                "patientId" => $newId,
                "noMedicalRecord" => $noMedicalRecord,
                "satuSehatCode" => $satuSehatCode,
                "isInfant" => $isInfant,
                "name" => $name,
                "email" => empty($email) ? null : $email,
                "phone" => empty($phone) ? null : $phone,
                "gender" => $gender,
                "birthPlace" => $birthPlace,
                "birthDate" => $birthDate,
                "nik" => empty($nik) ? null : $nik,
                "religion" => $religion,
                "martialStatus" => $martialStatus,
                "lastEducation" => $lastEducation,
                "occupation" => $occupation,
                "language" => $language,
                "ethnic" => $ethnic,
                "citizenshipStatus" => $citizenshipStatus,
                "provinceId" => $provinceId,
                "cityId" => $cityId,
                "districtId" => $districtId,
                "villageId" => $villageId,
                "rt" => $rt,
                "rw" => $rw,
                "postalCode" => $postalCode,
                "address" => $address,
                "medicalRecordStatus" => $medicalRecordStatus,
                "oldMedicalRecord" => empty($oldMedicalRecord) ? null : $oldMedicalRecord,
                "motherMedicalRecord" => empty($motherMedicalRecord) ? null : $motherMedicalRecord,
                "kkNumber" => empty($kkNumber) ? null : $kkNumber,
                "kkName" => empty($kkName) ? null : $kkName,
                "photo" => $photoFilename,
                "creatBy" => $loggedInAccountId,
                "creatAt" => $createdDate,
                "updateBy" => $loggedInAccountId,
                "updateAt" => $createdDate
            ]
        ]);

    } catch (PDOException $e) {
        error_log('[CreatePatient] Insert error: ' . $e->getMessage());
        // Hapus foto jika gagal
        if ($photoFilename && file_exists($uploadDir . $photoFilename)) {
            unlink($uploadDir . $photoFilename);
        }
        http_response_code(500);
        echo json_encode(["response" => ["message" => "Gagal menyimpan data: " . $e->getMessage(), "code" => 500], "metadata" => []]);
        exit;
    }
?>