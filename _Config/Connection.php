<?php
    // Koneksi Database

    class Database {
        private $host     = 'localhost';
        private $username = 'root';
        private $password = 'arunaparasilvanursari';
        private $database = 'rzf_medis';
        private $port     = 3306;                     // Port default MySQL
        private $charset  = 'utf8mb4';
        
        protected $connection;
        
        public function __construct() {
            $this->connect();
        }
        
        private function connect() {
            if (!isset($this->connection)) {
                try {
                    $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset={$this->charset}";
                    
                    $options = [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ];
                    
                    $this->connection = new PDO($dsn, $this->username, $this->password, $options);
                    
                } catch (PDOException $e) {
                    die("Koneksi database gagal: " . $e->getMessage());
                }
            }
            
            return $this->connection;
        }
        
        public function getConnection() {
            return $this->connection;
        }
        
        public function closeConnection() {
            $this->connection = null;
        }
    }

    // Cara penggunaan:
    $db = new Database();
    $Conn = $db->getConnection();
?>