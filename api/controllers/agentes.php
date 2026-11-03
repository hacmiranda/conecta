<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';

AuthMiddleware::configurarCORS(['GET']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

// GET - Listar agentes (funcionários internos: operador/gestor/admin)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = AuthMiddleware::verificarAuth();

    try {
        // Setor consultado: visão total escolhe (?setor_id) ou vê todos; coordenador escolhe entre os seus;
        // os demais só veem a equipe do próprio setor
        $setorEquipe = AcessoService::setorEscopo($user);
        if ($setorEquipe !== null) {
            // Equipe do setor: quem tem o setor como principal e o coordenador que o recebeu como adicional
            $query = "SELECT id, nome, email, setor_id FROM usuarios
                      WHERE tipo IN ('coordenador', 'gestor', 'operador') AND ativo = 1
                        AND (setor_id = :setor_id OR id IN (SELECT usuario_id FROM usuario_setores WHERE setor_id = :setor_id2))
                      ORDER BY nome";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':setor_id', $setorEquipe, PDO::PARAM_INT);
            $stmt->bindValue(':setor_id2', $setorEquipe, PDO::PARAM_INT);
            $stmt->execute();
        } elseif (AcessoService::ehVisaoTotal($user)) {
            $query = "SELECT id, nome, email, setor_id FROM usuarios WHERE tipo IN ('admin', 'coordenador', 'gestor', 'operador') AND ativo = 1 ORDER BY nome";
            $stmt = $db->prepare($query);
            $stmt->execute();
        } else {
            echo json_encode([]); // sem setor: não há equipe a mostrar
            exit;
        }
        echo json_encode($stmt->fetchAll());
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar agentes']);
    }
}
