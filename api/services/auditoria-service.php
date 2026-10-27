<?php
require_once __DIR__ . '/rede-service.php';

class AuditoriaService {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * IP do cliente: o da conexão, ou o repassado por um proxy confiável (RedeService). Cabeçalhos como
     * X-Forwarded-For enviados por qualquer cliente não valem (antes, quem quisesse escolhia o IP gravado).
     */
    private function obterIPv4() {
        $ip = RedeService::ipCliente();
        return $ip === '::1' ? '127.0.0.1' : $ip;
    }

    public function registrar($usuario_id, $acao, $tabela, $registro_id = null, $dados_anteriores = null, $dados_novos = null) {
        try {
            $ip = $this->obterIPv4();
            $hostname = null; // sem DNS reverso: atrasava cada registro e o nome é controlado por quem tem o IP
            
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $sessao_id = session_id() ?: null;
            
            $query = "INSERT INTO auditoria (usuario_id, acao, tabela, registro_id, dados_anteriores, dados_novos, ip_address, hostname, user_agent, sessao_id)
                      VALUES (:usuario_id, :acao, :tabela, :registro_id, :dados_anteriores, :dados_novos, :ip_address, :hostname, :user_agent, :sessao_id)";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':usuario_id', $usuario_id);
            $stmt->bindParam(':acao', $acao);
            $stmt->bindParam(':tabela', $tabela);
            $stmt->bindParam(':registro_id', $registro_id);
            $stmt->bindParam(':dados_anteriores', $dados_anteriores);
            $stmt->bindParam(':dados_novos', $dados_novos);
            $stmt->bindParam(':ip_address', $ip);
            $stmt->bindParam(':hostname', $hostname);
            $stmt->bindParam(':user_agent', $user_agent);
            $stmt->bindParam(':sessao_id', $sessao_id);
            
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Erro ao registrar auditoria: " . $e->getMessage());
            return false;
        }
    }
    
    public function registrarTicket($ticket_id, $usuario_id, $campo, $valor_anterior, $valor_novo) {
        try {
            $ip = $this->obterIPv4();
            $hostname = null; // sem DNS reverso: atrasava cada registro e o nome é controlado por quem tem o IP
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $query = "INSERT INTO auditoria_tickets (ticket_id, usuario_id, campo, valor_anterior, valor_novo, ip_address, hostname, user_agent)
                      VALUES (:ticket_id, :usuario_id, :campo, :valor_anterior, :valor_novo, :ip_address, :hostname, :user_agent)";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->bindParam(':usuario_id', $usuario_id);
            $stmt->bindParam(':campo', $campo);
            $stmt->bindParam(':valor_anterior', $valor_anterior);
            $stmt->bindParam(':valor_novo', $valor_novo);
            $stmt->bindParam(':ip_address', $ip);
            $stmt->bindParam(':hostname', $hostname);
            $stmt->bindParam(':user_agent', $user_agent);
            
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Erro ao registrar auditoria de ticket: " . $e->getMessage());
            return false;
        }
    }
    
    public function registrarLogin($usuario_id, $email, $acao) {
        try {
            $ip = $this->obterIPv4();
            $hostname = null; // sem DNS reverso: atrasava cada registro e o nome é controlado por quem tem o IP
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $sessao_id = session_id() ?: null;
            
            $query = "INSERT INTO auditoria_login (usuario_id, email, acao, ip_address, hostname, user_agent, sessao_id)
                      VALUES (:usuario_id, :email, :acao, :ip_address, :hostname, :user_agent, :sessao_id)";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':usuario_id', $usuario_id);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':acao', $acao);
            $stmt->bindParam(':ip_address', $ip);
            $stmt->bindParam(':hostname', $hostname);
            $stmt->bindParam(':user_agent', $user_agent);
            $stmt->bindParam(':sessao_id', $sessao_id);
            
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Erro ao registrar auditoria de login: " . $e->getMessage());
            return false;
        }
    }
}
