<?php
    // Response Header
    header('Content-Type: application/json');
    header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 60)));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
    header("Access-Control-Allow-Methods: POST");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, x-token, token");

    // Timezone
    date_default_timezone_set('UTC');

    // Helper
    include "../../_Config/Connection.php";
    include "../../_Config/Helper.php";

    // Ratelimit
    require "../../_Config/RateLimiter.php";

    $Limiter = new RateLimiter($Conn);

    // Maksimal 5 request setiap 60 detik
    $Limiter->check("get_token",5,60);

    // Validasi Method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            "response"=>[
                "message"=>"Metode request tidak diizinkan",
                "code"=>405
            ],
            "metadata"=>[]
        ]);
        exit;
    }

    // Ambil Body JSON
    $raw     = file_get_contents("php://input");
    $Tangkap = json_decode($raw,true);

    if(!is_array($Tangkap)){
        http_response_code(400);
        echo json_encode([
            "response"=>[
                "message"=>"Format JSON tidak valid",
                "code"=>400
            ],
            "metadata"=>[]
        ]);
        exit;
    }

    // Validasi Mandatory
    if(empty($Tangkap['client_id'])){
        http_response_code(422);
        echo json_encode([
            "response"=>[
                "message"=>"Client ID Tidak Boleh Kosong",
                "code"=>422
            ],
            "metadata"=>[]
        ]);
        exit;
    }

    if(empty($Tangkap['client_key'])){
        http_response_code(422);
        echo json_encode([
            "response"=>[
                "message"=>"Client Key Tidak Boleh Kosong",
                "code"=>422
            ],
            "metadata"=>[]
        ]);
        exit;
    }

    // Sanitasi
    $client_id  = validateAndSanitizeInput($Tangkap['client_id']);
    $client_key = validateAndSanitizeInput($Tangkap['client_key']);

    try{

        // Cari API Key dari 'api_key'
        $stmt = $Conn->prepare("
            SELECT *
            FROM api_key
            WHERE client_id = :client_id
            LIMIT 1
        ");
        $stmt->execute([
            ':client_id'=>$client_id
        ]);
        $ApiKey = $stmt->fetch();
        if(!$ApiKey){
            http_response_code(401);
            echo json_encode([
                "response"=>[
                    "message"=>"Client ID atau Client Key tidak valid",
                    "code"=>401
                ],
                "metadata"=>[]
            ]);
            exit;
        }

        // Verifikasi Password
        if(!password_verify($client_key,$ApiKey['client_key'])){
            http_response_code(401);
            echo json_encode([
                "response"=>[
                    "message"=>"Client ID atau Client Key tidak valid",
                    "code"=>401
                ],
                "metadata"=>[]
            ]);
            exit;
        }

        // Generate Token
        $token = bin2hex(random_bytes(32));
        $datetime_creat = gmdate("Y-m-d H:i:s");
        $datetime_expired = gmdate(
            "Y-m-d H:i:s T",
            time()+($ApiKey['expired_duration']*3600)
        );

        // Transaction
        $Conn->beginTransaction();

        //hapus token lama
        $stmt=$Conn->prepare("
            DELETE
            FROM api_token
            WHERE id_api_key=:id_api_key
        ");

        $stmt->execute([
            ':id_api_key'=>$ApiKey['id_api_key']
        ]);

        //insert token baru
        $stmt=$Conn->prepare("
            INSERT INTO api_token
            (
                id_api_key,
                token,
                datetime_creat,
                datetime_expired
            )
            VALUES
            (
                :id_api_key,
                :token,
                :datetime_creat,
                :datetime_expired
            )
        ");

        $stmt->execute([
            ':id_api_key'=>$ApiKey['id_api_key'],
            ':token'=>$token,
            ':datetime_creat'=>$datetime_creat,
            ':datetime_expired'=>$datetime_expired
        ]);

        $Conn->commit();

        // Response 200
        http_response_code(200);

        echo json_encode([
            "response"=>[
                "message"=>"Token berhasil dibuat",
                "code"=>200
            ],
            "metadata"=>[
                "id_api_key" => $ApiKey['id_api_key'],
                "client_id"  => $ApiKey['client_id'],
                "api_name"   => $ApiKey['api_name'],
                "token"      => $token,
                "expired_at" => $datetime_expired
            ]
        ]);

    }catch(Exception $e){
        if($Conn->inTransaction()){
            $Conn->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            "response"=>[
                "message"=>"Internal Server Error",
                "code"=>500
            ],
            "metadata"=>[]
        ]);
    }