<?php
require_once __DIR__ . '/environment.php';

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    /** Conexão única por requisição (antes cada new Database() abria outra: o middleware e o controller já eram duas). */
    private static ?PDO $compartilhada = null;

    public function __construct() {
        $this->host = Environment::get('db_host');
        $this->db_name = Environment::get('db_name');
        $this->username = Environment::get('db_user');
        $this->password = Environment::get('db_pass');
    }

    /** Conexão compartilhada por toda a requisição (os services recebem esta mesma). */
    public function getConnection() {
        if (self::$compartilhada === null) {
            self::$compartilhada = $this->conectar();
        }
        $this->conn = self::$compartilhada;
        return $this->conn;
    }

    /**
     * Conexão separada, para o que precisa ser gravado mesmo se a transação da requisição for
     * desfeita (ex.: limite de tentativas de login).
     */
    public function getConnectionPropria(): PDO {
        return $this->conectar();
    }

    private function conectar(): PDO {
        try {
            $conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            // Mesmo fuso do PHP (America/Sao_Paulo, sem horário de verão): NOW() do MySQL e date() do PHP
            // passam a concordar, o que importa para SLA, lembretes e registros de data/hora.
            $conn->exec("SET time_zone = '-03:00'");
            return $conn;
        } catch (PDOException $e) {
            error_log("Erro de conexão com banco: " . $e->getMessage());
            throw $e;
        }
    }
}
