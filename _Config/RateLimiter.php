<?php
    class RateLimiter {
        private PDO $Conn;

        public function __construct(PDO $Conn)
        {
            $this->Conn = $Conn;
        }

        /**
         * Membatasi jumlah request
         *
         * @param string $endpoint Nama endpoint (misal: get_token)
         * @param int    $maxHit   Maksimal request
         * @param int    $window   Window dalam detik
         */
        public function check(string $endpoint, int $maxHit = 5, int $window = 60): void
        {
            $ip = $this->getClientIP();

            // waktu dibulatkan sesuai window
            $requestTime = floor(time() / $window) * $window;

            // Tambah hit
            $sql = "
                INSERT INTO rate_limit
                (
                    ip_address,
                    endpoint,
                    request_time,
                    hit_count
                )
                VALUES
                (
                    :ip,
                    :endpoint,
                    :request_time,
                    1
                )
                ON DUPLICATE KEY UPDATE
                    hit_count = hit_count + 1
            ";

            $stmt = $this->Conn->prepare($sql);

            $stmt->execute([
                ':ip' => $ip,
                ':endpoint' => $endpoint,
                ':request_time' => $requestTime
            ]);

            // Ambil jumlah hit
            $stmt = $this->Conn->prepare("
                SELECT hit_count
                FROM rate_limit
                WHERE
                    ip_address = :ip
                    AND endpoint = :endpoint
                    AND request_time = :request_time
                LIMIT 1
            ");

            $stmt->execute([
                ':ip' => $ip,
                ':endpoint' => $endpoint,
                ':request_time' => $requestTime
            ]);

            $row = $stmt->fetch();

            if ($row && $row['hit_count'] > $maxHit) {

                http_response_code(429);

                echo json_encode([
                    "response" => [
                        "message" => "Terlalu banyak permintaan. Silakan coba beberapa saat lagi.",
                        "code" => 429
                    ],
                    "metadata" => [
                        "retry_after" => $window
                    ]
                ]);

                exit;
            }

            // Bersihkan data lama (1% kemungkinan)
            if (random_int(1, 100) === 1) {

                $stmt = $this->Conn->prepare("
                    DELETE
                    FROM rate_limit
                    WHERE request_time < :expired
                ");

                $stmt->execute([
                    ':expired' => time() - ($window * 10)
                ]);
            }
        }

        /**
         * Mendapatkan IP Client
         */
        private function getClientIP(): string
        {
            $headers = [
                'HTTP_CF_CONNECTING_IP',
                'HTTP_X_REAL_IP',
                'HTTP_X_FORWARDED_FOR',
                'REMOTE_ADDR'
            ];

            foreach ($headers as $header) {

                if (!empty($_SERVER[$header])) {

                    $ip = explode(',', $_SERVER[$header])[0];
                    $ip = trim($ip);

                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }

            return '0.0.0.0';
        }
    }