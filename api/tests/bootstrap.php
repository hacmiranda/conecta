<?php
/**
 * Bootstrap dos testes de unidade. Carrega só o que os services testados precisam —
 * nada aqui abre conexão de banco (os testes de integração com o banco ficam no
 * script de matriz de acesso, rodado contra o servidor local de verdade).
 */
error_reporting(E_ALL);

require_once __DIR__ . '/../middleware/auth.php';     // AuthMiddleware::mesmoSetor (usado por AcessoService)
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/senha-service.php';
require_once __DIR__ . '/../services/sla-service.php';
require_once __DIR__ . '/../services/email-cabecalho.php';
require_once __DIR__ . '/../services/email-historico.php';
