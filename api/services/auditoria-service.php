<?php
class AuditoriaService {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    private function obterIPv4() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        
        // Converter localhost IPv6 para IPv4
        if ($ip === '::1') {
            return '127.0.0.1';
        }
        
        // Se já é IPv4, retornar
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
        
        // Tentar obter IPv4 de headers de proxy
        $headers = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED'];
        
        foreach ($headers as $header) {
            if (!isset($_SERVER[$header])) continue;
            
            $ips = explode(',', $_SERVER[$header]);
            foreach ($ips as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $candidate;
                }
            }
        }
        
        return $ip;
    }
    
    public function registrar($usuario_id, $acao, $tabela, $registro_id = null, $dados_anteriores = null, $dados_novos = null) {
        try {
            $ip = $this->obterIPv4();
            $hostname = $ip ? gethostbyaddr($ip) : null;
            if ($hostname === $ip) $hostname = null;
            
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
            $hostname = $ip ? gethostbyaddr($ip) : null;
            if ($hostname === $ip) $hostname = null;
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
            $hostname = $ip ? gethostbyaddr($ip) : null;
            if ($hostname === $ip) $hostname = null;
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
?>
